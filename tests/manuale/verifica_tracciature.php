<?php

/**
 * @file
 * Verifica funzionale delle tracciature tecniche di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_tracciature.php
 *
 * Copre il §2 dell'allegato tecnico — ricostruibilità e verificabilità ex
 * post del procedimento deliberativo — e il §5, su monitoraggio,
 * registrazione degli accessi e disponibilità di sistemi di audit.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\EventoAudit;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\psiphos\Exception\VotoNonAmmessoException;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EsitiTracciature {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }
}

$gestoreEntita = \Drupal::entityTypeManager();
$database = \Drupal::database();
$urna = \Drupal::service('psiphos.urna');
$aula = \Drupal::service('psiphos.aula');
$scrutinio = \Drupal::service('psiphos.scrutinio');
$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');
$registro = \Drupal::service('psiphos.registro_audit');
$commutatore = \Drupal::service('account_switcher');
$pilaRichieste = \Drupal::service('request_stack');

$usaSessione = static function (string $identificativo) use ($pilaRichieste): void {
  $richiesta = Request::create('/psiphos');
  $sessione = new Session(new MockArraySessionStorage());
  $sessione->setId($identificativo);
  $richiesta->setSession($sessione);
  $pilaRichieste->push($richiesta);
};

/** Rimuove i soli dati creati da questa verifica. */
$ripulisci = static fn () => ProvaPsiphos::ripulisci();
ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
$ripulisci();

$utenti = [];
for ($indice = 1; $indice <= 4; $indice++) {
  $utente = User::create([
    'name' => "psiphos_prova_$indice",
    'mail' => "psiphos_prova_$indice@example.test",
    'status' => 1,
  ]);
  $utente->save();
  $utenti[] = $utente;
}
$commutatore->switchTo($utenti[0]);
$usaSessione('sessione-presidente');

echo "\n[1] Il procedimento lascia traccia\n";
$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio tracciato'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$seduta->save();
$identificativo = (int) $seduta->id();
EsitiTracciature::verifica('la convocazione è tracciata', count($registro->tracciature($identificativo)) === 1);

foreach ($utenti as $utente) {
  Presenza::create(['seduta' => $identificativo, 'utente' => $utente->id()])->save();
}
$seduta->transitaA(StatoSeduta::APERTA)->save();

foreach ($utenti as $posizione => $utente) {
  $usaSessione('sessione-' . $posizione);
  $aula->entra($seduta, $utente);
}
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($identificativo);

$punto = PuntoOdg::create(['seduta' => $identificativo, 'numero' => 1, 'oggetto' => 'Punto tracciato']);
$punto->save();
$delibera = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$delibera->save();
$delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();

foreach ([0, 1, 2] as $posizione) {
  $usaSessione('sessione-' . $posizione);
  $commutatore->switchTo($utenti[$posizione]);
  $urna->deposita($delibera, $utenti[$posizione], [SchemaScheda::VOCE_FAVOREVOLE]);
}

// Un secondo voto viene respinto: anche il rifiuto va tracciato.
try { $urna->deposita($delibera, $utenti[2], [SchemaScheda::VOCE_CONTRARIO]); }
catch (VotoNonAmmessoException) {}

$commutatore->switchTo($utenti[0]);
$usaSessione('sessione-presidente');
$delibera->transitaA(StatoDelibera::SOSPESA, 'Verifica del collegamento.')->save();
$delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$scrutinio->chiudiEScrutina($delibera);
// L'atto va redatto prima del sigillo: l'estratto di delibera si produce
// insieme al verbale.
$delibera->set('numero_delibera', '1')
  ->set('dispositivo', ['value' => 'Approva la proposta.', 'format' => 'plain_text'])
  ->save();
$seduta->transitaA(StatoSeduta::CHIUSA)->save();

$tracciature = $registro->tracciature($identificativo);
$eventi = array_column($tracciature, 'evento');

