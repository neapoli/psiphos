<?php

/**
 * @file
 * Verifica del blocco «le mie sedute collegiali».
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_blocco.php
 *
 * Il blocco porta l'ingresso in aula sulla scrivania del personale, al posto
 * del collegamento che il Presidente doveva far avere a ciascuno. Ciò che va
 * verificato non è che mostri le sedute, ma che mostri **solo** quelle di chi
 * guarda: un blocco che elenchi sedute altrui è una fuga di informazioni, e
 * un blocco che offra un bottone inutilizzabile insegna a non fidarsene.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

final class EsitiBlocco {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }
}

ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
ProvaPsiphos::ripulisci();

$commutatore = \Drupal::service('account_switcher');
$gestoreBlocchi = \Drupal::service('plugin.manager.block');
$renderizzatore = \Drupal::service('renderer');
$adesso = \Drupal::time()->getRequestTime();

/** Rende il blocco come lo vedrebbe l'utente indicato. */
$blocco = static function (User $utente, array $configurazione = []) use ($commutatore, $gestoreBlocchi, $renderizzatore): string {
  $commutatore->switchTo($utente);
  try {
    $costruzione = $gestoreBlocchi->createInstance('psiphos_sedute', $configurazione)->build();

    return $costruzione === []
      ? ''
      : (string) $renderizzatore->renderInIsolation($costruzione);
  }
  finally {
    $commutatore->switchBack();
  }
};

$ruolo = Role::create(['id' => 'psiphos_prova_docente', 'label' => 'Docente di prova Psíphos']);
foreach (['psiphos partecipare seduta', 'psiphos presiedere seduta', 'psiphos verbalizzare'] as $permesso) {
  $ruolo->grantPermission($permesso);
}
// La dashboard è di un altro modulo: senza il suo permesso il ritorno non si
// stampa, ed è il comportamento voluto — un bottone che porti a una pagina
// negata è peggio di un bottone assente.
if (\Drupal::service('module_handler')->moduleExists('dashboard')) {
  $ruolo->grantPermission('view scrivania dashboard');
}
$ruolo->save();

$ruoloLettore = Role::create(['id' => 'psiphos_prova_lettore', 'label' => 'Lettore di verbali di prova Psíphos']);
$ruoloLettore->grantPermission('psiphos partecipare seduta');
$ruoloLettore->grantPermission('psiphos visualizzare verbali');
$ruoloLettore->save();

$utenti = [];
foreach (['presidente', 'segretario', 'docente', 'estraneo', 'lettore', 'esterno'] as $nome) {
  $utente = User::create([
    'name' => "psiphos_prova_$nome",
    'mail' => "psiphos_prova_$nome@example.test",
    'status' => 1,
    // «estraneo» ha i permessi ma non è in alcun elenco; «esterno» non ha
    // nulla a che fare con le sedute. Sono due negazioni diverse: la prima
    // sull'elenco, la seconda sul permesso.
    'roles' => match ($nome) {
      'lettore' => ['psiphos_prova_lettore'],
      'esterno' => [],
      default => ['psiphos_prova_docente'],
    },
  ]);
  $utente->save();
  $utenti[$nome] = $utente;
}

/** Crea una seduta con i propri aventi diritto. */
$creaSeduta = static function (string $oggetto, array $partecipanti, array $valori = []) use ($utenti): Seduta {
  $seduta = Seduta::create($valori + [
    'titolo' => ProvaPsiphos::titolo($oggetto),
    'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
    'data_seduta' => \Drupal::time()->getRequestTime(),
    'presidente' => $utenti['presidente']->id(),
    'segretario' => $utenti['segretario']->id(),
    'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
  ]);
  $seduta->save();
  foreach ($partecipanti as $partecipante) {
    Presenza::create(['seduta' => $seduta->id(), 'utente' => $partecipante->id()])->save();
  }

  return $seduta;
};

