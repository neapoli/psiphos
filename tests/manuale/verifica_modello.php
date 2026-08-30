<?php

/**
 * @file
 * Verifica funzionale del modello dati di Psíphos.
 *
 * Non è una suite PHPUnit: è uno script ripetibile da lanciare sull'ambiente
 * di sviluppo per controllare che la macchina a stati, i quorum e i vincoli
 * di immodificabilità si comportino come previsto dall'allegato tecnico.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_modello.php
 *
 * Crea e rimuove i propri dati di prova: gli utenti hanno prefisso
 * "psiphos_prova_" e vengono eliminati sia all'inizio sia alla fine, così
 * un'interruzione a metà non impedisce il rilancio.
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
use Drupal\psiphos\Exception\TransizioneNonAmmessaException;
use Drupal\psiphos\Exception\VotoNonAmmessoException;
use Drupal\user\Entity\User;

/**
 * Contatore degli esiti, in proprietà statiche perché drush php:script
 * esegue il file dentro una funzione e le variabili globali non si agganciano.
 */
final class Esiti {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }

  /**
   * Verifica che l'azione sia impedita da un'eccezione della classe attesa.
   *
   * Drupal incapsula in EntityStorageException le eccezioni sollevate da
   * preSave(), perciò va risalita la catena delle eccezioni precedenti.
   */
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

/** Rimuove utenti ed entità di prova rimasti da esecuzioni interrotte. */
/** Rimuove i soli dati creati da questa verifica. */
$ripulisci = static fn () => ProvaPsiphos::ripulisci();

ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
$ripulisci();

$utenti = [];
for ($indice = 1; $indice <= 7; $indice++) {
  $utente = User::create([
    'name' => "psiphos_prova_$indice",
    'mail' => "psiphos_prova_$indice@example.test",
    'status' => 1,
  ]);
  $utente->save();
  $utenti[] = $utente;
}

echo "\n[1] Seduta e quorum costitutivo\n";
$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti di prova'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '1/2026-27',
  'anno_scolastico' => '2026/27',
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
]);
$seduta->save();
Esiti::verifica('stato iniziale = convocata', $seduta->stato() === StatoSeduta::CONVOCATA);
Esiti::verifica("organo ricade nella lett. a) dell'art. 44", $seduta->organo()->letteraArt44() === 'a');

foreach ($utenti as $utente) {
  Presenza::create(['seduta' => $seduta->id(), 'utente' => $utente->id()])->save();
}
Esiti::verifica('aventi diritto = 7', $seduta->numeroAventiDiritto() === 7);
Esiti::verifica('quorum costitutivo non raggiunto con 0 presenti', !$seduta->validamenteCostituita());

$entrati = 0;
foreach ($gestoreEntita->getStorage('psiphos_presenza')->loadByProperties(['seduta' => $seduta->id()]) as $presenza) {
  if ($entrati++ >= 5) {
    break;
  }
  $presenza->set('stato', StatoPresenza::PRESENTE->value)
    ->set('ingresso', \Drupal::time()->getRequestTime())
    ->save();
}
Esiti::verifica('presenti = 5', $seduta->numeroPresenti() === 5);
Esiti::verifica('quorum costitutivo raggiunto, 5 su una soglia di 4', $seduta->validamenteCostituita());

echo "\n[2] Macchina a stati della seduta\n";
Esiti::bloccata(
  'salto da convocata a verbalizzata impedito',
  static fn () => $seduta->transitaA(StatoSeduta::VERBALIZZATA),
  TransizioneNonAmmessaException::class
);
$seduta->transitaA(StatoSeduta::APERTA)->save();
Esiti::verifica('seduta aperta', $seduta->stato() === StatoSeduta::APERTA);
Esiti::verifica("aventi diritto congelati all'apertura = 7", $seduta->aventiDirittoAllApertura() === 7);

Presenza::create(['seduta' => $seduta->id(), 'utente' => $utenti[6]->id()])->save();
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
Esiti::verifica("l'elenco corrente cresce a 8", $seduta->numeroAventiDiritto() === 8);
Esiti::verifica('il denominatore dei quorum resta congelato a 7', $seduta->aventiDirittoAllApertura() === 7);

echo "\n[3] Scrittura diretta sullo stato, aggirando transitaA()\n";
Esiti::bloccata('preSave impedisce il ritorno a uno stato precedente', static function () use ($seduta): void {
  $seduta->set('stato', StatoSeduta::CONVOCATA->value);
  $seduta->save();
}, TransizioneNonAmmessaException::class);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
Esiti::verifica('lo stato in banca dati è rimasto "aperta"', $seduta->stato() === StatoSeduta::APERTA);

echo "\n[4] Punto all'ordine del giorno e delibera\n";
$punto = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 1,
  'oggetto' => 'Approvazione del PTOF',
  'deliberativo' => TRUE,
]);
$punto->save();
$delibera = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva il PTOF 2026/29?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$delibera->save();
Esiti::verifica('seduta derivata dal punto', (int) $delibera->get('seduta')->target_id === (int) $seduta->id());
Esiti::verifica('stato iniziale = predisposta', $delibera->stato() === StatoDelibera::PREDISPOSTA);
Esiti::verifica("l'urna è chiusa", !$delibera->urnaAperta());

echo "\n[5] Sospensione, immodificabilità del criterio, ripetizione (§8)\n";
$delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
Esiti::verifica("l'urna è aperta", $delibera->urnaAperta());
Esiti::verifica('presenti congelati al voto = 5', (int) $delibera->get('presenti_al_voto')->value === 5);
Esiti::verifica('aventi diritto al voto = 7', (int) $delibera->get('aventi_diritto_al_voto')->value === 7);
Esiti::bloccata(
  'sospensione senza motivazione rifiutata',
  static fn () => $delibera->transitaA(StatoDelibera::SOSPESA),
  \InvalidArgumentException::class
);
$delibera->transitaA(StatoDelibera::SOSPESA, 'Caduta della connessione per 4 aventi diritto.')->save();
Esiti::verifica(
  'votazione sospesa con motivazione registrata',
  $delibera->stato() === StatoDelibera::SOSPESA && str_contains((string) $delibera->get('motivazione')->value, 'connessione')
);
$delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
Esiti::verifica('votazione ripresa dalla sospensione', $delibera->stato() === StatoDelibera::IN_VOTAZIONE);
Esiti::bloccata('regola di maggioranza non modificabile a urna aperta', static function () use ($delibera): void {
  $delibera->set('regola_maggioranza', RegolaMaggioranza::DUE_TERZI_PRESENTI->value);
  $delibera->save();
}, \LogicException::class);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
Esiti::verifica('il criterio in banca dati è quello iniziale', $delibera->regolaMaggioranza() === RegolaMaggioranza::MAGGIORANZA_PRESENTI);