$attesi = [
  EventoAudit::SEDUTA_CONVOCATA,
  EventoAudit::SEDUTA_APERTA,
  EventoAudit::AULA_INGRESSO,
  EventoAudit::DELIBERA_PREDISPOSTA,
  EventoAudit::DELIBERA_APERTA,
  EventoAudit::VOTO_DEPOSITATO,
  EventoAudit::VOTO_RIFIUTATO,
  EventoAudit::DELIBERA_SOSPESA,
  EventoAudit::DELIBERA_CHIUSA,
  EventoAudit::SEDUTA_CHIUSA,
];
foreach ($attesi as $evento) {
  EsitiTracciature::verifica(
    sprintf('tracciato: %s', $evento->value),
    in_array($evento->value, $eventi, TRUE)
  );
}
EsitiTracciature::verifica('quattro ingressi in aula', count(array_keys($eventi, EventoAudit::AULA_INGRESSO->value, TRUE)) === 4);
EsitiTracciature::verifica('tre schede depositate', count(array_keys($eventi, EventoAudit::VOTO_DEPOSITATO->value, TRUE)) === 3);

echo "\n[2] Le tracciature non rivelano il voto\n";
$serializzate = (string) json_encode($tracciature, JSON_UNESCAPED_UNICODE);
EsitiTracciature::verifica(
  'nessuna annotazione contiene una voce di scheda',
  !str_contains($serializzate, SchemaScheda::VOCE_FAVOREVOLE) && !str_contains($serializzate, SchemaScheda::VOCE_CONTRARIO)
);
$deposito = array_values(array_filter($tracciature, static fn (array $t): bool => $t['evento'] === EventoAudit::VOTO_DEPOSITATO->value))[0];
EsitiTracciature::verifica('il deposito registra chi ha votato', isset($deposito['contesto']['votante']));
EsitiTracciature::verifica('e non che cosa ha votato', !array_key_exists('voci', $deposito['contesto']));
$rifiuto = array_values(array_filter($tracciature, static fn (array $t): bool => $t['evento'] === EventoAudit::VOTO_RIFIUTATO->value))[0];
EsitiTracciature::verifica('il rifiuto ne registra il motivo', str_contains($rifiuto['contesto']['motivo'], 'già espresso'));
EsitiTracciature::verifica('e non la scheda respinta', !array_key_exists('voci', $rifiuto['contesto']));

echo "\n[3] Contenuto delle annotazioni\n";
$sospensione = array_values(array_filter($tracciature, static fn (array $t): bool => $t['evento'] === EventoAudit::DELIBERA_SOSPESA->value))[0];
EsitiTracciature::verifica('la sospensione riporta la motivazione', str_contains($sospensione['contesto']['motivazione'], 'collegamento'));
$chiusura = array_values(array_filter($tracciature, static fn (array $t): bool => $t['evento'] === EventoAudit::DELIBERA_CHIUSA->value))[0];
EsitiTracciature::verifica('la chiusura riporta esito e sigillo', $chiusura['contesto']['esito'] === 'approvata' && strlen($chiusura['contesto']['sigillo_urna']) === 64);
EsitiTracciature::verifica('sospensione e rifiuto sono segnalati come anomalie', $sospensione['anomalia'] && $rifiuto['anomalia']);

echo "\n[4] Catena delle tracciature\n";
$catena = $registro->verificaCatena($identificativo);
EsitiTracciature::verifica('catena integra', $catena['integra']);
EsitiTracciature::verifica('tutte le tracciature verificate', $catena['tracciature'] === count($tracciature));

// Seconda seduta, per accertare l'indipendenza delle catene.
$altraSeduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Consiglio di classe tracciato'),
  'organo' => TipoOrgano::CONSIGLIO_CLASSE->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$altraSeduta->save();
EsitiTracciature::verifica('la seconda catena parte da zero', $registro->verificaCatena((int) $altraSeduta->id())['tracciature'] === 1);