echo "\n[1] Si vede solo ciò a cui si ha titolo\n";
$convocata = $creaSeduta('Collegio convocato', [$utenti['presidente'], $utenti['segretario'], $utenti['docente']], [
  'url_videoconferenza' => 'https://meet.example.test/collegio-di-prova',
]);

$vistaDocente = $blocco($utenti['docente']);
EsitiBlocco::verifica(
  "l'avente diritto vede la propria seduta",
  str_contains($vistaDocente, 'Collegio convocato')
);
EsitiBlocco::verifica(
  'chi non è in elenco non la vede, e il blocco non si stampa affatto',
  $blocco($utenti['estraneo']) === ''
);

echo "\n[2] Seduta convocata: si legge, non si entra\n";
EsitiBlocco::verifica(
  "non si offre di entrare in un'aula che non è aperta",
  !str_contains($vistaDocente, 'Entra in aula')
);
EsitiBlocco::verifica(
  'il titolo porta alla convocazione, che è ciò che c\'è da leggere',
  str_contains($vistaDocente, '/psiphos/seduta/' . $convocata->id())
);
// Sulla riga compatta la data è abbreviata: per esteso occuperebbe metà
// larghezza e spingerebbe stato e collegamenti contro il margine opposto.
EsitiBlocco::verifica(
  'e la data della seduta',
  str_contains($vistaDocente, \Drupal::service('date.formatter')->format($adesso, 'custom', 'l j F Y, H:i'))
);
// Una data, un titolo e la parola «convocata» accostati non dicono a che cosa
// si riferiscano: ogni valore porta la propria etichetta, e ogni voce dichiara
// che cos'è.
EsitiBlocco::verifica(
  'ogni dato porta la propria etichetta',
  str_contains($vistaDocente, '>Oggetto') && str_contains($vistaDocente, '>Organo')
    && str_contains($vistaDocente, '>Stato')
);
EsitiBlocco::verifica(
  "e la voce dichiara di essere la prossima seduta",
  str_contains($vistaDocente, 'Prossima seduta')
);
// Il colore del filetto accompagna lo stato ma non lo comunica: l'aggancio
// deve esserci per il foglio di stile, e l'intestazione a parole deve restare.
EsitiBlocco::verifica(
  'e porta la classe dello stato, cui il colore si aggancia',
  str_contains($vistaDocente, 'psiphos-sedute__voce--convocata')
);

echo "\n[3] Il collegamento video si apre a parte\n";
// Chi lascia l'aula per aprire la videoconferenza esce dalla seduta, e la
// presenza decade: il collegamento deve aprirsi in una scheda nuova.
EsitiBlocco::verifica(
  'il collegamento alla videoconferenza è offerto',
  str_contains($vistaDocente, 'https://meet.example.test/collegio-di-prova')
);
EsitiBlocco::verifica(
  'e si apre in una scheda nuova, senza portarsi dietro la sessione',
  str_contains($vistaDocente, 'target="_blank"') && str_contains($vistaDocente, 'rel="noopener noreferrer"')
);
$senzaVideo = $creaSeduta('Collegio senza video', [$utenti['docente']]);
EsitiBlocco::verifica(
  'dove la videoconferenza non è indicata non compare alcun collegamento',
  substr_count($blocco($utenti['docente']), 'meet.example.test') === 1
);
$senzaVideo->delete();

echo "\n[4] Il ruolo nella seduta è dichiarato\n";
EsitiBlocco::verifica(
  'al Presidente si dice che presiede',
  str_contains($blocco($utenti['presidente']), 'Presiedi questa seduta')
);
EsitiBlocco::verifica(
  'al segretario che verbalizza',
  str_contains($blocco($utenti['segretario']), 'Verbalizzi questa seduta')
);
EsitiBlocco::verifica(
  'e agli altri nulla',
  !str_contains($vistaDocente, 'questa seduta')
);