$delibera->transitaA(StatoDelibera::CHIUSA)->save();
Esiti::bloccata(
  'riapertura di un\'urna chiusa impedita',
  static fn () => $delibera->transitaA(StatoDelibera::IN_VOTAZIONE),
  TransizioneNonAmmessaException::class
);
$delibera->transitaA(StatoDelibera::ANNULLATA, 'Malfunzionamento rilevato dopo la chiusura.')->save();
$ripetizione = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva il PTOF 2026/29? (ripetizione)',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
  'ripetizione_di' => $delibera->id(),
]);
$ripetizione->save();
Esiti::verifica("l'esito annullato resta agli atti", $delibera->esito()?->value === 'annullata');
Esiti::verifica('la ripetizione riferisce la votazione annullata', (int) $ripetizione->ripetizioneDi()?->id() === (int) $delibera->id());

echo "\n[6] Calcolo delle maggioranze\n";
Esiti::verifica('semplice: 3 favorevoli contro 2 contrari, approvata', RegolaMaggioranza::MAGGIORANZA_VOTANTI->approvata(3, 2, 5, 7));
Esiti::verifica('semplice: 2 favorevoli contro 2 contrari, respinta', !RegolaMaggioranza::MAGGIORANZA_VOTANTI->approvata(2, 2, 5, 7));
Esiti::verifica('assoluta dei presenti su 5: soglia 3', RegolaMaggioranza::MAGGIORANZA_PRESENTI->sogliaFavorevoli(5, 7) === 3);
Esiti::verifica('assoluta degli aventi diritto su 7: soglia 4', RegolaMaggioranza::MAGGIORANZA_AVENTI_DIRITTO->sogliaFavorevoli(5, 7) === 4);
Esiti::verifica('due terzi dei presenti su 5: soglia 4', RegolaMaggioranza::DUE_TERZI_PRESENTI->sogliaFavorevoli(5, 7) === 4);
Esiti::verifica('due terzi con 3 favorevoli su 5 presenti, respinta', !RegolaMaggioranza::DUE_TERZI_PRESENTI->approvata(3, 2, 5, 7));

echo "\n[7] Controllo di accesso\n";
Esiti::verifica('cancellazione vietata su seduta aperta', $seduta->access('delete', $utenti[0], TRUE)->isForbidden());
$estraneo = User::create(['name' => 'psiphos_prova_estraneo', 'mail' => 'psiphos_prova_estraneo@example.test', 'status' => 1]);
$estraneo->save();
Esiti::verifica('un estraneo senza permessi non vede la seduta', !$seduta->access('view', $estraneo));

echo "\n[8] Struttura della scheda\n";
$puntoDesignazione = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 2,
  'oggetto' => 'Designazione del referente per l\'inclusione',
  'deliberativo' => TRUE,
]);
$puntoDesignazione->save();

