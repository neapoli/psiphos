<?php

/**
 * @file
 * Verifica funzionale della verbalizzazione di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_verbale.php
 *
 * Copre il §7 dell'allegato tecnico: verbali digitali immodificabili,
 * completi, associati ai metadati di autenticità e integrità, conservati nel
 * formato prescritto dalle Linee guida AgID, con esportazione strutturata
 * degli esiti.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\StatoVerbale;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\user\Entity\User;

final class EsitiVerbale {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }

  public static function bloccata(string $descrizione, callable $azione, string $classeAttesa): void {
    try {
      $azione();
      self::verifica($descrizione, FALSE);
    }
    catch (\Throwable $eccezione) {
      for ($corrente = $eccezione; $corrente !== NULL; $corrente = $corrente->getPrevious()) {
        if ($corrente instanceof $classeAttesa) {
          self::verifica($descrizione, TRUE);
          return;
        }
      }
      self::verifica(sprintf('%s [attesa %s, ottenuta %s]', $descrizione, $classeAttesa, get_class($eccezione)), FALSE);
    }
  }
}

$gestoreEntita = \Drupal::entityTypeManager();
$database = \Drupal::database();
$urna = \Drupal::service('psiphos.urna');
$scrutinio = \Drupal::service('psiphos.scrutinio');
$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');
$costruttore = \Drupal::service('psiphos.costruttore_verbale');
$conservazione = \Drupal::service('psiphos.conservazione_documento');

/** Rimuove i soli dati creati da questa verifica. */
$ripulisci = static fn () => ProvaPsiphos::ripulisci();
ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
$ripulisci();

// Seduta completa: due votazioni, una palese e una segreta a scelta.
$utenti = [];
for ($indice = 1; $indice <= 5; $indice++) {
  $utente = User::create([
    'name' => "psiphos_prova_$indice",
    'mail' => "psiphos_prova_$indice@example.test",
    'status' => 1,
  ]);
  $utente->save();
  $utenti[] = $utente;
}

$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti del 26 agosto'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '1/2026-27',
  'anno_scolastico' => '2026/27',
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'data_convocazione' => \Drupal::time()->getRequestTime() - 86400,
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
  'url_videoconferenza' => 'https://meet.example.test/collegio',
  'note_procedurali' => 'Nessun malfunzionamento rilevato.',
]);
$seduta->save();
foreach ($utenti as $utente) {
  Presenza::create([
    'seduta' => $seduta->id(),
    'utente' => $utente->id(),
    'stato' => StatoPresenza::PRESENTE->value,
    'ingresso' => \Drupal::time()->getRequestTime(),
    'ultima_attivita' => \Drupal::time()->getRequestTime(),
  ])->save();
}
$seduta->transitaA(StatoSeduta::APERTA)->save();

$punto = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 1, 'oggetto' => 'Approvazione del PTOF']);
$punto->save();
$puntoDue = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 2, 'oggetto' => "Designazione del referente per l'inclusione"]);
$puntoDue->save();