echo "\n[5] Seduta aperta e votazione in corso\n";
$convocata->set('stato', StatoSeduta::APERTA->value)->save();
$vistaDocente = $blocco($utenti['docente']);
EsitiBlocco::verifica(
  "aperta la seduta, si offre di entrare in aula",
  str_contains($vistaDocente, 'Entra in aula')
    && str_contains($vistaDocente, '/psiphos/seduta/' . $convocata->id() . '/aula')
);
// Le classi dei temi dipingono lo sfondo e lasciano il testo al colore
// ereditato: il bottone deve portare i propri colori, non dipendere da quelli.
EsitiBlocco::verifica(
  'e il bottone porta la propria classe, non quella del tema',
  str_contains($vistaDocente, 'psiphos-sedute__azione--principale')
    && !str_contains($vistaDocente, 'button--primary')
    && !str_contains($vistaDocente, 'class="button"')
);
EsitiBlocco::verifica(
  'senza votazione aperta nulla richiama con urgenza',
  !str_contains($vistaDocente, 'Votazione in corso')
);
EsitiBlocco::verifica(
  "e l'intestazione dichiara che la seduta è in corso",
  str_contains($vistaDocente, 'Seduta in corso')
);

$punto = PuntoOdg::create(['seduta' => $convocata->id(), 'numero' => 1, 'oggetto' => 'Approvazione del PTOF']);
$punto->save();
$delibera = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva il PTOF?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
  'stato' => StatoDelibera::IN_VOTAZIONE->value,
]);
$delibera->save();

EsitiBlocco::verifica(
  'con una votazione aperta il blocco lo segnala',
  str_contains($blocco($utenti['docente']), 'Votazione in corso')
);

echo "\n[6] Ordine: prima ciò su cui si deve agire\n";
$vecchia = $creaSeduta('Collegio di ieri', [$utenti['docente']], [
  'data_seduta' => $adesso + 86400,
]);
$vistaDocente = $blocco($utenti['docente']);
EsitiBlocco::verifica(
  'la seduta aperta precede le altre, anche se meno recente',
  strpos($vistaDocente, 'Collegio convocato') < strpos($vistaDocente, 'Collegio di ieri')
);
$vecchia->delete();

echo "\n[7] Sedute che non devono comparire\n";
$annullata = $creaSeduta('Collegio annullato', [$utenti['docente']], ['stato' => StatoSeduta::ANNULLATA->value]);
EsitiBlocco::verifica(
  'una seduta annullata non compare',
  !str_contains($blocco($utenti['docente']), 'Collegio annullato')
);
$annullata->delete();

$conclusa = $creaSeduta('Collegio concluso', [$utenti['docente'], $utenti['lettore']]);
$conclusa->set('stato', StatoSeduta::APERTA->value)->save();
$conclusa->set('stato', StatoSeduta::CHIUSA->value)->set('chiusa_il', $adesso - 5 * 86400)->save();

EsitiBlocco::verifica(
  'una seduta conclusa da pochi giorni resta in vista',
  str_contains($blocco($utenti['docente']), 'Collegio concluso')
);
EsitiBlocco::verifica(
  "e si distingue nel peso da quella su cui si deve agire",
  str_contains($blocco($utenti['docente']), 'psiphos-sedute__voce--conclusa')
);
EsitiBlocco::verifica(
  'e non porta alcun bottone, perché non resta nulla da fare',
  (static function (string $reso): bool {
    $inizio = strrpos($reso, 'psiphos-sedute__voce--conclusa');
    if ($inizio === FALSE) {
      return FALSE;
    }
    // Solo dentro la scheda: dopo l'elenco c'è il bottone dell'archivio, che
    // è del blocco e non della singola seduta.
    $fine = strpos($reso, '</li>', $inizio);
    $scheda = $fine === FALSE ? substr($reso, $inizio) : substr($reso, $inizio, $fine - $inizio);

    return !str_contains($scheda, 'psiphos-sedute__azione');
  })($blocco($utenti['docente']))
);
EsitiBlocco::verifica(
  'oltre il periodo configurato esce dal blocco',
  !str_contains($blocco($utenti['docente'], ['giorni_concluse' => 3]), 'Collegio concluso')
);
EsitiBlocco::verifica(
  'e con zero giorni le sedute concluse non si mostrano affatto',
  !str_contains($blocco($utenti['docente'], ['giorni_concluse' => 0]), 'Collegio concluso')
);

