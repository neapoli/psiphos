<?php

/**
 * @file
 * Verifica della separazione fra organi nella consultazione dei verbali.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_accesso.php
 *
 * Il caso che conta è il gruppo di lavoro operativo: è costituito per il
 * singolo alunno, il suo verbale riferisce della disabilità di un minore
 * identificato, e un permesso valido per tutti gli organi lo aprirebbe a ogni
 * docente dell'istituto. Il §3.3 dell'allegato tecnico chiede che ciascuno
 * acceda «esclusivamente alle funzionalità strettamente necessarie», e qui la
 * necessità è delimitata dall'appartenenza all'organo.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

final class EsitiAccesso {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }
}

ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
ProvaPsiphos::ripulisci();

$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');

$ruolo = Role::create(['id' => 'psiphos_prova_docente', 'label' => 'Docente di prova Psíphos']);
foreach (['psiphos partecipare seduta', 'psiphos presiedere seduta', 'psiphos verbalizzare', 'psiphos visualizzare verbali'] as $permesso) {
  $ruolo->grantPermission($permesso);
}
$ruolo->save();

$ruoloSegreteria = Role::create(['id' => 'psiphos_prova_lettore', 'label' => 'Segreteria di prova Psíphos']);
$ruoloSegreteria->grantPermission('psiphos visualizzare ogni verbale');
$ruoloSegreteria->save();

$utenti = [];
foreach (['tre_a', 'tre_b', 'presidente', 'segreteria'] as $nome) {
  $utente = User::create([
    'name' => "psiphos_prova_$nome",
    'mail' => "psiphos_prova_$nome@example.test",
    'status' => 1,
    'roles' => [$nome === 'segreteria' ? 'psiphos_prova_lettore' : 'psiphos_prova_docente'],
  ]);
  $utente->save();
  $utenti[$nome] = $utente;
}

/** Costituisce un organo, lo conduce fino al verbale e lo sigilla. */
$organo = static function (string $oggetto, string $tipo, array $componenti) use ($utenti, $verbalizzazione): array {
  $seduta = Seduta::create([
    'titolo' => ProvaPsiphos::titolo($oggetto),
    'organo' => $tipo,
    'data_seduta' => \Drupal::time()->getRequestTime(),
    'presidente' => $utenti['presidente']->id(),
    'segretario' => $utenti['presidente']->id(),
    'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
  ]);
  $seduta->save();
  foreach ([...$componenti, $utenti['presidente']] as $componente) {
    Presenza::create(['seduta' => $seduta->id(), 'utente' => $componente->id()])->save();
  }

  $punto = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 1, 'oggetto' => $oggetto]);
  $punto->save();
  $delibera = Delibera::create([
    'punto_odg' => $punto->id(),
    'quesito' => 'Si approva?',
    'tipo_voto' => TipoVoto::PALESE->value,
    'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
  ]);
  $delibera->save();

  $seduta->set('stato', StatoSeduta::APERTA->value)->save();
  $seduta->set('stato', StatoSeduta::CHIUSA->value)->save();
  $verbale = $verbalizzazione->sigilla($verbalizzazione->perSeduta($seduta), $utenti['presidente']);

  return ['seduta' => $seduta, 'verbale' => $verbale, 'delibera' => $delibera];
};

// Due gruppi di lavoro operativi: stesso tipo di organo, alunni diversi. È il
// caso in cui una regola per tipo di organo, invece che per singola seduta,
// non basterebbe.
$gloA = $organo('GLO alunno della 3A', TipoOrgano::GRUPPO_LAVORO_INCLUSIONE->value, [$utenti['tre_a']]);
$gloB = $organo('GLO alunno della 3B', TipoOrgano::GRUPPO_LAVORO_INCLUSIONE->value, [$utenti['tre_b']]);
$collegio = $organo('Collegio dei docenti', TipoOrgano::COLLEGIO_DOCENTI->value, [$utenti['tre_a'], $utenti['tre_b']]);