// Scheda di approvazione: voci fisse, nessuna opzione ammessa.
$approvazione = Delibera::create([
  'punto_odg' => $puntoDesignazione->id(),
  'quesito' => 'Si approva la proposta?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'schema_scheda' => SchemaScheda::APPROVAZIONE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$approvazione->save();
Esiti::verifica(
  'scheda di approvazione: tre voci fisse',
  array_keys($approvazione->vociScheda()) === ['favorevole', 'contrario', 'astenuto']
);
Esiti::bloccata('opzioni rifiutate su scheda di approvazione', static function () use ($approvazione): void {
  $approvazione->set('opzioni', ['Rossi', 'Bianchi']);
  $approvazione->save();
}, \InvalidArgumentException::class);
$approvazione = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($approvazione->id());

// Scheda a scelta singola.
$sceltaSingola = Delibera::create([
  'punto_odg' => $puntoDesignazione->id(),
  'quesito' => 'Chi si designa come referente?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Rossi', 'Bianchi', 'Verdi'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$sceltaSingola->save();
$voci = $sceltaSingola->vociScheda();
Esiti::verifica('scheda a scelta: 3 opzioni più la scheda bianca', count($voci) === 4);
Esiti::verifica('chiavi tecniche stabili sulla scheda', array_keys($voci) === ['opzione_1', 'opzione_2', 'opzione_3', 'scheda_bianca']);
Esiti::verifica("l'urna conserva la chiave, non il testo dell'opzione", $voci['opzione_2'] === 'Bianchi');
Esiti::verifica('scheda bianca sempre disponibile', isset($voci[SchemaScheda::VOCE_SCHEDA_BIANCA]));

Esiti::bloccata('scheda a scelta con una sola opzione rifiutata', static function () use ($puntoDesignazione): void {
  Delibera::create([
    'punto_odg' => $puntoDesignazione->id(),
    'quesito' => 'Quesito incompleto',
    'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
    'opzioni' => ['Unica'],
  ])->save();
}, \InvalidArgumentException::class);

Esiti::bloccata('opzioni duplicate rifiutate', static function () use ($puntoDesignazione): void {
  Delibera::create([
    'punto_odg' => $puntoDesignazione->id(),
    'quesito' => 'Quesito con duplicati',
    'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
    'opzioni' => ['Rossi', 'Rossi'],
  ])->save();
}, \InvalidArgumentException::class);

Esiti::bloccata('preferenze pari al numero di opzioni rifiutate', static function () use ($puntoDesignazione): void {
  Delibera::create([
    'punto_odg' => $puntoDesignazione->id(),
    'quesito' => 'Quesito senza selettività',
    'schema_scheda' => SchemaScheda::SCELTA_MULTIPLA->value,
    'opzioni' => ['Rossi', 'Bianchi'],
    'preferenze_massime' => 2,
  ])->save();
}, \InvalidArgumentException::class);

$sceltaMultipla = Delibera::create([
  'punto_odg' => $puntoDesignazione->id(),
  'quesito' => 'Si designano due componenti su quattro',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_MULTIPLA->value,
  'opzioni' => ['Rossi', 'Bianchi', 'Verdi', 'Neri'],
  'preferenze_massime' => 2,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$sceltaMultipla->save();
Esiti::verifica('scelta multipla: 2 preferenze su 4 opzioni', $sceltaMultipla->preferenzeMassime() === 2);
Esiti::verifica('su scheda singola le preferenze restano 1', $sceltaSingola->preferenzeMassime() === 1);

// La scheda si blocca all'apertura dell'urna, come il criterio di conteggio.
$sceltaSingola->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
Esiti::bloccata('opzioni non modificabili a urna aperta', static function () use ($sceltaSingola): void {
  $sceltaSingola->set('opzioni', ['Rossi', 'Bianchi', 'Gialli']);
  $sceltaSingola->save();
}, \LogicException::class);
Esiti::bloccata('struttura della scheda non modificabile a urna aperta', static function () use ($sceltaSingola): void {
  $sceltaSingola->set('schema_scheda', SchemaScheda::APPROVAZIONE->value);
  $sceltaSingola->save();
}, \LogicException::class);
$sceltaSingola = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($sceltaSingola->id());
Esiti::verifica('le opzioni in banca dati sono quelle iniziali', $sceltaSingola->opzioni() === ['Rossi', 'Bianchi', 'Verdi']);

// Il conteggio usa una sola rappresentazione per ogni struttura di scheda.
$sceltaSingola->set('conteggio', ['opzione_1' => 3, 'opzione_2' => 1, 'opzione_3' => 0, 'scheda_bianca' => 1]);
$sceltaSingola->save();
$sceltaSingola = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($sceltaSingola->id());
Esiti::verifica('conteggio riletto correttamente', $sceltaSingola->conteggio()['opzione_1'] === 3);
Esiti::verifica('conteggio completo di tutte le voci', count($sceltaSingola->conteggio()) === 4);

// La regola di maggioranza vale anche sulle schede a scelta: favorevoli sono
// i voti dell'opzione in testa, contrari tutti gli altri voti validi.
Esiti::verifica(
  'opzione in testa con 3 voti su 5 presenti raggiunge la maggioranza assoluta',
  RegolaMaggioranza::MAGGIORANZA_PRESENTI->approvata(3, 2, 5, 7)
);
Esiti::verifica(
  "l'esito si legge diversamente su una scheda a scelta",
  \Drupal\psiphos\Enum\EsitoDelibera::RESPINTA->etichettaPer(SchemaScheda::SCELTA_SINGOLA)
    !== \Drupal\psiphos\Enum\EsitoDelibera::RESPINTA->etichettaPer(SchemaScheda::APPROVAZIONE)
);

echo "\n[8] Form di redazione\n";
// Percorso reale: costruzione, validazione e salvataggio attraverso il
// costruttore di form. Le chiamate dirette ai metodi non lo esercitano, ed è
// esattamente qui che un errore si manifesta come schermata di errore.
$riferimentoUtente = static fn (\Drupal\user\UserInterface $u): string => sprintf('%s (%d)', $u->getAccountName(), $u->id());

$sottometti = static function (string $tipo, \Drupal\Core\Entity\ContentEntityInterface $entita, array $valori, array $elementiMultipli = []): array {
  $form = \Drupal::entityTypeManager()->getFormObject($tipo, 'add');
  $form->setEntity($entita);
  $statoForm = new \Drupal\Core\Form\FormState();
  $statoForm->setValues($valori);

  // I campi a cardinalità illimitata costruiscono un solo elemento: i
  // successivi compaiono nel browser premendo «aggiungi un altro elemento».
  // Fuori dal browser vanno dichiarati prima, altrimenti i valori oltre il
  // primo non trovano un campo in cui essere raccolti.
  foreach ($elementiMultipli as $campo => $quantita) {
    \Drupal\Core\Field\WidgetBase::setWidgetState([], $campo, $statoForm, ['items_count' => $quantita]);
  }

  \Drupal::formBuilder()->submitForm($form, $statoForm);

  return ['form' => $form, 'stato' => $statoForm, 'errori' => $statoForm->getErrors()];
};

$valoriSeduta = [
  'titolo' => [['value' => ProvaPsiphos::titolo('Seduta dal form')]],
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => [['value' => '2/2026-27']],
  'anno_scolastico' => [['value' => '2026/27']],
  'data_seduta' => [['value' => ['date' => '2026-09-02', 'time' => '09:00:00']]],
  'data_convocazione' => [['value' => ['date' => '', 'time' => '']]],
  'modalita' => 'distanza',
  'quorum_costitutivo' => 'meta_piu_uno',
  'presidente' => [['target_id' => $riferimentoUtente($utenti[0])]],
  'segretario' => [['target_id' => $riferimentoUtente($utenti[1])]],
  'riferimento_regolamento' => [['value' => "Art. 12-bis del Regolamento d'istituto"]],
  'note_procedurali' => [['value' => '', 'format' => NULL]],
  'url_videoconferenza' => [['value' => '']],
];

$esitoSeduta = NULL;
try {
  $esitoSeduta = $sottometti('psiphos_seduta', Seduta::create([]), $valoriSeduta);
  Esiti::verifica('il form della seduta si costruisce e valida senza errori', $esitoSeduta['errori'] === []);
}
catch (\Throwable $errore) {
  Esiti::verifica('il form della seduta si costruisce e valida senza errori [' . get_class($errore) . ': ' . $errore->getMessage() . ']', FALSE);
}

if ($esitoSeduta !== NULL) {
  $sedutaDalForm = $esitoSeduta['form']->getEntity();
  $statoSalvataggio = new \Drupal\Core\Form\FormState();
  $esitoSeduta['form']->save([], $statoSalvataggio);
  Esiti::verifica('la seduta viene salvata', !$sedutaDalForm->isNew());
  Esiti::verifica(
    'e il salvataggio riporta alla pagina della seduta',
    $statoSalvataggio->getRedirect()?->toString() === $sedutaDalForm->toUrl()->toString()
  );

  $esitoPunto = $sottometti('psiphos_punto_odg', PuntoOdg::create([]), [
    'seduta' => [['target_id' => sprintf('%s (%d)', $sedutaDalForm->label(), $sedutaDalForm->id())]],
    'numero' => [['value' => 1]],
    'oggetto' => [['value' => 'Punto dal form']],
    'descrizione' => [['value' => '', 'format' => NULL]],
    'deliberativo' => 1,
  ]);
  Esiti::verifica('il form del punto valida senza errori', $esitoPunto['errori'] === []);
  $statoSalvataggio = new \Drupal\Core\Form\FormState();
  $esitoPunto['form']->save([], $statoSalvataggio);
  // L'entità popolata è quella del form, non quella passata a setEntity().
  $puntoDalForm = $esitoPunto['form']->getEntity();
  Esiti::verifica(
    'anche il punto riporta alla seduta',
    $statoSalvataggio->getRedirect()?->toString() === $sedutaDalForm->toUrl()->toString()
  );

  $riferimentoPunto = sprintf('%s (%d)', $puntoDalForm->label(), $puntoDalForm->id());
  $valoriDelibera = static fn (array $opzioni, string $schema): array => [
    'punto_odg' => [['target_id' => $riferimentoPunto]],
    'numero_delibera' => [['value' => '']],
    'quesito' => [['value' => 'Chi si designa?']],
    'tipo_voto' => TipoVoto::SEGRETO->value,
    'schema_scheda' => $schema,
    'opzioni' => array_map(static fn (string $o): array => ['value' => $o], $opzioni),
    'preferenze_massime' => [['value' => 1]],
    'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
    'motivazione' => [['value' => '', 'format' => NULL]],
  ];

  // Una scheda incoerente deve produrre un errore di validazione leggibile,
  // non un'eccezione: la validaScheda() dell'entità arriverebbe altrimenti
  // solo al salvataggio, dove diventa una schermata di errore.
  $incoerente = NULL;
  try {
    $incoerente = $sottometti('psiphos_delibera', Delibera::create([]), $valoriDelibera(['Solo uno'], 'scelta_singola'), ['opzioni' => 1]);
    Esiti::verifica('una scheda a scelta con una sola opzione è respinta in validazione', $incoerente['errori'] !== []);
    Esiti::verifica(
      "e l'errore è agganciato al campo delle opzioni",
      array_key_exists('opzioni', $incoerente['errori'])
    );
  }
  catch (\Throwable $errore) {
    Esiti::verifica('una scheda a scelta con una sola opzione è respinta in validazione [' . get_class($errore) . ']', FALSE);
    Esiti::verifica("e l'errore è agganciato al campo delle opzioni", FALSE);
  }

  // Le scelte che determinano come si conta stanno vicine e spiegate: si
  // fissano una volta sola e si bloccano all'apertura dell'urna.
  $formDelibera = $gestoreEntita->getFormObject('psiphos_delibera', 'add');
  $formDelibera->setEntity(Delibera::create([]));
  $costruzioneDelibera = \Drupal::formBuilder()->getForm($formDelibera);

  $pesi = [];
  foreach (['tipo_voto', 'schema_scheda', 'regola_maggioranza', 'opzioni', 'preferenze_massime'] as $campoDelibera) {
    $pesi[$campoDelibera] = $costruzioneDelibera[$campoDelibera]['#weight'] ?? 0;
  }
  $ordinati = $pesi;
  asort($ordinati);
  Esiti::verifica(
    'la maggioranza segue la struttura della scheda, e opzioni e preferenze restano insieme',
    array_keys($ordinati) === ['tipo_voto', 'schema_scheda', 'regola_maggioranza', 'opzioni', 'preferenze_massime']
  );
  Esiti::verifica('nessun peso è assegnato due volte', count(array_unique($pesi)) === count($pesi));

  $sceltaSenzaSpiegazione = [];
  foreach (['schema_scheda' => SchemaScheda::cases(), 'regola_maggioranza' => RegolaMaggioranza::cases()] as $campoScelta => $alternative) {
    foreach ($alternative as $alternativa) {
      if (($costruzioneDelibera[$campoScelta]['widget'][$alternativa->value]['#description'] ?? '') === '') {
        $sceltaSenzaSpiegazione[] = $campoScelta . '/' . $alternativa->value;
      }
    }
  }
  Esiti::verifica(
    'ogni alternativa porta la propria spiegazione'
      . ($sceltaSenzaSpiegazione === [] ? '' : ' [manca: ' . implode(', ', $sceltaSenzaSpiegazione) . ']'),
    $sceltaSenzaSpiegazione === []
  );

  $coerente = $sottometti('psiphos_delibera', Delibera::create([]), $valoriDelibera(['Rossi', 'Bianchi'], 'scelta_singola'), ['opzioni' => 2]);
  Esiti::verifica(
    'una scheda con due opzioni distinte passa la validazione'
      . ($coerente['errori'] === [] ? '' : ' [' . implode(' | ', array_map('strval', $coerente['errori'])) . ']'),
    $coerente['errori'] === []
  );
}



echo "\n[9] Comandi di redazione sull'ordine del giorno\n";
// Ciò su cui si è cominciato a votare non è più correggibile, e il divieto
// vale per chiunque, amministratore compreso: non è una questione di
// permessi ma di integrità dell'atto.
// Chi redige ha i permessi di convocazione: senza, il controllo di accesso
// nega correttamente e la sezione non proverebbe nulla.
$ruoloRedattore = \Drupal\user\Entity\Role::load('psiphos_prova_docente')
  ?? \Drupal\user\Entity\Role::create(['id' => 'psiphos_prova_docente', 'label' => 'Redattore di prova Psíphos']);
foreach (['psiphos convocare seduta', 'psiphos presiedere seduta', 'psiphos partecipare seduta'] as $permesso) {
  $ruoloRedattore->grantPermission($permesso);
}
$ruoloRedattore->save();
$utenti[0]->addRole('psiphos_prova_docente');
$utenti[0]->save();

$sedutaComandi = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Comandi di redazione'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaComandi->save();
Presenza::create([
  'seduta' => $sedutaComandi->id(),
  'utente' => $utenti[0]->id(),
  'stato' => StatoPresenza::PRESENTE->value,
  'ingresso' => \Drupal::time()->getRequestTime(),
  'ultima_attivita' => \Drupal::time()->getRequestTime(),
])->save();

$puntoInformativo = PuntoOdg::create(['seduta' => $sedutaComandi->id(), 'numero' => 1, 'oggetto' => 'Comunicazioni', 'deliberativo' => FALSE]);
$puntoInformativo->save();
$puntoVotato = PuntoOdg::create(['seduta' => $sedutaComandi->id(), 'numero' => 2, 'oggetto' => 'Approvazione']);
$puntoVotato->save();
$deliberaComandi = Delibera::create([
  'punto_odg' => $puntoVotato->id(),
  'quesito' => 'Si approva?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$deliberaComandi->save();

$azzeraAccessi = static function () use ($gestoreEntita): void {
  foreach (['psiphos_seduta', 'psiphos_punto_odg', 'psiphos_delibera'] as $tipo) {
    $gestoreEntita->getStorage($tipo)->resetCache();
    $gestoreEntita->getAccessControlHandler($tipo)->resetCache();
  }
};

$azzeraAccessi();
Esiti::verifica('un punto non ancora votato è modificabile', $puntoVotato->access('update', $utenti[0]));
Esiti::verifica('e cancellabile da chi redige, senza essere amministratore', $puntoVotato->access('delete', $utenti[0]));
Esiti::verifica('una delibera predisposta è modificabile', $deliberaComandi->access('update', $utenti[0]));

$sedutaComandi->transitaA(StatoSeduta::APERTA)->save();
$deliberaComandi->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$azzeraAccessi();
$puntoVotato = $gestoreEntita->getStorage('psiphos_punto_odg')->load($puntoVotato->id());
$deliberaComandi = $gestoreEntita->getStorage('psiphos_delibera')->load($deliberaComandi->id());
$puntoInformativo = $gestoreEntita->getStorage('psiphos_punto_odg')->load($puntoInformativo->id());

Esiti::verifica("aperta la votazione, la delibera non è più modificabile", !$deliberaComandi->access('update', $utenti[0]));
Esiti::verifica('né cancellabile', !$deliberaComandi->access('delete', $utenti[0]));
Esiti::verifica('e nemmeno il punto su cui si sta votando', !$puntoVotato->access('update', $utenti[0]));
Esiti::verifica('mentre un punto informativo resta correggibile', $puntoInformativo->access('update', $utenti[0]));

// Il divieto non è superabile con i permessi: nemmeno l'utenza uno passa.
$amministratore = \Drupal\user\Entity\User::load(1);
$azzeraAccessi();
Esiti::verifica(
  "il divieto vale anche per l'amministratore",
  !$deliberaComandi->access('update', $amministratore) && !$puntoVotato->access('update', $amministratore)
);

// Pulizia della seduta di questa sezione.
\Drupal::database()->delete('psiphos_audit')->condition('seduta', $sedutaComandi->id())->execute();
$deliberaComandi->delete();
$puntoVotato->delete();
$puntoInformativo->delete();
foreach ($gestoreEntita->getStorage('psiphos_presenza')->loadByProperties(['seduta' => $sedutaComandi->id()]) as $presenzaComandi) {
  $presenzaComandi->delete();
}
\Drupal::database()->update('psiphos_seduta')->fields(['stato' => 'convocata'])->condition('id', $sedutaComandi->id())->execute();
$gestoreEntita->getStorage('psiphos_seduta')->resetCache([$sedutaComandi->id()]);
$gestoreEntita->getStorage('psiphos_seduta')->load($sedutaComandi->id())->delete();



echo "\n[9-bis] Ripetizione di una votazione annullata (§8)\n";
// La ripetizione deve essere riconoscibile come tale: chiederlo a memoria a
// chi conduce una seduta significa non ottenerlo quasi mai, perciò il legame
// arriva precompilato dal collegamento sulla votazione annullata.
$sedutaRipetizione = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Ripetizione'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaRipetizione->save();
Presenza::create([
  'seduta' => $sedutaRipetizione->id(),
  'utente' => $utenti[0]->id(),
  'stato' => StatoPresenza::PRESENTE->value,
  'ingresso' => \Drupal::time()->getRequestTime(),
  'ultima_attivita' => \Drupal::time()->getRequestTime(),
])->save();
$sedutaRipetizione->transitaA(StatoSeduta::APERTA)->save();
$puntoRipetuto = PuntoOdg::create(['seduta' => $sedutaRipetizione->id(), 'numero' => 1, 'oggetto' => 'Designazione']);
$puntoRipetuto->save();
$annullata = Delibera::create([
  'punto_odg' => $puntoRipetuto->id(),
  'quesito' => 'Chi si designa?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$annullata->save();
$annullata->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$annullata->transitaA(StatoDelibera::ANNULLATA, 'Errore di calcolo del sistema nella proclamazione.')->save();

foreach (['psiphos_seduta', 'psiphos_punto_odg', 'psiphos_delibera'] as $tipoDaAzzerare) {
  $gestoreEntita->getStorage($tipoDaAzzerare)->resetCache();
  $gestoreEntita->getAccessControlHandler($tipoDaAzzerare)->resetCache();
}
// I comandi sono filtrati sull'accesso dell'utenza corrente: la verifica
// deve quindi guardare la pagina con gli occhi di chi presiede.
\Drupal::service('account_switcher')->switchTo($utenti[0]);
$resaRipetizione = (string) \Drupal::service('renderer')->renderRoot(
  $gestoreEntita->getViewBuilder('psiphos_seduta')->view(
    $gestoreEntita->getStorage('psiphos_seduta')->load($sedutaRipetizione->id())
  )
);
\Drupal::service('account_switcher')->switchBack();
Esiti::verifica(
  'la votazione annullata offre di predisporre la ripetizione',
  str_contains($resaRipetizione, 'Predisponi la ripetizione')
);
Esiti::verifica(
  'e il collegamento porta con sé il riferimento alla votazione annullata',
  str_contains($resaRipetizione, 'ripetizione_di=' . $annullata->id())
);

// Il modulo di redazione raccoglie i riferimenti dalla richiesta.
$richiestaRipetizione = \Symfony\Component\HttpFoundation\Request::create(
  '/admin/content/psiphos/delibera/aggiungi',
  'GET',
  ['punto_odg' => $puntoRipetuto->id(), 'ripetizione_di' => $annullata->id()]
);
$sessioneRipetizione = new \Symfony\Component\HttpFoundation\Session\Session(
  new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
);
$sessioneRipetizione->setId('verifica-ripetizione');
$richiestaRipetizione->setSession($sessioneRipetizione);
\Drupal::service('request_stack')->push($richiestaRipetizione);

$primaEntita = static function (mixed $valore): ?\Drupal\Core\Entity\EntityInterface {
  if (is_array($valore)) {
    $valore = reset($valore);
  }

  return $valore instanceof \Drupal\Core\Entity\EntityInterface ? $valore : NULL;
};
$formRipetizione = $gestoreEntita->getFormObject('psiphos_delibera', 'add');
$formRipetizione->setEntity(Delibera::create([]));
$costruzioneRipetizione = \Drupal::formBuilder()->getForm($formRipetizione);
Esiti::verifica(
  'il punto arriva precompilato',
  $primaEntita($costruzioneRipetizione['punto_odg']['widget'][0]['target_id']['#default_value'] ?? NULL)?->id() === $puntoRipetuto->id()
);
Esiti::verifica(
  'e con esso la votazione da ripetere',
  $primaEntita($costruzioneRipetizione['ripetizione_di']['widget'][0]['target_id']['#default_value'] ?? NULL)?->id() === $annullata->id()
);

\Drupal::database()->delete('psiphos_audit')->condition('seduta', $sedutaRipetizione->id())->execute();
$annullata->delete();
$puntoRipetuto->delete();
foreach ($gestoreEntita->getStorage('psiphos_presenza')->loadByProperties(['seduta' => $sedutaRipetizione->id()]) as $posizioneRipetizione) {
  $posizioneRipetizione->delete();
}
\Drupal::database()->update('psiphos_seduta')->fields(['stato' => 'convocata'])->condition('id', $sedutaRipetizione->id())->execute();
$gestoreEntita->getStorage('psiphos_seduta')->resetCache([$sedutaRipetizione->id()]);
$gestoreEntita->getStorage('psiphos_seduta')->load($sedutaRipetizione->id())->delete();

echo "\n[10] Resa dei testi redatti con un formato\n";
// I campi descrittivi conservano il testo così come è stato scritto, tag
// compresi: l'impronta del verbale si calcola su quello e deve restare
// ripetibile. La resa applica invece il formato con cui il testo è stato
// redatto, che è l'unico a sapere che cosa sia lecito in quel campo.
$sedutaTesti = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Resa dei testi'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaTesti->save();
$puntoTesti = PuntoOdg::create([
  'seduta' => $sedutaTesti->id(),
  'numero' => 1,
  'oggetto' => 'Punto con illustrazione',
  'descrizione' => ['value' => '<p>Bisogna votare sul punto</p>', 'format' => 'plain_text'],
]);
$puntoTesti->save();

$rendiSeduta = static function () use ($gestoreEntita, $sedutaTesti): string {
  $gestoreEntita->getStorage('psiphos_punto_odg')->resetCache();
  $gestoreEntita->getStorage('psiphos_seduta')->resetCache([$sedutaTesti->id()]);

  return (string) \Drupal::service('renderer')->renderRoot(
    $gestoreEntita->getViewBuilder('psiphos_seduta')->view(
      $gestoreEntita->getStorage('psiphos_seduta')->load($sedutaTesti->id())
    )
  );
};

$resa = $rendiSeduta();
Esiti::verifica(
  'il testo semplice mostra i marcatori come tali, non come tag attivi',
  str_contains($resa, '&lt;p&gt;Bisogna votare sul punto&lt;/p&gt;')
);
Esiti::verifica(
  "e non finisce nella pagina come marcatura eseguibile",
  !str_contains($resa, '<p>Bisogna votare sul punto</p>')
);

// Senza formato dichiarato il testo resta tale, con i soli a capo resi.
$puntoTesti->set('descrizione', ['value' => "Prima riga\nSeconda riga", 'format' => NULL])->save();
$resa = $rendiSeduta();
Esiti::verifica('senza formato dichiarato gli a capo diventano interruzioni di riga', str_contains($resa, 'Prima riga<br />'));

// Il valore conservato non è toccato dalla resa: è quello su cui si firma.
$puntoTesti->set('descrizione', ['value' => '<p>Testo integrale</p>', 'format' => 'plain_text'])->save();
$gestoreEntita->getStorage('psiphos_punto_odg')->resetCache([$puntoTesti->id()]);
$strutturaTesti = \Drupal::service('psiphos.costruttore_verbale')->strutturaCanonica(
  $gestoreEntita->getStorage('psiphos_seduta')->load($sedutaTesti->id())
);
Esiti::verifica(
  'la struttura canonica conserva il testo grezzo',
  $strutturaTesti['ordine_del_giorno'][0]['illustrazione'] === '<p>Testo integrale</p>'
);
Esiti::verifica(
  'e ne dichiara il formato accanto',
  $strutturaTesti['ordine_del_giorno'][0]['illustrazione_formato'] === 'plain_text'
);

\Drupal::database()->delete('psiphos_audit')->condition('seduta', $sedutaTesti->id())->execute();
$puntoTesti->delete();
$gestoreEntita->getStorage('psiphos_seduta')->load($sedutaTesti->id())->delete();



echo "\n[11] Composizione dell'elenco degli aventi diritto\n";
$sedutaElenco = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Elenco aventi diritto'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaElenco->save();

// Gli utenti di prova portano già il ruolo assegnato nella sezione 9.
foreach ($utenti as $utenteElenco) {
  if (!$utenteElenco->hasRole('psiphos_prova_docente')) {
    $utenteElenco->addRole('psiphos_prova_docente');
    $utenteElenco->save();
  }
}

$componiElenco = static function (array $valori) use ($sedutaElenco): void {
  $form = \Drupal\psiphos\Form\ElencoAventiDirittoForm::create(\Drupal::getContainer());
  $statoElenco = new \Drupal\Core\Form\FormState();
  $statoElenco->setValues($valori);
  $strutturaElenco = [];
  $form->buildForm($strutturaElenco, $statoElenco, $sedutaElenco);
  $form->submitForm($strutturaElenco, $statoElenco);
};
$aventiDiritto = static fn (): int => (int) \Drupal::database()->select('psiphos_presenza', 'p')
  ->condition('seduta', $sedutaElenco->id())
  ->countQuery()
  ->execute()
  ->fetchField();

// A elenco vuoto la tabella non ha valore predefinito e il costruttore di
// form le assegna la stringa vuota: chi la riceve non può assumere un array.
$elencoVuotoRetto = TRUE;
try {
  $componiElenco(['elenco' => '', 'utenti' => '', 'ruolo' => '']);
}
catch (\Throwable $erroreElenco) {
  $elencoVuotoRetto = FALSE;
}
Esiti::verifica('un elenco ancora vuoto non fa cadere il modulo', $elencoVuotoRetto);

$componiElenco(['elenco' => '', 'ruolo' => 'psiphos_prova_docente', 'utenti' => '']);
Esiti::verifica('un ruolo intero entra in elenco in un colpo solo', $aventiDiritto() === count($utenti));

// Un'utenza bloccata non può accedere, quindi non potrà mai essere presente:
// includerla gonfierebbe il denominatore dei quorum.
$bloccato = \Drupal\user\Entity\User::create([
  'name' => ProvaPsiphos::PREFISSO_UTENTI . 'bloccato',
  'mail' => ProvaPsiphos::PREFISSO_UTENTI . 'bloccato@example.test',
  'status' => 0,
  'roles' => ['psiphos_prova_docente'],
]);
$bloccato->save();
$primaDelBloccato = $aventiDiritto();
$componiElenco(['elenco' => '', 'ruolo' => 'psiphos_prova_docente', 'utenti' => '']);
Esiti::verifica("il caricamento per ruolo esclude le utenze bloccate", $aventiDiritto() === $primaDelBloccato);
$componiElenco(['elenco' => '', 'ruolo' => '', 'utenti' => [['target_id' => $bloccato->id()]]]);
Esiti::verifica("e nemmeno indicandola a mano viene aggiunta", $aventiDiritto() === $primaDelBloccato);

$selettore = \Drupal::service('plugin.manager.entity_reference_selection')->getInstance([
  'target_type' => 'user',
  'handler' => 'psiphos_utente_attivo',
]);
$proposti = array_keys($selettore->getReferenceableEntities(ProvaPsiphos::PREFISSO_UTENTI, 'CONTAINS', 50)['user'] ?? []);
Esiti::verifica(
  "l'autocompletamento non propone utenze bloccate",
  !in_array((int) $bloccato->id(), array_map('intval', $proposti), TRUE)
);

$componiElenco(['ruolo' => 'psiphos_prova_docente', 'utenti' => []]);
Esiti::verifica('ripetere l\'operazione non duplica nessuno', $aventiDiritto() === count($utenti));

$aggiunto = \Drupal\user\Entity\User::create([
  'name' => ProvaPsiphos::PREFISSO_UTENTI . 'aggiunto',
  'mail' => ProvaPsiphos::PREFISSO_UTENTI . 'aggiunto@example.test',
  'status' => 1,
]);
$aggiunto->save();
$componiElenco(['ruolo' => '', 'utenti' => [['target_id' => $aggiunto->id()]]]);
Esiti::verifica('si può aggiungere anche un singolo avente diritto', $aventiDiritto() === count($utenti) + 1);

$archivioPresenze = $gestoreEntita->getStorage('psiphos_presenza');
$posizioneAggiunta = reset($archivioPresenze->loadByProperties([
  'seduta' => $sedutaElenco->id(),
  'utente' => $aggiunto->id(),
]));
$componiElenco(['elenco' => [$posizioneAggiunta->id() => ['rimuovi' => 1]], 'ruolo' => '', 'utenti' => []]);
Esiti::verifica('chi non è ancora entrato può essere rimosso', $aventiDiritto() === count($utenti));

// Chi è entrato in aula ha lasciato traccia nel registro della seduta:
// toglierlo dall'elenco falserebbe il verbale.
$sedutaElenco->transitaA(StatoSeduta::APERTA)->save();
$posizioneInAula = reset($archivioPresenze->loadByProperties([
  'seduta' => $sedutaElenco->id(),
  'utente' => $utenti[0]->id(),
]));
$posizioneInAula->set('stato', StatoPresenza::PRESENTE->value)
  ->set('ingresso', \Drupal::time()->getRequestTime())
  ->save();
$componiElenco(['elenco' => [$posizioneInAula->id() => ['rimuovi' => 1]], 'ruolo' => '', 'utenti' => []]);
Esiti::verifica('chi è già in aula non è rimovibile', $aventiDiritto() === count($utenti));

// La casella di rimozione deve essere visibile: il tema disegna il controllo
// attraverso la sua etichetta, e un'etichetta nascosta lascia la cella vuota.
$posizioneAttesa = reset($archivioPresenze->loadByProperties([
  'seduta' => $sedutaElenco->id(),
  'utente' => $utenti[1]->id(),
]));
$resaElenco = (string) \Drupal::service('renderer')->renderRoot(
  \Drupal::formBuilder()->getForm('\Drupal\psiphos\Form\ElencoAventiDirittoForm', $sedutaElenco)
);
preg_match('#<label[^>]*for="edit-elenco-' . $posizioneAttesa->id() . '-rimuovi"[^>]*>#', $resaElenco, $etichettaRimozione);
Esiti::verifica(
  "la casella di rimozione porta un'etichetta visibile",
  isset($etichettaRimozione[0]) && !str_contains($etichettaRimozione[0], 'visually-hidden')
);
Esiti::verifica(
  'e un nome accessibile che dice chi si sta rimuovendo',
  str_contains($resaElenco, 'aria-label="Rimuovi ' . $utenti[1]->getAccountName())
);
Esiti::verifica(
  'chi è in aula non ha la casella ma la dicitura',
  !str_contains($resaElenco, 'elenco[' . $posizioneInAula->id() . '][rimuovi]') && str_contains($resaElenco, 'già in aula')
);

$modificheTracciate = \Drupal::database()->select('psiphos_audit', 'a')
  ->condition('seduta', $sedutaElenco->id())
  ->condition('evento', \Drupal\psiphos\Enum\EventoAudit::ELENCO_MODIFICATO->value)
  ->countQuery()
  ->execute()
  ->fetchField();
Esiti::verifica('ogni modifica all\'elenco è tracciata', (int) $modificheTracciate === 3);

\Drupal::database()->delete('psiphos_audit')->condition('seduta', $sedutaElenco->id())->execute();
foreach ($archivioPresenze->loadByProperties(['seduta' => $sedutaElenco->id()]) as $posizione) {
  $posizione->delete();
}
\Drupal::database()->update('psiphos_seduta')->fields(['stato' => 'convocata'])->condition('id', $sedutaElenco->id())->execute();
$gestoreEntita->getStorage('psiphos_seduta')->resetCache([$sedutaElenco->id()]);
$gestoreEntita->getStorage('psiphos_seduta')->load($sedutaElenco->id())->delete();



echo "\n[11-bis] Nominativi negli atti\n";
// Un atto amministrativo nomina le persone con cognome e nome, non con il
// loro identificativo tecnico.
$anagrafato = $utenti[3];
if ($anagrafato->hasField('field_cognome') && $anagrafato->hasField('field_nome')) {
  $anagrafato->set('field_cognome', 'Rossi')->set('field_nome', 'Maria')->save();
  Esiti::verifica(
    'con i campi anagrafici compilati si usa «Cognome Nome»',
    \Drupal\psiphos\Nominativo::perUtente($anagrafato) === 'Rossi Maria'
  );
  $anagrafato->set('field_cognome', NULL)->set('field_nome', NULL)->save();
}
Esiti::verifica(
  'senza anagrafica si ripiega sul nome visualizzato',
  \Drupal\psiphos\Nominativo::perUtente($utenti[3]) === $utenti[3]->getAccountName()
);
Esiti::verifica(
  'e un utente assente non produce una riga vuota',
  \Drupal\psiphos\Nominativo::perUtente(NULL) !== ''
);

echo "\n[12] Tema delle pagine\n";
// Nessuna pagina del modulo è destinata al pubblico: sono atti interni,
// visibili solo a chi ha un ruolo nella seduta.
$rotteDelModulo = [];
$senzaTemaAmministrativo = [];
foreach (\Drupal::service('router.route_provider')->getAllRoutes() as $nomeRotta => $rotta) {
  if (!str_starts_with($nomeRotta, 'psiphos.') && !str_starts_with($nomeRotta, 'entity.psiphos_')) {
    continue;
  }
  $rotteDelModulo[] = $nomeRotta;
  if (!$rotta->getOption('_admin_route')) {
    $senzaTemaAmministrativo[] = $nomeRotta;
  }
}
Esiti::verifica('il modulo espone le proprie rotte', count($rotteDelModulo) > 20);
Esiti::verifica(
  'tutte le pagine usano il tema di amministrazione'
    . ($senzaTemaAmministrativo === [] ? '' : ' [fuori: ' . implode(', ', $senzaTemaAmministrativo) . ']'),
  $senzaTemaAmministrativo === []
);
Esiti::verifica(
  "l'aula è compresa",
  \Drupal::service('router.route_provider')->getRouteByName('psiphos.aula')->getOption('_admin_route') === TRUE
);


echo "\n[Copertura regolamentare (§2, §8)]\n";
// «Senza copertura regolamentare la deliberazione è impugnabile» lo dice la
// descrizione del campo. Se il campo resta facoltativo, quella frase è un
// consiglio che si può ignorare, e l'attestazione dichiara un fatto che non è
// garantito.
$senzaRegolamento = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Seduta priva di copertura'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'data_convocazione' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$mancanti = [];
foreach ($senzaRegolamento->validate() as $violazione) {
  $mancanti[] = $violazione->getPropertyPath();
}
Esiti::verifica(
  "il riferimento al Regolamento d'istituto è obbligatorio",
  in_array('riferimento_regolamento', $mancanti, TRUE)
);
$senzaRegolamento->set('riferimento_regolamento', "Art. 12-bis del Regolamento d'istituto");
Esiti::verifica(
  'indicato il riferimento, la convocazione è valida',
  count($senzaRegolamento->validate()) === 0
);

echo "\n[Chiusura dei lavori con votazioni pendenti (§8)]\n";
// Una seduta chiusa con l'urna aperta non è uno stato raggiungibile per una
// strada anziché per un'altra: è uno stato che non deve esistere. Il divieto
// stava nel solo banco di presidenza, e per ogni altra strada la seduta si
// chiudeva lasciando la votazione aperta — dove si continuava a votare.
$sedutaPendente = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Chiusura con votazione pendente'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'data_convocazione' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
]);
$sedutaPendente->save();
Presenza::create([
  'seduta' => $sedutaPendente->id(),
  'utente' => $utenti[0]->id(),
  'stato' => StatoPresenza::PRESENTE->value,
  'ingresso' => \Drupal::time()->getRequestTime(),
  'ultima_attivita' => \Drupal::time()->getRequestTime(),
])->save();
$sedutaPendente->transitaA(StatoSeduta::APERTA)->save();
$puntoPendente = PuntoOdg::create(['seduta' => $sedutaPendente->id(), 'numero' => 1, 'oggetto' => 'Punto']);
$puntoPendente->save();
$votazionePendente = Delibera::create([
  'punto_odg' => $puntoPendente->id(),
  'quesito' => 'Si approva?',
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$votazionePendente->save();
$votazionePendente->transitaA(StatoDelibera::IN_VOTAZIONE)->save();

Esiti::bloccata(
  'la seduta non si chiude mentre una votazione è aperta',
  static fn () => $sedutaPendente->transitaA(StatoSeduta::CHIUSA)->save(),
  TransizioneNonAmmessaException::class
);

$votazionePendente->transitaA(StatoDelibera::SOSPESA, 'Collegamento interrotto.')->save();
Esiti::bloccata(
  'né mentre una votazione è sospesa',
  static fn () => $sedutaPendente->transitaA(StatoSeduta::CHIUSA)->save(),
  TransizioneNonAmmessaException::class
);

// Difesa in profondità: se una seduta chiusa con urna aperta esistesse
// comunque, la scheda non dovrebbe entrarci.
$votazionePendente->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
\Drupal::database()->update('psiphos_seduta')
  ->fields(['stato' => StatoSeduta::CHIUSA->value])
  ->condition('id', $sedutaPendente->id())
  ->execute();
\Drupal::entityTypeManager()->getStorage('psiphos_seduta')->resetCache([$sedutaPendente->id()]);
\Drupal::entityTypeManager()->getStorage('psiphos_delibera')->resetCache([$votazionePendente->id()]);
Esiti::bloccata(
  'e su una seduta chiusa la scheda è respinta comunque',
  static fn () => \Drupal::service('psiphos.urna')->deposita(
    \Drupal::entityTypeManager()->getStorage('psiphos_delibera')->loadUnchanged($votazionePendente->id()),
    $utenti[0],
    [SchemaScheda::VOCE_FAVOREVOLE]
  ),
  VotoNonAmmessoException::class
);

$votazioneChiusa = \Drupal::entityTypeManager()->getStorage('psiphos_delibera')->loadUnchanged($votazionePendente->id());
Esiti::verifica(
  'e nessuna scheda è entrata',
  \Drupal::service('psiphos.urna')->numeroVotanti($votazioneChiusa) === 0
);

echo "\n[Designati fuori dall'elenco degli aventi diritto]\n";
// L'elenco è la fonte del diritto di voto: chi non vi figura non vota,
// presidente o segretario che sia. La separazione è voluta — il verbalizzante
// può essere un amministrativo che non compone l'organo — ma nel Consiglio di
// classe il coordinatore che presiede è quasi sempre docente della classe, e
// la dimenticanza si scopre solo quando prova a votare e non può.
$sedutaDesignati = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Consiglio della 3A'),
  'organo' => TipoOrgano::CONSIGLIO_CLASSE->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto",
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaDesignati->save();
Presenza::create(['seduta' => $sedutaDesignati->id(), 'utente' => $utenti[2]->id()])->save();

$elencoReso = static function () use ($sedutaDesignati): string {
  return html_entity_decode(
    strip_tags((string) \Drupal::service('renderer')->renderInIsolation(
      \Drupal::formBuilder()->getForm('Drupal\psiphos\Form\ElencoAventiDirittoForm', $sedutaDesignati)
    )),
    ENT_QUOTES,
    'UTF-8'
  );
};

$reso = preg_replace('/\s+/', ' ', $elencoReso());
Esiti::verifica(
  "l'elenco avverte quando presidente e segretario non vi figurano",
  str_contains($reso, 'Il Presidente designato e il segretario verbalizzante designato non figurano')
);
Esiti::verifica(
  "e ne spiega la conseguenza, invece di limitarsi a segnalarlo",
  str_contains($reso, 'non potranno votare e non concorreranno ai quorum')
);

Presenza::create(['seduta' => $sedutaDesignati->id(), 'utente' => $utenti[0]->id()])->save();
$reso = preg_replace('/\s+/', ' ', $elencoReso());
Esiti::verifica(
  'aggiunto il Presidente, resta segnalato il solo segretario',
  str_contains($reso, 'Il segretario verbalizzante designato non figura')
    && !str_contains($reso, 'Il Presidente designato')
);

Presenza::create(['seduta' => $sedutaDesignati->id(), 'utente' => $utenti[1]->id()])->save();
Esiti::verifica(
  "aggiunti entrambi, l'avviso sparisce",
  !str_contains(preg_replace('/\s+/', ' ', $elencoReso()), 'non figura')
);

$sedutaDesignati->delete();

printf("\n--- %d superate, %d fallite ---\n", Esiti::$superate, Esiti::$fallite);

$ripulisci();
echo "pulizia completata\n";