echo "\n[8] Il verbale solo a chi può vederlo\n";
$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');
$verbale = $verbalizzazione->perSeduta($conclusa);
EsitiBlocco::verifica(
  'finché il verbale non è sigillato non si offre ad alcuno',
  !str_contains($blocco($utenti['lettore']), 'Verbale')
);

$verbalizzazione->sigilla($verbale, $utenti['segretario']);

$percorsoVerbale = '/psiphos/seduta/' . $conclusa->id() . '/verbale';
// Il verbale è l'unica cosa che si cerchi dopo una seduta conclusa: sta fra i
// dati, accanto allo stato, e non costa una riga di bottoni.
EsitiBlocco::verifica(
  'sigillato, lo vede chi ha il permesso di consultare i verbali',
  str_contains($blocco($utenti['lettore']), $percorsoVerbale)
);
EsitiBlocco::verifica(
  'e non chi quel permesso non ce l\'ha, pur avendo partecipato',
  !str_contains($blocco($utenti['docente']), $percorsoVerbale)
);

echo "\n[9] Il blocco non si mostra a chi non partecipa\n";
$istanza = $gestoreBlocchi->createInstance('psiphos_sedute', []);
EsitiBlocco::verifica(
  'chi non ha alcun permesso di seduta non vede nemmeno il riquadro',
  !$istanza->access($utenti['esterno'])
);
EsitiBlocco::verifica(
  "chi ha i permessi ma non è in elenco vede il riquadro consentito e vuoto",
  $istanza->access($utenti['estraneo']) && $blocco($utenti['estraneo']) === ''
);
$commutatore->switchTo($utenti['docente']);
$costruzione = $gestoreBlocchi->createInstance('psiphos_sedute', [])->build();
$commutatore->switchBack();
EsitiBlocco::verifica(
  'il blocco non si memorizza: lo stato cambia mentre lo si guarda',
  ($costruzione['#cache']['max-age'] ?? -1) === 0
    && in_array('user', $costruzione['#cache']['contexts'] ?? [], TRUE)
);

echo "\n[10] Una scrivania non è un archivio\n";
// Con trenta sedute trattate allo stesso modo il blocco smette di dire che
// cosa fare adesso, che è l'unica ragione per cui sta su una scrivania.
$aggiunte = [];
for ($indice = 1; $indice <= 8; $indice++) {
  $aggiunte[] = $creaSeduta("Collegio numero $indice", [$utenti['docente']], [
    'data_seduta' => $adesso + $indice * 3600,
  ]);
}

$vista = $blocco($utenti['docente']);
EsitiBlocco::verifica(
  'oltre il numero massimo le sedute non si stampano tutte',
  substr_count($vista, '<li class="psiphos-sedute__voce') === 5
);
EsitiBlocco::verifica(
  'e il blocco dichiara quante ne restano fuori',
  str_contains($vista, 'non sono mostrate')
);
EsitiBlocco::verifica(
  'la seduta in corso resta in cima, per quante ne arrivino dopo',
  strpos($vista, 'Collegio convocato') < strpos($vista, 'Collegio numero')
);
EsitiBlocco::verifica(
  'e una sola porta il rilievo e il bottone di ingresso',
  substr_count($vista, 'psiphos-sedute__voce--rilievo') === 1
    && substr_count($vista, 'Entra in aula') === 1
);
EsitiBlocco::verifica(
  'le prossime si leggono dalla più vicina alla più lontana',
  strpos($vista, 'Collegio numero 1') < strpos($vista, 'Collegio numero 2')
);
EsitiBlocco::verifica(
  'alzando il limite compaiono tutte e l\'avviso sparisce',
  !str_contains($blocco($utenti['docente'], ['voci_massime' => 40]), 'non sono mostrate')
);
foreach ($aggiunte as $aggiunta) {
  $aggiunta->delete();
}