echo "\n[1] Ciascuno vede il verbale del proprio gruppo\n";
EsitiAccesso::verifica(
  'il docente della 3A vede il verbale del GLO del proprio alunno',
  $gloA['verbale']->access('view', $utenti['tre_a'])
);
EsitiAccesso::verifica(
  'e il docente della 3B quello del proprio',
  $gloB['verbale']->access('view', $utenti['tre_b'])
);

echo "\n[2] E non quello dell'altro\n";
EsitiAccesso::verifica(
  "il docente della 3A non vede il verbale del GLO dell'alunno della 3B",
  !$gloB['verbale']->access('view', $utenti['tre_a'])
);
EsitiAccesso::verifica(
  'né la seduta che lo ha prodotto',
  !$gloB['seduta']->access('view', $utenti['tre_a'])
);
EsitiAccesso::verifica(
  "né l'estratto della delibera, che ne riporta l'oggetto",
  !$gloB['delibera']->access('view', $utenti['tre_a'])
);
EsitiAccesso::verifica(
  'e viceversa',
  !$gloA['verbale']->access('view', $utenti['tre_b'])
    && !$gloA['seduta']->access('view', $utenti['tre_b'])
);

echo "\n[3] Il Collegio resta di tutti i suoi componenti\n";
EsitiAccesso::verifica(
  'entrambi i docenti vedono il verbale del Collegio',
  $collegio['verbale']->access('view', $utenti['tre_a'])
    && $collegio['verbale']->access('view', $utenti['tre_b'])
);

echo "\n[4] Dirigente e segreteria vedono ogni organo\n";
EsitiAccesso::verifica(
  'la segreteria apre i verbali di entrambi i gruppi',
  $gloA['verbale']->access('view', $utenti['segreteria'])
    && $gloB['verbale']->access('view', $utenti['segreteria'])
);
EsitiAccesso::verifica(
  'e le sedute che li hanno prodotti',
  $gloA['seduta']->access('view', $utenti['segreteria'])
    && $gloB['seduta']->access('view', $utenti['segreteria'])
);

echo "\n[5] Il permesso di base non basta più da solo\n";
// Il difetto corretto: «psiphos visualizzare verbali» era globale, e chi lo
// possedeva apriva qualunque verbale digitandone l'indirizzo.
$estraneo = User::create([
  'name' => 'psiphos_prova_estraneo',
  'mail' => 'psiphos_prova_estraneo@example.test',
  'status' => 1,
  'roles' => ['psiphos_prova_docente'],
]);
$estraneo->save();
EsitiAccesso::verifica(
  "chi ha il permesso ma non è in alcun elenco non apre nulla",
  !$gloA['verbale']->access('view', $estraneo)
    && !$collegio['verbale']->access('view', $estraneo)
);

echo "\n[6] La scheda del verbale segue l'accesso all'entità\n";
$accesso = \Drupal::service('psiphos.accesso_verbale_seduta');
EsitiAccesso::verifica(
  'la scheda si apre a chi appartiene al gruppo',
  $accesso->access($gloA['seduta'], $utenti['tre_a'])->isAllowed()
);
EsitiAccesso::verifica(
  "e resta chiusa a chi non vi appartiene, non solo la pagina",
  !$accesso->access($gloB['seduta'], $utenti['tre_a'])->isAllowed()
);
EsitiAccesso::verifica(
  'mentre la segreteria vi accede',
  $accesso->access($gloB['seduta'], $utenti['segreteria'])->isAllowed()
);

echo "\n[7] Chi verbalizza accede sempre al proprio verbale\n";
EsitiAccesso::verifica(
  'il segretario designato apre la scheda anche del gruppo altrui, perché lo ha verbalizzato',
  $accesso->access($gloB['seduta'], $utenti['presidente'])->isAllowed()
);