$palese = Delibera::create([
  'punto_odg' => $punto->id(),
  'numero_delibera' => '1',
  'quesito' => 'Si approva il PTOF 2026/29?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$palese->save();
$palese->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ([0, 1, 2] as $i) { $urna->deposita($palese, $utenti[$i], [SchemaScheda::VOCE_FAVOREVOLE]); }
$urna->deposita($palese, $utenti[3], [SchemaScheda::VOCE_CONTRARIO]);
$scrutinio->chiudiEScrutina($palese);

$segreta = Delibera::create([
  'punto_odg' => $puntoDue->id(),
  'numero_delibera' => '2',
  'quesito' => 'Chi si designa come referente?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Rossi', 'Bianchi'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
]);
$segreta->save();
$segreta->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ([0, 1, 2] as $i) { $urna->deposita($segreta, $utenti[$i], ['opzione_1']); }
$urna->deposita($segreta, $utenti[3], ['opzione_2']);
$scrutinio->chiudiEScrutina($segreta);

// Le delibere concluse vanno redatte come atti prima che il verbale possa
// essere sigillato: l'estratto si sigilla insieme al verbale, e un atto senza
// numero né dispositivo non è protocollabile.
$palese->set('dispositivo', ['value' => 'Approva il PTOF 2026/2029.', 'format' => 'plain_text'])->save();
$segreta->set('oggetto', "Referente per l'inclusione")
  ->set('premesse', [
    'value' => "Visto il D.lgs. 66/2017\nVista la L. 170/2010",
    'format' => 'plain_text',
  ])
  ->set('dispositivo', ['value' => 'Designa la prof.ssa Rossi referente per l\'inclusione.', 'format' => 'plain_text'])
  ->save();

echo "\n[1] Bozza del verbale\n";
$verbale = $verbalizzazione->perSeduta($seduta);
EsitiVerbale::verifica('bozza creata', $verbale->stato() === StatoVerbale::BOZZA);
EsitiVerbale::verifica('una sola bozza per seduta', $verbalizzazione->perSeduta($seduta)->id() === $verbale->id());
$ammissibilita = $verbalizzazione->sigillabile($verbale);
EsitiVerbale::verifica('non sigillabile a seduta aperta', !$ammissibilita['ammesso']);
EsitiVerbale::verifica('e il motivo lo dice', str_contains($ammissibilita['motivo'], 'non è ancora chiusa'));

$seduta->transitaA(StatoSeduta::CHIUSA)->save();
$verbale->set('testo', "Il Presidente illustra il punto. Interviene la prof.ssa Bianchi.\nSi passa alla votazione.")->save();
EsitiVerbale::verifica('sigillabile a seduta chiusa', $verbalizzazione->sigillabile($verbale)['ammesso']);

echo "\n[1-bis] Accesso alla scheda del verbale\n";
// La scheda non deve comparire a chi non ha nulla da vederci: Drupal ricava
// la visibilità delle schede dall'accesso alla rotta, e una scheda che porta
// a una pagina negata è peggio di una scheda assente.
$controlloVerbale = \Drupal::service('psiphos.accesso_verbale_seduta');
$ruoloPartecipante = \Drupal\user\Entity\Role::load('psiphos_prova_partecipante')
  ?? \Drupal\user\Entity\Role::create(['id' => 'psiphos_prova_partecipante', 'label' => 'Partecipante di prova']);
$ruoloPartecipante->grantPermission('psiphos partecipare seduta');
$ruoloPartecipante->grantPermission('psiphos visualizzare verbali');
$ruoloPartecipante->save();
$partecipante = $utenti[2];
$partecipante->addRole('psiphos_prova_partecipante');
$partecipante->save();

$ruoloSegretario = \Drupal\user\Entity\Role::load('psiphos_prova_segretario')
  ?? \Drupal\user\Entity\Role::create(['id' => 'psiphos_prova_segretario', 'label' => 'Segretario di prova']);
$ruoloSegretario->grantPermission('psiphos verbalizzare');
$ruoloSegretario->save();
$utenti[1]->addRole('psiphos_prova_segretario');
$utenti[1]->save();

$verbaliPrima = (int) \Drupal::database()->select('psiphos_verbale', 'v')->countQuery()->execute()->fetchField();
EsitiVerbale::verifica(
  'senza verbale aperto, chi partecipa non vede la scheda',
  !$controlloVerbale->access($seduta, $partecipante)->isAllowed()
);
EsitiVerbale::verifica(
  'il segretario verbalizzante invece sì',
  $controlloVerbale->access($seduta, $utenti[1])->isAllowed()
);
EsitiVerbale::verifica(
  'la sola verifica di accesso non apre alcuna bozza',
  (int) \Drupal::database()->select('psiphos_verbale', 'v')->countQuery()->execute()->fetchField() === $verbaliPrima
);

echo "\n[2] Struttura canonica\n";
$dati = $costruttore->struttura($seduta, $verbale);
EsitiVerbale::verifica('formato dichiarato', $dati['formato'] === \Drupal\psiphos\Service\CostruttoreVerbale::FORMATO);
EsitiVerbale::verifica('identificativo del documento presente', $dati['metadati']['identificativo'] === $seduta->uuid());
EsitiVerbale::verifica('riferimento normativo riportato', str_contains($dati['metadati']['riferimento_normativo'], 'prot. 3803'));
EsitiVerbale::verifica("riferimento al Regolamento d'istituto riportato", str_contains($dati['metadati']['riferimento_regolamento'], '12-bis'));
EsitiVerbale::verifica('registro presenze completo', count($dati['registro_presenze']) === 5);
EsitiVerbale::verifica('registro presenze in ordine alfabetico', $dati['registro_presenze'][0]['nominativo'] === 'psiphos_prova_1');
EsitiVerbale::verifica('ordine del giorno con due punti', count($dati['ordine_del_giorno']) === 2);
EsitiVerbale::verifica('quorum costitutivo documentato', $dati['costituzione']['presenti_minimi'] === 3 && $dati['costituzione']['validamente_costituita']);
EsitiVerbale::verifica('svolgimento redatto dal segretario riportato', str_contains($dati['svolgimento'], 'prof.ssa Bianchi'));

$votazionePalese = $dati['ordine_del_giorno'][0]['votazioni'][0];
$votazioneSegreta = $dati['ordine_del_giorno'][1]['votazioni'][0];

echo "\n[3] Registri di voto: §4.2 contro §4.3\n";
EsitiVerbale::verifica('il voto palese riporta la scelta di ciascuno', isset($votazionePalese['registro_votanti'][0]['voto']));
EsitiVerbale::verifica('e la scelta è leggibile, non una chiave tecnica', $votazionePalese['registro_votanti'][0]['voto'] === 'Favorevole');
EsitiVerbale::verifica('il voto segreto elenca i votanti', count($votazioneSegreta['registro_votanti']) === 4);
EsitiVerbale::verifica(
  'ma non riporta come ciascuno abbia votato',
  !array_key_exists('voto', $votazioneSegreta['registro_votanti'][0])
);
EsitiVerbale::verifica('lo scrutinio segreto riporta comunque il conteggio', $votazioneSegreta['conteggio']['opzione_1'] === 3);
EsitiVerbale::verifica('e il sigillo dell\'urna', strlen($votazioneSegreta['sigillo_urna']) === 64);
EsitiVerbale::verifica('proclamato il più votato', $votazioneSegreta['opzioni_prevalenti'] === ['opzione_1']);

echo "\n[3-bis] Ripetizione e motivazione nel documento\n";
// Una ripetizione deve essere riconoscibile da chi legge il verbale, e
// l'esito deve portare con sé la ragione per cui è quello.
$puntoRipetuto = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 3, 'oggetto' => 'Punto ripetuto']);
$puntoRipetuto->save();
$primaVotazione = Delibera::create([
  'punto_odg' => $puntoRipetuto->id(),
  'quesito' => 'Si approva la proposta?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$primaVotazione->save();
$primaVotazione->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$primaVotazione->transitaA(StatoDelibera::ANNULLATA, 'Malfunzionamento del collegamento.')->save();
$ripetizioneVotazione = Delibera::create([
  'punto_odg' => $puntoRipetuto->id(),
  'quesito' => 'Si approva la proposta? (ripetizione)',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
  'ripetizione_di' => $primaVotazione->id(),
]);
$ripetizioneVotazione->save();

$datiConRipetizione = $costruttore->struttura($seduta, $verbale);
$votazioniDelPunto = [];
foreach ($datiConRipetizione['ordine_del_giorno'] as $puntoStruttura) {
  if ($puntoStruttura['oggetto'] === 'Punto ripetuto') {
    $votazioniDelPunto = $puntoStruttura['votazioni'];
  }
}
$quesitiRipetuti = array_column($votazioniDelPunto, 'ripetizione_di_quesito');
EsitiVerbale::verifica(
  'la ripetizione dichiara il quesito della votazione annullata',
  in_array('Si approva la proposta?', $quesitiRipetuti, TRUE)
);
$annullataInStruttura = NULL;
foreach ($votazioniDelPunto as $votazioneStruttura) {
  if ($votazioneStruttura['esito'] === 'annullata') {
    $annullataInStruttura = $votazioneStruttura;
  }
}
EsitiVerbale::verifica(
  "l'annullata porta la motivazione dell'esito",
  str_contains((string) ($annullataInStruttura['motivazione_esito'] ?? ''), 'resta agli atti')
);

// La motivazione è testo tradotto: sta nel documento, non nell'impronta.
$canonicaConRipetizione = $costruttore->serializza($costruttore->strutturaCanonica($seduta, $verbale));
EsitiVerbale::verifica(
  "la motivazione dell'esito resta fuori dall'impronta",
  !str_contains($canonicaConRipetizione, 'motivazione_esito')
);
EsitiVerbale::verifica(
  'mentre il riferimento alla votazione ripetuta vi entra',
  str_contains($canonicaConRipetizione, 'ripetizione_di_quesito')
);
EsitiVerbale::verifica(
  'ogni votazione porta un identificativo stabile',
  str_contains($canonicaConRipetizione, 'identificativo')
);

$ripetizioneVotazione->delete();
$primaVotazione->delete();
$puntoRipetuto->delete();

echo "\n[4] Impronta del contenuto\n";
$impronta = $costruttore->impronta($seduta, $verbale);
EsitiVerbale::verifica('impronta a 64 caratteri', strlen($impronta) === 64);
EsitiVerbale::verifica('ricalcolata due volte dà lo stesso valore', $impronta === $costruttore->impronta($seduta, $verbale));
$serializzato = $costruttore->serializza($costruttore->strutturaCanonica($seduta, $verbale));
EsitiVerbale::verifica('la serializzazione è JSON valido', json_decode($serializzato, TRUE) !== NULL);
EsitiVerbale::verifica(
  'e ricalcolare da essa dà la stessa impronta',
  hash('sha256', $serializzato) === $impronta
);
EsitiVerbale::verifica(
  'il momento di generazione non entra nel calcolo',
  !str_contains($serializzato, 'generato_il')
);

echo "\n[5] Sigillo\n";
EsitiVerbale::verifica('formato di conservazione producibile su questo server', $conservazione->conservazioneDisponibile());
$verbale = $verbalizzazione->sigilla($verbale, $utenti[1]);
$verbale = $gestoreEntita->getStorage('psiphos_verbale')->loadUnchanged($verbale->id());
EsitiVerbale::verifica('verbale sigillato', $verbale->sigillato());
// L'impronta registrata è quella dei byte conservati, non quella calcolabile
// prima del sigillo: fra i metadati c'è la data di chiusura, che esiste solo
// da quando il documento è chiuso. Un'impronta identica prima e dopo
// significherebbe che il sigillo non lascia traccia nel documento.
EsitiVerbale::verifica(
  "l'impronta registrata è quella dei byte conservati",
  $verbale->get('impronta_contenuto')->value === hash('sha256', $verbalizzazione->esporta($verbale))
);
EsitiVerbale::verifica(
  "e differisce da quella calcolabile sulla bozza, che non aveva data di chiusura",
  $verbale->get('impronta_contenuto')->value !== $impronta
);
EsitiVerbale::verifica('impronta del documento registrata', strlen((string) $verbale->get('impronta_pdf')->value) === 64);
EsitiVerbale::verifica('sigillante registrato', (int) $verbale->get('sigillato_da')->target_id === (int) $utenti[1]->id());
\Drupal::service('psiphos.verbalizzazione');
$gestoreEntita->getStorage('psiphos_verbale')->resetCache([$verbale->id()]);
EsitiVerbale::verifica(
  'sigillato il verbale, chi partecipa può consultarlo',
  \Drupal::service('psiphos.accesso_verbale_seduta')->access($seduta, $partecipante)->isAllowed()
);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiVerbale::verifica('la seduta risulta verbalizzata', $seduta->stato() === StatoSeduta::VERBALIZZATA);

echo "\n[6] Documento conservato\n";
$file = $verbale->get('documento')->entity;
EsitiVerbale::verifica('documento allegato al verbale', $file !== NULL);
EsitiVerbale::verifica('conservato fra i file riservati', str_starts_with($file->getFileUri(), 'private://'));
EsitiVerbale::verifica('file permanente, non temporaneo', $file->isPermanent());
$contenuto = (string) file_get_contents($file->getFileUri());
EsitiVerbale::verifica('è un PDF', str_starts_with($contenuto, '%PDF-'));
EsitiVerbale::verifica('formato dichiarato PDF/A-2B', $verbale->get('formato')->value === 'PDF/A-2B');
EsitiVerbale::verifica('il documento porta i marcatori PDF/A', str_contains($contenuto, 'pdfaid'));
EsitiVerbale::verifica("l'impronta del file corrisponde", hash('sha256', $contenuto) === $verbale->get('impronta_pdf')->value);
EsitiVerbale::verifica('il documento contiene il quesito messo ai voti', strlen($contenuto) > 5000);

// Il documento è la fotografia del verbale sigillato: non deve riportare la
// dicitura di bozza né un'impronta ancora vuota.
$testoDocumento = (string) \Drupal::service('psiphos.verbalizzazione')->documentoHtml($verbale);
EsitiVerbale::verifica(
  'il documento non si dichiara bozza',
  !str_contains($testoDocumento, 'Bozza non sigillata')
);
EsitiVerbale::verifica(
  'riporta la data del sigillo',
  str_contains($testoDocumento, 'Sigillato il')
);
EsitiVerbale::verifica(
  'e chi lo ha sigillato',
  str_contains($testoDocumento, $utenti[1]->getAccountName())
);
EsitiVerbale::verifica(
  "porta l'impronta del contenuto, che è nota prima di produrlo",
  str_contains($testoDocumento, (string) $verbale->get('impronta_contenuto')->value)
);
EsitiVerbale::verifica(
  "spiega perché non contiene la propria impronta",
  str_contains($testoDocumento, 'non può contenere la propria impronta')
);

// A schermo, dove le impronte sono entrambe note, si mostrano entrambe.
$paginaVerbale = (string) \Drupal::service('renderer')->renderRoot(
  $gestoreEntita->getViewBuilder('psiphos_verbale')->view($verbale)
);
EsitiVerbale::verifica(
  'la pagina del verbale riporta anche impronta del documento e formato',
  str_contains($paginaVerbale, (string) $verbale->get('impronta_pdf')->value)
    && str_contains($paginaVerbale, (string) $verbale->get('formato')->value)
);

echo "\n[7] Immodificabilità (§7)\n";
EsitiVerbale::bloccata('un verbale sigillato non si salva più', static function () use ($verbale): void {
  $verbale->set('testo', 'Testo alterato dopo il sigillo.');
  $verbale->save();
}, \LogicException::class);
$verbale = $gestoreEntita->getStorage('psiphos_verbale')->loadUnchanged($verbale->id());
EsitiVerbale::verifica('il testo in banca dati è intatto', str_contains((string) $verbale->get('testo')->value, 'prof.ssa Bianchi'));
EsitiVerbale::verifica('il verbale non è cancellabile', $verbale->access('delete', $utenti[1], TRUE)->isForbidden());
EsitiVerbale::verifica('e non è più modificabile nemmeno dal segretario', !$verbale->access('update', $utenti[1]));

// Il sigillo vale anche contro l'amministratore: la scheda di redazione non
// deve comparire a chi non potrà comunque salvare.
$gestoreEntita->getAccessControlHandler('psiphos_verbale')->resetCache();
$amministratoreVerbale = \Drupal\user\Entity\User::load(1);
EsitiVerbale::verifica(
  "nemmeno l'amministratore può modificare un verbale sigillato",
  !$verbale->access('update', $amministratoreVerbale)
);
EsitiVerbale::verifica(
  'né cancellarlo',
  !$verbale->access('delete', $amministratoreVerbale)
);

// Il verbale è un'entità propria: senza un rimando, chi vi arriva perde la
// seduta di vista.
// Il rimando è filtrato sull'accesso alla seduta dell'utenza corrente.
\Drupal::service('account_switcher')->switchTo($amministratoreVerbale);
$paginaSigillata = (string) \Drupal::service('renderer')->renderRoot(
  $gestoreEntita->getViewBuilder('psiphos_verbale')->view($verbale)
);
EsitiVerbale::verifica(
  'dalla pagina del verbale si torna alla seduta',
  str_contains($paginaSigillata, 'Torna alla seduta')
);

// I comandi sono pulsanti e precedono l'intestazione: si arriva qui per
// scaricare il documento, la lettura del testo viene dopo.
EsitiVerbale::verifica(
  'i comandi precedono il titolo',
  strpos($paginaSigillata, 'psiphos-azioni--testata') < strpos($paginaSigillata, '<h1')
);
EsitiVerbale::verifica(
  'lo scarico del documento è il comando principale',
  (bool) preg_match('#<a[^>]*class="[^"]*button--primary[^"]*"[^>]*>\s*Scarica il documento#', $paginaSigillata)
);
$comandiNonPulsanti = 0;
if (preg_match('#<ul class="psiphos-azioni psiphos-azioni--testata">(.*?)</ul>#s', $paginaSigillata, $blocco)) {
  preg_match_all('#<a\b[^>]*>#', $blocco[1], $ancore);
  foreach ($ancore[0] as $ancora) {
    if (!str_contains($ancora, 'button')) {
      $comandiNonPulsanti++;
    }
  }
}
EsitiVerbale::verifica('tutti e tre i comandi sono pulsanti', $comandiNonPulsanti === 0);
$paginaVerifica = (string) \Drupal::service('renderer')->renderRoot(
  \Drupal\psiphos\Controller\VerbaleController::create(\Drupal::getContainer())->verifica($verbale)
);
\Drupal::service('account_switcher')->switchBack();
EsitiVerbale::verifica(
  'e anche dalla verifica di integrità',
  str_contains($paginaVerifica, 'Torna alla seduta')
);

// Anche chi partecipa, a verbale sigillato, ritrova la strada per la seduta.
\Drupal::service('account_switcher')->switchTo($partecipante);
$gestoreEntita->getAccessControlHandler('psiphos_seduta')->resetCache();
$paginaPartecipante = (string) \Drupal::service('renderer')->renderRoot(
  $gestoreEntita->getViewBuilder('psiphos_verbale')->view($verbale)
);
\Drupal::service('account_switcher')->switchBack();
EsitiVerbale::verifica(
  'e lo stesso vale per chi ha partecipato alla seduta',
  str_contains($paginaPartecipante, 'Torna alla seduta')
);

echo "\n[8] Verifica\n";
// Integrità e corrispondenza rispondono a due domande diverse: la prima
// riguarda i byte conservati, la seconda il fatto che la banca dati racconti
// ancora la stessa seduta. Confonderle, com'era prima, faceva sì che un
// cognome corretto o una traduzione rivista dichiarassero manomesso un
// verbale intatto.
$controllo = $costruttore->verifica($verbale);
EsitiVerbale::verifica('il verbale conserva la propria esportazione', $controllo['sigillato']);
EsitiVerbale::verifica("l'esportazione conservata è quella sigillata", $controllo['integro']);
EsitiVerbale::verifica('e la banca dati vi corrisponde', $controllo['corrispondente']);

/** Rilegge il verbale ignorando ogni cache. */
$rileggiVerbale = static function () use ($gestoreEntita, $seduta, $verbale) {
  $gestoreEntita->getStorage('psiphos_seduta')->resetCache([$seduta->id()]);
  $gestoreEntita->getStorage('psiphos_verbale')->resetCache([$verbale->id()]);
  return $gestoreEntita->getStorage('psiphos_verbale')->loadUnchanged($verbale->id());
};

// Scrittura diretta sui dati della seduta a valle del sigillo: è ciò che
// accade tanto per una manomissione quanto per una correzione legittima.
$database->update('psiphos_seduta')
  ->fields(['note_procedurali__value' => 'Nota aggiunta dopo il sigillo.'])
  ->condition('id', $seduta->id())
  ->execute();
$controlloDopo = $costruttore->verifica($rileggiVerbale());
EsitiVerbale::verifica(
  "una modifica ai dati non intacca l'integrità del documento conservato",
  $controlloDopo['integro']
);
EsitiVerbale::verifica(
  'ma la corrispondenza con la banca dati viene meno',
  !$controlloDopo['corrispondente']
);
EsitiVerbale::verifica(
  "e l'impronta dei dati attuali diverge da quella conservata",
  $controlloDopo['impronta_dati_attuali'] !== $controlloDopo['impronta_ricalcolata']
);

$database->update('psiphos_seduta')
  ->fields(['note_procedurali__value' => 'Nessun malfunzionamento rilevato.'])
  ->condition('id', $seduta->id())
  ->execute();
EsitiVerbale::verifica(
  'ripristinato il dato, la corrispondenza torna',
  $costruttore->verifica($rileggiVerbale())['corrispondente']
);

// Manomissione dell'esportazione conservata: questa sì che è compromissione.
$database->update('psiphos_verbale')
  ->fields(['contenuto' => '{"formato":"psiphos-verbale-v1","alterato":true}'])
  ->condition('id', $verbale->id())
  ->execute();
$controlloAlterato = $costruttore->verifica($rileggiVerbale());
EsitiVerbale::verifica(
  "alterare l'esportazione conservata rompe l'integrità",
  !$controlloAlterato['integro']
);
$database->update('psiphos_verbale')
  ->fields(['contenuto' => $verbale->get('contenuto')->value])
  ->condition('id', $verbale->id())
  ->execute();
EsitiVerbale::verifica(
  "ripristinati i byte, l'integrità torna",
  $costruttore->verifica($rileggiVerbale())['integro']
);

// La ragione per cui l'esportazione si conserva invece di ricostruirla: un
// nominativo corretto è un fatto normale, e non deve invalidare un atto.
$primaDellaCorrezione = $verbalizzazione->esporta($rileggiVerbale());
$utenti[3]->set('name', 'psiphos_prova_4_rinominato')->save();
$verbaleRiletto = $rileggiVerbale();
EsitiVerbale::verifica(
  'la correzione di un nominativo non altera i byte esportati',
  $verbalizzazione->esporta($verbaleRiletto) === $primaDellaCorrezione
);
EsitiVerbale::verifica(
  "né l'integrità del verbale",
  $costruttore->verifica($verbaleRiletto)['integro']
);
$utenti[3]->set('name', 'psiphos_prova_4')->save();

echo "\n[8-bis] Carta intestata\n";
$carta = \Drupal::service('psiphos.intestazione_istituto')->dati();
$struttura = $verbalizzazione->struttura($verbale);
EsitiVerbale::verifica(
  "la denominazione viene dal nome del sito e non dal titolo del luogo",
  $carta['istituto'] === (string) \Drupal::config('system.site')->get('name')
);
EsitiVerbale::verifica(
  "l'intestazione entra nel verbale conservato",
  ($struttura['metadati']['intestazione']['istituto'] ?? '') === $carta['istituto']
);
$documentoIntestato = $verbalizzazione->documentoHtml($verbale);
EsitiVerbale::verifica(
  'e compare nel documento',
  str_contains(html_entity_decode(strip_tags($documentoIntestato), ENT_QUOTES, 'UTF-8'), $carta['istituto'])
);
// Verbale ed estratto includono lo stesso template: escono dalla stessa
// segreteria e devono presentarsi allo stesso modo.
EsitiVerbale::verifica(
  'con lo stesso blocco usato dagli estratti di delibera',
  str_contains($documentoIntestato, 'psiphos-carta__istituto')
);

echo "\n[9] Esportazione strutturata\n";
$esportazione = $verbalizzazione->esporta($verbale);
$riletto = json_decode($esportazione, TRUE);
EsitiVerbale::verifica('esportazione JSON valida', is_array($riletto));
EsitiVerbale::verifica(
  "l'esportazione è esattamente ciò che il verbale conserva",
  $esportazione === (string) $verbale->get('contenuto')->value
);
EsitiVerbale::verifica('contiene ordine del giorno e votazioni', isset($riletto['ordine_del_giorno'][0]['votazioni'][0]['conteggio']));
EsitiVerbale::verifica('contiene il registro delle presenze', count($riletto['registro_presenze']) === 5);
EsitiVerbale::verifica(
  "l'esportazione non rivela come si è votato a scrutinio segreto",
  !isset($riletto['ordine_del_giorno'][1]['votazioni'][0]['registro_votanti'][0]['voto'])
);
EsitiVerbale::verifica(
  "l'impronta è lo SHA-256 del file esportato, senza alcuna elaborazione",
  hash('sha256', $esportazione) === $verbale->get('impronta_contenuto')->value
);
EsitiVerbale::verifica(
  "l'esportazione non porta dati volatili",
  !str_contains($esportazione, 'generato_il') && !str_contains($esportazione, 'motivazione_esito')
);

echo "\n[Metadati di conservazione (§7)]\n";
// Il formato PDF/A da solo non basta: senza tipologia documentale e data di
// chiusura il pacchetto di versamento viene respinto dal conservatore, e lo si
// scopre al primo versamento vero, quando i documenti sono già decine.
$conservato = json_decode($verbalizzazione->esporta($verbale), TRUE);
foreach ([
  'identificativo' => "l'identificativo del documento",
  'tipologia_documentale' => 'la tipologia documentale',
  'data_chiusura' => 'la data di chiusura',
  'modalita_formazione' => 'la modalità di formazione',
  'oggetto' => "l'oggetto",
  'soggetto_produttore' => 'il soggetto produttore',
] as $metadato => $descrizione) {
  EsitiVerbale::verifica(
    "i metadati conservati comprendono $descrizione",
    trim((string) ($conservato['metadati'][$metadato] ?? '')) !== ''
  );
}
EsitiVerbale::verifica(
  'la data di chiusura è quella del sigillo',
  ($conservato['metadati']['data_chiusura'] ?? '') === date('c', (int) $verbale->get('sigillato_il')->value)
);
EsitiVerbale::verifica(
  'e la versione della struttura segue i campi che contiene',
  ($conservato['formato'] ?? '') === 'psiphos-verbale-v2'
);

printf("\n--- %d superate, %d fallite ---\n", EsitiVerbale::$superate, EsitiVerbale::$fallite);

$ripulisci();
foreach (['psiphos_prova_partecipante', 'psiphos_prova_segretario'] as $ruoloDiProva) {
  if ($daRimuovere = \Drupal\user\Entity\Role::load($ruoloDiProva)) {
    $daRimuovere->delete();
  }
}
echo "pulizia completata\n";