echo "\n[11] L'archivio personale\n";
// Il blocco si ferma alle prime sedute, ma i verbali restano nel sito per
// anni: chi vi ha partecipato deve poterli ritrovare. L'elenco amministrativo
// è di chi convoca, e un docente non vi accede.
$archivio = static function (User $utente, string $filtri = '') use ($commutatore): string {
  $commutatore->switchTo($utente);
  try {
    $risposta = \Drupal::service('http_kernel')->handle(
      \Symfony\Component\HttpFoundation\Request::create('/psiphos/le-mie-sedute' . $filtri)
    );

    return $risposta->getStatusCode() === 200
      ? html_entity_decode(strip_tags((string) $risposta->getContent()), ENT_QUOTES, 'UTF-8')
      : sprintf('HTTP %d', $risposta->getStatusCode());
  }
  finally {
    $commutatore->switchBack();
  }
};

$suo = $archivio($utenti['docente']);
EsitiBlocco::verifica(
  "l'archivio elenca le sedute di chi guarda",
  str_contains($suo, 'Collegio convocato') && str_contains($suo, 'Collegio concluso')
);
EsitiBlocco::verifica(
  'e non quelle a cui non è stato chiamato',
  !str_contains($archivio($utenti['estraneo']), 'Collegio convocato')
);
EsitiBlocco::verifica(
  'a chi non ne ha alcuna lo dice, invece di mostrare una tabella vuota',
  str_contains($archivio($utenti['estraneo']), 'Non risulta alcuna seduta')
);
EsitiBlocco::verifica(
  'chi non ha alcun permesso di seduta non vi accede affatto',
  str_contains($archivio($utenti['esterno']), 'HTTP 403')
);
EsitiBlocco::verifica(
  "l'archivio dichiara quante ne risultano in tutto",
  str_contains($suo, 'Sedute che mi riguardano')
);
// Un coordinatore che convoca il Consiglio della propria classe senza
// figurare nell'elenco lo perderebbe di vista il giorno dopo: l'elenco
// amministrativo è di chi amministra il sito, non di chi ha convocato.
EsitiBlocco::verifica(
  "vi compaiono anche le sedute che si presiede",
  str_contains($archivio($utenti['presidente']), 'Presidente')
);
EsitiBlocco::verifica(
  'e quelle che si verbalizza',
  str_contains($archivio($utenti['segretario']), 'Segretario')
);
$convocataDaAltri = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Consiglio della 3A'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => $adesso,
  'presidente' => $utenti['presidente']->id(),
  'segretario' => $utenti['segretario']->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
  'uid' => $utenti['estraneo']->id(),
]);
$convocataDaAltri->save();
Presenza::create(['seduta' => $convocataDaAltri->id(), 'utente' => $utenti['tre_a'] ?? $utenti['docente']->id()]);
EsitiBlocco::verifica(
  'e quelle che si è convocate pur non essendo in elenco',
  str_contains($archivio($utenti['estraneo']), 'Consiglio della 3A')
    && str_contains($archivio($utenti['estraneo']), 'Convocante')
);
EsitiBlocco::verifica(
  'mentre a chi non vi ha alcun titolo restano invisibili',
  !str_contains($archivio($utenti['lettore']), 'Consiglio della 3A')
);
$convocataDaAltri->delete();
EsitiBlocco::verifica(
  'e riporta il verbale solo dove è consultabile',
  str_contains($archivio($utenti['lettore']), 'Verbale')
    && !str_contains($suo, 'Verbale')
);
EsitiBlocco::verifica(
  "il blocco vi rimanda, così l'avviso delle eccedenti non è un vicolo cieco",
  str_contains($blocco($utenti['docente']), '/psiphos/le-mie-sedute')
);
EsitiBlocco::verifica(
  "e vi rimanda con un bottone in chiusura, non con una nota a piè di pagina",
  str_contains($blocco($utenti['docente']), 'psiphos-sedute__chiusura')
    && str_contains($blocco($utenti['docente']), 'psiphos-sedute__azione--archivio')
);
// Il bottone dell'archivio sta fuori dall'elenco: finché le regole pendevano
// dal contenitore non lo raggiungevano, e restava un collegamento nudo.
EsitiBlocco::verifica(
  "il bottone dell'archivio porta la classe delle azioni, non solo la propria",
  str_contains($blocco($utenti['docente']), 'psiphos-sedute__azione psiphos-sedute__azione--archivio')
);
// La dashboard e' di un altro modulo, e il suo accesso dipende dall'esistenza
// di una dashboard per quell'utente, non da un permesso: si verifica con
// un'utenza che ne disponga, e si controlla che il rimando manchi a chi non
// puo' raggiungerla.
EsitiBlocco::verifica(
  "l'archivio riporta alla dashboard, invece di lasciarvici dentro",
  !\Drupal\Core\Url::fromUserInput('/admin/dashboard')->access(User::load(1))
    || str_contains($archivio(User::load(1)), 'Torna alla dashboard')
);
EsitiBlocco::verifica(
  'e non vi rimanda chi non può raggiungerla',
  !str_contains($archivio($utenti['lettore']), 'Torna alla dashboard')
);

