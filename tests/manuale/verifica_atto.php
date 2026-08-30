<?php

/**
 * @file
 * Verifica funzionale dell'estratto di delibera di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_atto.php
 *
 * Le scuole tengono le delibere separate dal verbale: il verbale documenta la
 * seduta, l'estratto documenta il singolo atto e circola da solo verso
 * l'albo, l'Amministrazione Trasparente e gli uffici. Sono due documenti con
 * due destinazioni, e questa verifica accerta che siano anche due documenti
 * verificabili separatamente, ciascuno con la propria impronta, e che l'uno
 * non possa essere sigillato senza l'altro.
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
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\user\Entity\User;

final class EsitiAtto {
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
$urna = \Drupal::service('psiphos.urna');
$scrutinio = \Drupal::service('psiphos.scrutinio');
$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');
$costruttoreAtto = \Drupal::service('psiphos.costruttore_atto');
$commutatore = \Drupal::service('account_switcher');

ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
ProvaPsiphos::ripulisci();

$adesso = \Drupal::time()->getRequestTime();

$utenti = [];
for ($indice = 1; $indice <= 4; $indice++) {
  $utente = User::create([
    'name' => ProvaPsiphos::PREFISSO_UTENTI . $indice,
    'mail' => ProvaPsiphos::PREFISSO_UTENTI . "$indice@example.test",
    'status' => 1,
  ]);
  $utente->save();
  $utenti[] = $utente;
}

$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti del 30 giugno'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '8/2025-26',
  'anno_scolastico' => '2025/26',
  'data_seduta' => $adesso,
  'data_convocazione' => $adesso - 86400,
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
]);
$seduta->save();

foreach ($utenti as $utente) {
  Presenza::create([
    'seduta' => $seduta->id(),
    'utente' => $utente->id(),
    'stato' => StatoPresenza::PRESENTE->value,
    'ingresso' => $adesso,
    'ultima_attivita' => $adesso,
  ])->save();
}
$seduta->transitaA(StatoSeduta::APERTA)->save();

$punto = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 3,
  'oggetto' => 'Approvazione PAI 2025',
]);
$punto->save();

// Delibera approvata all'unanimità: è il caso della scuola, e la formula
// deve dirlo con queste parole.
$unanime = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva il PAI 2025/2026?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$unanime->save();
$unanime->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ($utenti as $utente) {
  $urna->deposita($unanime, $utente, [SchemaScheda::VOCE_FAVOREVOLE]);
}
$scrutinio->chiudiEScrutina($unanime);

// Delibera approvata a maggioranza, con un contrario e un astenuto.
$puntoDue = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 4,
  'oggetto' => 'Adozione del piano delle attività',
]);
$puntoDue->save();
$maggioranza = Delibera::create([
  'punto_odg' => $puntoDue->id(),
  'quesito' => 'Si adotta il piano delle attività?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$maggioranza->save();
$maggioranza->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($maggioranza, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($maggioranza, $utenti[1], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($maggioranza, $utenti[2], [SchemaScheda::VOCE_CONTRARIO]);
$urna->deposita($maggioranza, $utenti[3], [SchemaScheda::VOCE_ASTENUTO]);
$scrutinio->chiudiEScrutina($maggioranza);

// Delibera annullata: resta agli atti nel verbale, ma non è un atto.
$annullata = Delibera::create([
  'punto_odg' => $puntoDue->id(),
  'quesito' => 'Si adotta il piano nella prima formulazione?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$annullata->save();
$annullata->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($annullata, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$annullata->transitaA(StatoDelibera::ANNULLATA, 'Formulazione del quesito equivoca.')->save();

echo "\n[1] Proclamazione e prospetto\n";
$proclamazioneUnanime = $scrutinio->proclamazione($unanime);
EsitiAtto::verifica(
  "la proclamazione nomina l'organo come soggetto, con l'articolo",
  str_starts_with($proclamazioneUnanime, 'Il Collegio dei docenti')
);
EsitiAtto::verifica(
  'con il verbo di approvazione',
  str_contains($proclamazioneUnanime, 'approva') && !str_contains($proclamazioneUnanime, 'non approva')
);
EsitiAtto::verifica("l'unanimità è riconosciuta", str_contains($proclamazioneUnanime, "all'unanimità"));
EsitiAtto::verifica(
  'e introduce il prospetto',
  str_contains($proclamazioneUnanime, 'con la seguente votazione:')
);

$prospetto = $scrutinio->prospettoVotazione($unanime);
$valori = array_column($prospetto, 'valore', 'voce');
EsitiAtto::verifica(
  'il prospetto apre con la base di calcolo',
  array_slice(array_column($prospetto, 'voce'), 0, 3) === ['Aventi diritto', 'Presenti', 'Votanti']
);
EsitiAtto::verifica(
  'e prosegue con le voci nell\'ordine della scheda',
  array_slice(array_column($prospetto, 'voce'), 3) === ['Favorevole', 'Contrario', 'Astenuto']
);
EsitiAtto::verifica(
  'le cifre sono quelle dell\'urna',
  $valori['Votanti'] === 4 && $valori['Favorevole'] === 4 && $valori['Contrario'] === 0
);
EsitiAtto::verifica(
  "su una scheda di approvazione nessuna voce è marcata proclamata",
  array_filter(array_column($prospetto, 'qualifica')) === []
);

$proclamazioneMaggioranza = $scrutinio->proclamazione($maggioranza);
EsitiAtto::verifica(
  "un astenuto toglie l'unanimità",
  !str_contains($proclamazioneMaggioranza, "all'unanimità")
);
$valoriMaggioranza = array_column($scrutinio->prospettoVotazione($maggioranza), 'valore', 'voce');
EsitiAtto::verifica(
  'ma il prospetto riporta comunque ogni voce',
  $valoriMaggioranza['Favorevole'] === 2
    && $valoriMaggioranza['Contrario'] === 1
    && $valoriMaggioranza['Astenuto'] === 1
);
EsitiAtto::verifica(
  'una votazione annullata non proclama nulla',
  str_contains($scrutinio->proclamazione($annullata), 'non produce effetti')
);

echo "\n[2] Che cosa è un atto da formalizzare\n";
EsitiAtto::verifica('una votazione conclusa lo è', $unanime->daFormalizzare());
EsitiAtto::verifica('una votazione annullata non lo è', !$annullata->daFormalizzare());

$predisposta = Delibera::create([
  'punto_odg' => $puntoDue->id(),
  'quesito' => 'Quesito mai messo ai voti',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$predisposta->save();
EsitiAtto::verifica('una votazione mai aperta non lo è', !$predisposta->daFormalizzare());
$predisposta->delete();

$seduta->transitaA(StatoSeduta::CHIUSA)->save();
$daFormalizzare = $verbalizzazione->delibereDaFormalizzare($seduta);
EsitiAtto::verifica(
  'la seduta ha due atti da formalizzare e non tre',
  count($daFormalizzare) === 2
);

echo "\n[3] Il sigillo attende che gli atti siano redatti\n";
$verbale = $verbalizzazione->perSeduta($seduta);
$ammissibilita = $verbalizzazione->sigillabile($verbale);
EsitiAtto::verifica('il verbale non è sigillabile con atti incompleti', !$ammissibilita['ammesso']);
EsitiAtto::verifica(
  'e il motivo nomina le delibere da completare',
  str_contains($ammissibilita['motivo'], 'Si approva il PAI 2025/2026?')
);
EsitiAtto::verifica(
  'dicendo che cosa manca a ciascuna',
  str_contains($ammissibilita['motivo'], 'il numero di delibera')
    && str_contains($ammissibilita['motivo'], 'il dispositivo')
);
// Il verbo e la congiunzione seguono il numero delle lacune: un elenco
// giustapposto — «manca il numero di delibera, il dispositivo» — si legge
// come un'apposizione anziché come un elenco di ciò che manca.
EsitiAtto::verifica(
  'con due lacune il verbo è al plurale e le voci sono congiunte',
  $unanime->descrizioneLacune() === 'mancano il numero di delibera e il dispositivo'
);

$unanime->set('numero_delibera', '35')->save();
EsitiAtto::verifica(
  'con il solo numero l\'atto è ancora incompleto',
  $unanime->lacuneAtto() !== []
);
EsitiAtto::verifica(
  'e con una sola lacuna il verbo torna al singolare',
  $unanime->descrizioneLacune() === 'manca il dispositivo'
);

$unanime->set('oggetto', 'Piano Annuale per l\'Inclusione 2025-2026')
  ->set('premesse', [
    'value' => "Visto il DPR 275/1999 Regolamento dell'Autonomia\nVisto il D.lgs. 66/2017\nTenuto conto della Nota USR prot. 12820 del 26/05/2026",
    'format' => 'plain_text',
  ])
  ->set('dispositivo', [
    'value' => 'Approva il Piano Annuale per l\'Inclusione (PAI) 2025/2026, allegato alla presente delibera.',
    'format' => 'plain_text',
  ])
  ->save();
EsitiAtto::verifica('con numero e dispositivo non ha più lacune', $unanime->lacuneAtto() === []);
EsitiAtto::verifica('e non ha nulla da dichiarare mancante', $unanime->descrizioneLacune() === '');

$maggioranza->set('numero_delibera', '36')
  ->set('dispositivo', ['value' => 'Adotta il piano delle attività.', 'format' => 'plain_text'])
  ->save();
EsitiAtto::verifica(
  'completati gli atti, il verbale è sigillabile',
  $verbalizzazione->sigillabile($verbale)['ammesso']
);

echo "\n[4] Oggetto dell'atto\n";
EsitiAtto::verifica(
  "l'oggetto proprio prevale sul quesito",
  $unanime->oggettoAtto() === 'Piano Annuale per l\'Inclusione 2025-2026'
);
EsitiAtto::verifica(
  'e dove manca vale il quesito',
  $maggioranza->oggettoAtto() === 'Si adotta il piano delle attività?'
);

echo "\n[5] Redazione dell'atto: chi e quando\n";
$controllo = \Drupal::service('psiphos.accesso_redazione_atto');
$ruolo = \Drupal\user\Entity\Role::load(ProvaPsiphos::PREFISSO_UTENTI . 'segretario')
  ?? \Drupal\user\Entity\Role::create(['id' => ProvaPsiphos::PREFISSO_UTENTI . 'segretario', 'label' => 'Segretario di prova']);
$ruolo->grantPermission('psiphos verbalizzare')->save();
$utenti[1]->addRole($ruolo->id())->save();
$utenti[2]->save();

EsitiAtto::verifica(
  'il segretario può redigere l\'atto',
  $controllo->access($unanime, $utenti[1])->isAllowed()
);
EsitiAtto::verifica(
  'chi non ha incarichi nella seduta non può',
  !$controllo->access($unanime, $utenti[2])->isAllowed()
);
EsitiAtto::verifica(
  'una votazione annullata non è redigibile come atto',
  !$controllo->access($annullata, $utenti[1])->isAllowed()
);

echo "\n[6] Sigillo degli estratti\n";
$verbale = $verbalizzazione->sigilla($verbale, $utenti[1]);
$archivioDelibere = $gestoreEntita->getStorage('psiphos_delibera');
$archivioDelibere->resetCache();
$unanime = $archivioDelibere->load($unanime->id());
$maggioranza = $archivioDelibere->load($maggioranza->id());
$annullata = $archivioDelibere->load($annullata->id());

EsitiAtto::verifica("l'atto risulta sigillato", $unanime->attoSigillato());
EsitiAtto::verifica('e porta un documento', $unanime->get('documento')->target_id !== NULL);
EsitiAtto::verifica('il secondo atto pure', $maggioranza->attoSigillato());
EsitiAtto::verifica(
  'la votazione annullata non ha prodotto alcun estratto',
  !$annullata->attoSigillato() && $annullata->get('documento')->target_id === NULL
);
EsitiAtto::verifica(
  'gli estratti stanno nella cartella delle delibere',
  str_contains((string) $unanime->get('documento')->entity->getFileUri(), 'psiphos/delibere/')
);

echo "\n[7] Impronte dell'atto\n";
$esportazione = $verbalizzazione->esportaAtto($unanime);
EsitiAtto::verifica('esportazione JSON valida', json_decode($esportazione, TRUE) !== NULL);
EsitiAtto::verifica(
  "l'impronta registrata è lo SHA-256 del file esportato, senza elaborazioni",
  hash('sha256', $esportazione) === (string) $unanime->get('impronta_atto')->value
);
EsitiAtto::verifica(
  "l'impronta del documento è lo SHA-256 del PDF",
  hash_file('sha256', $unanime->get('documento')->entity->getFileUri())
    === (string) $unanime->get('impronta_documento')->value
);
EsitiAtto::verifica(
  'i due atti hanno impronte diverse',
  $unanime->get('impronta_atto')->value !== $maggioranza->get('impronta_atto')->value
);

$verifica = $costruttoreAtto->verifica($unanime, $verbale);
EsitiAtto::verifica("l'atto risulta integro", $verifica['sigillato'] && $verifica['integro']);
EsitiAtto::verifica('e la banca dati vi corrisponde', $verifica['corrispondente']);
EsitiAtto::verifica(
  "l'esportazione è esattamente ciò che la delibera conserva",
  $esportazione === (string) $unanime->get('contenuto')->value
);

// È la ragione per cui l'esportazione si conserva anziché ricostruirla a ogni
// richiesta: la correzione di un cognome è un fatto ordinario, e un atto già
// sigillato non deve smettere di verificare perché qualcuno si è sposato.
$utenti[0]->set('name', ProvaPsiphos::PREFISSO_UTENTI . '1_rinominato')->save();
$archivioDelibere->resetCache();
$attoRiletto = $archivioDelibere->load($unanime->id());
EsitiAtto::verifica(
  'la correzione di un nominativo non altera i byte esportati',
  $verbalizzazione->esportaAtto($attoRiletto) === $esportazione
);
EsitiAtto::verifica(
  "né l'integrità dell'atto",
  $costruttoreAtto->verifica($attoRiletto, $verbale)['integro']
);
$utenti[0]->set('name', ProvaPsiphos::PREFISSO_UTENTI . '1')->save();

// L'ordine del conteggio è quello della scheda, non quello alfabetico: è
// l'ordine con cui il collegio ha votato e con cui l'esito si legge.
$conteggioEsportato = array_keys(json_decode($esportazione, TRUE)['votazione']['conteggio']);
EsitiAtto::verifica(
  "il conteggio conserva l'ordine delle voci sulla scheda",
  $conteggioEsportato === [SchemaScheda::VOCE_FAVOREVOLE, SchemaScheda::VOCE_CONTRARIO, SchemaScheda::VOCE_ASTENUTO]
);

$dati = json_decode($esportazione, TRUE);
EsitiAtto::verifica(
  "l'esportazione non porta la proclamazione, che è testo derivato",
  !str_contains($esportazione, "all'unanimità")
);
EsitiAtto::verifica(
  'ma porta il conteggio da cui la formula si ricava',
  ($dati['votazione']['conteggio'][SchemaScheda::VOCE_FAVOREVOLE] ?? NULL) === 4
);
EsitiAtto::verifica(
  "l'atto dichiara da quale verbale è tratto",
  $dati['verbale']['identificativo'] === $verbale->uuid()
);
EsitiAtto::verifica(
  "e con quale impronta quel verbale è stato sigillato",
  $dati['verbale']['impronta_contenuto'] === (string) $verbale->get('impronta_contenuto')->value
);
EsitiAtto::verifica(
  "porta il sigillo dell'urna da cui l'esito è uscito",
  $dati['votazione']['sigillo_urna'] === (string) $unanime->get('sigillo_urna')->value
);
EsitiAtto::verifica(
  'e le premesse per intero, nell\'ordine in cui sono state redatte',
  str_starts_with($dati['atto']['premesse'], "Visto il DPR 275/1999 Regolamento dell'Autonomia")
    && str_contains($dati['atto']['premesse'], 'Tenuto conto della Nota USR')
);

echo "\n[7-bis] Intestazione dell'istituto\n";
$intestazione = \Drupal::service('psiphos.intestazione_istituto')->dati();
EsitiAtto::verifica(
  "l'intestazione riporta almeno il nome dell'istituto",
  trim($intestazione['istituto']) !== ''
);
EsitiAtto::verifica(
  "ed entra nell'atto conservato",
  ($dati['metadati']['intestazione']['istituto'] ?? '') === $intestazione['istituto']
);
// Congelata al sigillo: una delibera protocollata porta i recapiti che
// l'istituto aveva quel giorno, non quelli di oggi. Se si ricavassero ogni
// volta dai dati vivi, cambiare un numero di telefono riscriverebbe la carta
// intestata di tutti gli atti già adottati — e ne romperebbe l'impronta.
$sedi = \Drupal::entityQuery('node')->accessCheck(FALSE)
  ->condition('type', 'luogo')->condition('field_sede_legale', 1)->range(0, 1)->execute();
if ($sedi !== []) {
  $sede = \Drupal::entityTypeManager()->getStorage('node')->load(reset($sedi));
  $telefonoOriginale = (string) $sede->get('field_telefono')->value;
  $sede->set('field_telefono', '0110000000')->save();

  $archivioDelibere->resetCache();
  $conCambio = $archivioDelibere->load($unanime->id());
  $esportazioneDopo = $verbalizzazione->esportaAtto($conCambio);

  EsitiAtto::verifica(
    'cambiare un recapito non altera un atto già sigillato',
    $esportazioneDopo === $esportazione
  );
  EsitiAtto::verifica(
    "né la sua impronta",
    $costruttoreAtto->verifica($conCambio, $verbale)['integro']
  );

  $sede->set('field_telefono', $telefonoOriginale)->save();
}
else {
  EsitiAtto::verifica('nessuna sede legale: si ripiega sul nome del sito', TRUE);
  EsitiAtto::verifica('e l\'atto resta producibile', TRUE);
}

echo "\n[8] Documento dell'estratto\n";
$documento = $verbalizzazione->documentoAttoHtml($unanime, $verbale);
// Le entità vanno decodificate: il documento è HTML, e gli apostrofi vi
// compaiono legittimamente come &#039;.
$testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($documento), ENT_QUOTES, 'UTF-8')));
foreach ([
  'il numero di delibera' => 'Delibera n. 35',
  "l'oggetto dell'atto" => 'Piano Annuale per l\'Inclusione 2025-2026',
  "la denominazione dell'organo" => 'Collegio dei docenti',
  'le premesse' => 'Visto il D.lgs. 66/2017',
  'il dispositivo' => 'Approva il Piano Annuale per l\'Inclusione (PAI) 2025/2026',
  'la proclamazione' => "Il Collegio dei docenti approva all'unanimità con la seguente votazione:",
  'il prospetto della votazione' => 'Aventi diritto',
  "l'intestazione dell'istituto" => $intestazione['istituto'],
  'la seduta di provenienza' => 'Collegio dei docenti',
  // «Il Collegio dei docenti,» apre il periodo che i visti proseguono: non è
  // un'etichetta ma il soggetto della frase, e la virgola lo dichiara.
  "l'organo come soggetto, con articolo e virgola" => 'Il Collegio dei docenti,',
] as $descrizione => $atteso) {
  EsitiAtto::verifica("il documento riporta $descrizione", str_contains($testo, $atteso));
}

// L'estratto è l'atto: il come e il perché della seduta li documenta il
// verbale. Restano però nell'esportazione, che è il record completo — chi
// verifica l'atto deve poter risalire a quesito, modalità e presidenza anche
// senza il verbale.
// Il nominativo si prende dall'esportazione e non si scrive a mano: un
// valore inventato non comparirebbe nel documento in nessun caso, e
// l'asserzione passerebbe senza verificare nulla.
foreach ([
  'il quesito posto ai voti' => $dati['atto']['quesito'],
  'il riferimento normativo' => 'prot. 3803',
  'la presidenza' => $dati['seduta']['presidente'],
] as $descrizione => $assente) {
  EsitiAtto::verifica(
    "il documento non riporta $descrizione, che è del verbale",
    !str_contains($testo, $assente)
  );
}
EsitiAtto::verifica(
  "ma il quesito resta nell'esportazione",
  ($dati['atto']['quesito'] ?? '') === 'Si approva il PAI 2025/2026?'
);
EsitiAtto::verifica(
  'e con esso il riferimento normativo e la presidenza',
  str_contains((string) ($dati['metadati']['riferimento_normativo'] ?? ''), 'prot. 3803')
    && ($dati['seduta']['presidente'] ?? '') !== ''
);
EsitiAtto::verifica(
  "e non si dichiara una bozza, perché è sigillato",
  !str_contains($testo, 'non ancora sigillato')
);

// Riferimenti e impronte sono usciti dal corpo dell'atto ma non dal
// documento: l'estratto circola senza il verbale, e chi lo riceve non ha
// altro con cui verificarlo. L'impronta dell'atto e quella dell'estratto
// esistono solo qui, il verbale porta le proprie.
foreach ([
  "l'impronta dell'atto" => (string) $unanime->get('impronta_atto')->value,
  "il sigillo dell'urna" => (string) $unanime->get('sigillo_urna')->value,
  'il verbale da cui è tratto' => $verbale->uuid(),
  'la sua impronta' => (string) $verbale->get('impronta_contenuto')->value,
] as $descrizione => $atteso) {
  EsitiAtto::verifica("il piè di pagina conserva $descrizione", str_contains($testo, $atteso));
}

echo "\n[9] Immodificabilità dopo il sigillo\n";
EsitiAtto::bloccata(
  'un atto sigillato non accetta più modifiche',
  static function () use ($unanime): void {
    $unanime->set('dispositivo', ['value' => 'Testo sostituito.', 'format' => 'plain_text'])->save();
  },
  \LogicException::class
);

$commutatore->switchTo(User::load(1));
EsitiAtto::bloccata(
  "nemmeno l'amministratore può modificarlo",
  static function () use ($unanime): void {
    $unanime->set('numero_delibera', '99')->save();
  },
  \LogicException::class
);
EsitiAtto::verifica(
  "e la redazione è negata anche a chi l'aveva redatto",
  !$controllo->access($unanime, $utenti[1])->isAllowed()
);
ProvaPsiphos::ripristinaUtenza($commutatore);

echo "\n--- " . EsitiAtto::$superate . ' superate, ' . EsitiAtto::$fallite . " fallite ---\n";

ProvaPsiphos::ripulisci();
$ruolo->delete();
echo "pulizia completata\n";