echo "\n[8] L'elenco amministrativo\n";
// Il permesso «convocare una seduta» non consentiva nulla: chi lo aveva non
// poteva creare la seduta né vederne l'elenco, e per convocare un Consiglio
// di classe bisognava dare a un coordinatore l'amministrazione dell'intero
// modulo.
$ruoloCoordinatore = Role::create(['id' => 'psiphos_prova_coordinatore', 'label' => 'Coordinatore di prova']);
foreach (['psiphos partecipare seduta', 'psiphos presiedere seduta', 'psiphos convocare seduta'] as $permesso) {
  $ruoloCoordinatore->grantPermission($permesso);
}
$ruoloCoordinatore->save();

$coordinatore = User::create([
  'name' => 'psiphos_prova_coordinatore',
  'mail' => 'psiphos_prova_coordinatore@example.test',
  'status' => 1,
  'roles' => ['psiphos_prova_coordinatore'],
]);
$coordinatore->save();

EsitiAccesso::verifica(
  'chi può convocare può creare la convocazione',
  \Drupal::entityTypeManager()->getAccessControlHandler('psiphos_seduta')
    ->createAccess('psiphos_seduta', $coordinatore)
);

$suo = $organo('Consiglio del coordinatore', TipoOrgano::CONSIGLIO_CLASSE->value, []);
$archivio = \Drupal::entityTypeManager()->getStorage('psiphos_seduta');
$archivio->loadUnchanged($suo['seduta']->id())->set('uid', $coordinatore->id())->save();

/** Rende l'elenco amministrativo come lo vedrebbe l'utente indicato. */
$elenco = static function (User $utente, string $filtri = ''): string {
  $commutatore = \Drupal::service('account_switcher');
  $pila = \Drupal::service('request_stack');
  $pila->push(\Symfony\Component\HttpFoundation\Request::create('/admin/content/psiphos/seduta' . $filtri));
  $commutatore->switchTo($utente);
  try {
    return (string) \Drupal::service('renderer')->renderInIsolation(
      \Drupal::entityTypeManager()->getHandler('psiphos_seduta', 'list_builder')->render()
    );
  }
  finally {
    $commutatore->switchBack();
    $pila->pop();
  }
};

$visto = $elenco($coordinatore);
EsitiAccesso::verifica(
  "e ritrova nell'elenco la seduta che ha convocato",
  str_contains($visto, 'Consiglio del coordinatore')
);
EsitiAccesso::verifica(
  'e non quelle di organi cui non appartiene',
  !str_contains($visto, 'GLO alunno della 3A') && !str_contains($visto, 'GLO alunno della 3B')
);
EsitiAccesso::verifica(
  'la segreteria vede invece ogni seduta convocata',
  str_contains($elenco($utenti['segreteria']), 'GLO alunno della 3A')
    && str_contains($elenco($utenti['segreteria']), 'GLO alunno della 3B')
    && str_contains($elenco($utenti['segreteria']), 'Consiglio del coordinatore')
);
EsitiAccesso::verifica(
  "l'elenco porta la barra dei filtri",
  str_contains($elenco($utenti['segreteria']), 'psiphos-filtri')
);
EsitiAccesso::verifica(
  'e il ritorno alla dashboard, prima dei filtri',
  !\Drupal\Core\Url::fromUserInput('/admin/dashboard')->access(User::load(1))
    || (static function (string $reso): bool {
      $ritorno = strpos($reso, 'Torna alla dashboard');
      $filtri = strpos($reso, 'psiphos-filtri');

      return $ritorno !== FALSE && $filtri !== FALSE && $ritorno < $filtri;
    })($elenco(User::load(1)))
);
EsitiAccesso::verifica(
  'e i filtri restringono davvero',
  str_contains($elenco($utenti['segreteria'], '?oggetto=coordinatore'), 'Consiglio del coordinatore')
    && !str_contains($elenco($utenti['segreteria'], '?oggetto=coordinatore'), 'GLO alunno')
);

$archivio->loadUnchanged($suo['seduta']->id())->delete();
$coordinatore->delete();
$ruoloCoordinatore->delete();

printf("\n--- %d superate, %d fallite ---\n", EsitiAccesso::$superate, EsitiAccesso::$fallite);

ProvaPsiphos::ripulisci();
echo "dati di prova rimossi\n";