echo "\n[12] I filtri dell'archivio\n";
// Cinque anni di sedute non si scorrono a pagine: si cercano. E i filtri
// stanno nell'indirizzo, così una ricerca si condivide con un collega.
$deposito = \Drupal::entityTypeManager()->getStorage('psiphos_seduta');
$deposito->loadUnchanged($conclusa->id())->set('anno_scolastico', '2024/25')->save();
$deposito->loadUnchanged($convocata->id())->set('anno_scolastico', '2026/27')->save();

EsitiBlocco::verifica(
  "il filtro sull'oggetto trattiene le sole corrispondenti",
  str_contains($archivio($utenti['docente'], '?oggetto=concluso'), 'Collegio concluso')
    && !str_contains($archivio($utenti['docente'], '?oggetto=concluso'), 'Collegio convocato')
);
EsitiBlocco::verifica(
  "il filtro sull'anno scolastico distingue le annate",
  str_contains($archivio($utenti['docente'], '?anno=2024/25'), 'Collegio concluso')
    && !str_contains($archivio($utenti['docente'], '?anno=2024/25'), 'Collegio convocato')
);
EsitiBlocco::verifica(
  'il filtro sullo stato distingue le sedute concluse dalle altre',
  !str_contains($archivio($utenti['docente'], '?stato=convocata'), 'Collegio concluso')
);
EsitiBlocco::verifica(
  'il filtro sul ruolo distingue chi presiede da chi partecipa',
  str_contains($archivio($utenti['presidente'], '?ruolo=presidente'), 'Collegio convocato')
    && !str_contains($archivio($utenti['docente'], '?ruolo=presidente'), 'Collegio convocato')
);
EsitiBlocco::verifica(
  'un valore inventato è scartato, non produce un errore',
  str_contains($archivio($utenti['docente'], '?stato=inventato'), 'Collegio convocato')
);
EsitiBlocco::verifica(
  'e quando nulla corrisponde lo dice, distinguendolo da un archivio vuoto',
  str_contains($archivio($utenti['docente'], '?oggetto=zzzinesistente'), 'Nessuna seduta corrisponde')
);
EsitiBlocco::verifica(
  'gli anni proposti sono quelli di chi guarda, non un elenco a priori',
  str_contains($archivio($utenti['docente']), '2024/25')
    && !str_contains($archivio($utenti['estraneo']), '2024/25')
);
EsitiBlocco::verifica(
  'con un filtro attivo compare il comando per azzerarlo',
  str_contains($archivio($utenti['docente'], '?oggetto=concluso'), 'Azzera')
    && !str_contains($archivio($utenti['docente']), 'Azzera')
);