echo "\n[5] Manomissione\n";
$daAlterare = $tracciature[3]['id'];
$database->update('psiphos_audit')->fields(['contesto' => '{"alterato":true}'])->condition('id', $daAlterare)->execute();
$catenaAlterata = $registro->verificaCatena($identificativo);
EsitiTracciature::verifica("un'annotazione alterata rompe la catena", !$catenaAlterata['integra']);
EsitiTracciature::verifica('la rottura è individuata sulla riga giusta', $catenaAlterata['prima_rottura'] === $daAlterare);
EsitiTracciature::verifica('il motivo distingue l\'alterazione', str_contains($catenaAlterata['motivo'], 'alterata'));
EsitiTracciature::verifica(
  "la catena dell'altra seduta resta integra",
  $registro->verificaCatena((int) $altraSeduta->id())['integra']
);

// Ripristino e prova della rimozione.
$originale = $tracciature[3];
$database->update('psiphos_audit')
  ->fields(['contesto' => (string) json_encode($originale['contesto'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])
  ->condition('id', $daAlterare)
  ->execute();
EsitiTracciature::verifica('ripristinato il contenuto, la catena torna integra', $registro->verificaCatena($identificativo)['integra']);

$database->delete('psiphos_audit')->condition('id', $tracciature[2]['id'])->execute();
$catenaMutila = $registro->verificaCatena($identificativo);
EsitiTracciature::verifica("un'annotazione rimossa rompe la catena", !$catenaMutila['integra']);
EsitiTracciature::verifica('il motivo distingue la rimozione', str_contains($catenaMutila['motivo'], 'rimosse o riordinate'));

echo "\n[6] Diagnostica di sicurezza (§5, §9)\n";
require_once DRUPAL_ROOT . '/core/includes/install.inc';
$diagnostica = \Drupal::moduleHandler()->invoke('psiphos', 'requirements', ['runtime']);
EsitiTracciature::verifica('la diagnostica copre cinque presidi', count($diagnostica) === 5);
EsitiTracciature::verifica(
  'e segnala come errore la catena compromessa',
  ($diagnostica['psiphos_tracciature']['severity'] ?? 0) === REQUIREMENT_ERROR
);
EsitiTracciature::verifica(
  'indicando quale seduta',
  str_contains((string) $diagnostica['psiphos_tracciature']['description'], (string) $identificativo)
);

echo "\n[7] Conservazione delle tracciature (§6)\n";
$verbale = $verbalizzazione->perSeduta($seduta);
$verbalizzazione->sigilla($verbale, $utenti[1]);
$gestoreEntita->getStorage('psiphos_seduta')->resetCache([$identificativo]);
$prima = count($registro->tracciature($identificativo));
EsitiTracciature::verifica('la sigillatura del verbale è tracciata', $prima > count($tracciature));

EsitiTracciature::verifica('nulla da rimuovere su una seduta recente', $registro->applicaRitenzione() === 0);

// Si retrodata la chiusura oltre i termini di conservazione.
$giorni = (int) \Drupal::config('psiphos.settings')->get('audit.ritenzione_giorni');
$database->update('psiphos_seduta')
  ->fields(['chiusa_il' => \Drupal::time()->getRequestTime() - (($giorni + 1) * 86400)])
  ->condition('id', $identificativo)
  ->execute();
EsitiTracciature::verifica('la seduta scaduta viene ripulita', $registro->applicaRitenzione() === 1);
$residue = $registro->tracciature($identificativo);
EsitiTracciature::verifica('resta la sola annotazione di troncamento', count($residue) === 1);
EsitiTracciature::verifica(
  'che dichiara quante tracciature sono state rimosse',
  $residue[0]['evento'] === EventoAudit::TRACCIATURE_TRONCATE->value && $residue[0]['contesto']['tracciature_rimosse'] === $prima
);
EsitiTracciature::verifica('e la catena troncata risulta integra', $registro->verificaCatena($identificativo)['integra']);
EsitiTracciature::verifica('una seconda passata non ripulisce di nuovo', $registro->applicaRitenzione() === 0);

printf("\n--- %d superate, %d fallite ---\n", EsitiTracciature::$superate, EsitiTracciature::$fallite);

ProvaPsiphos::ripristinaUtenza();
$ripulisci();
echo "pulizia completata\n";