echo "\n[13] Blocco e archivio dicono la stessa cosa\n";
// Il presidente che non figura in elenco — perché in quell'organo non ha
// diritto di voto — deve comunque poter aprire la seduta dalla propria
// scrivania, e chi la convoca deve ritrovarla dove ritrova le altre. Finché
// il blocco interrogava il solo elenco, l'archivio la mostrava e la scrivania
// no.
$fuoriElenco = $creaSeduta('Consiglio della 3A', [$utenti['docente']], [
  'presidente' => $utenti['lettore']->id(),
  'segretario' => $utenti['lettore']->id(),
  'uid' => $utenti['estraneo']->id(),
]);

EsitiBlocco::verifica(
  'chi presiede senza figurare in elenco la vede sulla scrivania',
  str_contains($blocco($utenti['lettore']), 'Consiglio della 3A')
);
EsitiBlocco::verifica(
  'e vi legge il proprio ruolo',
  str_contains($blocco($utenti['lettore']), 'Presiedi questa seduta')
);
EsitiBlocco::verifica(
  'chi la ha convocata la vede e lo legge',
  str_contains($blocco($utenti['estraneo']), 'Consiglio della 3A')
    && str_contains($blocco($utenti['estraneo']), 'Hai convocato questa seduta')
);
EsitiBlocco::verifica(
  'e chi non vi ha alcun titolo continua a non vederla',
  !str_contains($blocco($utenti['segretario']), 'Consiglio della 3A')
);
EsitiBlocco::verifica(
  'blocco e archivio concordano su ciò che mostrano',
  str_contains($archivio($utenti['lettore']), 'Consiglio della 3A')
    && str_contains($archivio($utenti['estraneo']), 'Consiglio della 3A')
);
$fuoriElenco->delete();

echo "\n[14] Convocare dalla dashboard\n";
// Il personale parte dalla dashboard: chi può convocare deve trovarci il
// punto da cui si comincia, anche — anzi soprattutto — quando non c'è ancora
// nulla. Per tutti gli altri il riquadro vuoto resta un ingombro.
$ruoloConvocante = Role::create(['id' => 'psiphos_prova_coordinatore', 'label' => 'Coordinatore di prova']);
$ruoloConvocante->grantPermission('psiphos convocare seduta');
$ruoloConvocante->save();
$convocante = User::create([
  'name' => 'psiphos_prova_convocante',
  'mail' => 'psiphos_prova_convocante@example.test',
  'status' => 1,
  'roles' => ['psiphos_prova_coordinatore'],
]);
$convocante->save();

$vistaConvocante = $blocco($convocante);
EsitiBlocco::verifica(
  'chi può convocare vede il riquadro anche senza alcuna seduta',
  $vistaConvocante !== ''
);
EsitiBlocco::verifica(
  'e vi trova il comando per convocarne una',
  str_contains($vistaConvocante, 'Convoca una seduta')
    && str_contains($vistaConvocante, '/admin/content/psiphos/seduta/aggiungi')
);
EsitiBlocco::verifica(
  'con la spiegazione del perché è vuoto',
  str_contains($vistaConvocante, 'Non risulta alcuna seduta collegiale da mostrare')
);
EsitiBlocco::verifica(
  'chi non può convocare non vede né il comando né il riquadro vuoto',
  $blocco($utenti['estraneo']) === ''
);
EsitiBlocco::verifica(
  'e chi ha sedute ma non può convocare non vede il comando',
  !str_contains($blocco($utenti['docente']), 'Convoca una seduta')
);
EsitiBlocco::verifica(
  "i due comandi stanno sulla stessa riga, convocare prima dell'archivio",
  (static function (string $reso): bool {
    $riga = strpos($reso, 'psiphos-sedute__comandi');
    $crea = strpos($reso, 'Convoca una seduta');
    $archivio = strpos($reso, "Vai all'archivio");

    return $riga !== FALSE && $crea !== FALSE && $riga < $crea
      && ($archivio === FALSE || $crea < $archivio);
  })($vistaConvocante)
);

$convocante->delete();
$ruoloConvocante->delete();

printf("\n--- %d superate, %d fallite ---\n", EsitiBlocco::$superate, EsitiBlocco::$fallite);

ProvaPsiphos::ripulisci();
echo "dati di prova rimossi\n";
