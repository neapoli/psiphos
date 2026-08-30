<?php

/**
 * @file
 * Verifica dell'attestazione di conformità di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_conformita.php
 *
 * Copre il §9 dell'allegato tecnico: la verifica preventiva di coerenza della
 * soluzione ai requisiti presuppone che l'attestazione rifletta la
 * configurazione in essere e non un elenco di intenzioni.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Service\AttestazioneConformita;

final class EsitiConformita {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }
}

/**
 * Ricerca insensibile a maiuscole e agli a capo.
 *
 * I documenti sono testo a scorrimento con righe spezzate a settanta
 * colonne: cercare una frase così com'è scritta significherebbe far
 * dipendere l'esito della verifica da dove va a capo il paragrafo.
 */
function contiene(string $testo, string $frase): bool {
  $normalizza = static fn (string $t): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', $t));

  return str_contains($normalizza($testo), $normalizza($frase));
}

$attestatore = \Drupal::service('psiphos.attestazione_conformita');
// Questa verifica modifica la configurazione del modulo e la ripristina
// alla fine: interrotta a metà, la lascia alterata.
ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);

$configurazione = \Drupal::configFactory();
$originale = $configurazione->get('psiphos.settings')->getRawData();

/** Ripristina la configurazione di partenza. */
$ripristina = static function () use ($configurazione, $originale): void {
  $modificabile = $configurazione->getEditable('psiphos.settings');
  foreach (['autenticazione', 'sessione', 'conservazione', 'audit'] as $sezione) {
    $modificabile->set($sezione, $originale[$sezione]);
  }
  $modificabile->save();
};

echo "\n[1] Copertura dell'allegato tecnico\n";
$attestazione = $attestatore->attestazione();
$requisiti = $attestazione['requisiti'];
EsitiConformita::verifica('attestazione prodotta', $attestazione['formato'] === 'psiphos-conformita-v1');
EsitiConformita::verifica('istituzione indicata', $attestazione['istituzione'] !== '');
EsitiConformita::verifica('versione del modulo indicata', $attestazione['prodotto']['versione'] !== 'n.d.');

$paragrafi = array_unique(array_column($requisiti, 'paragrafo'));
foreach (['§2', '§3.1', '§3.2', '§3.3', '§3.4', '§4.1', '§4.2', '§4.3', '§5', '§6', '§7', '§8', '§9'] as $atteso) {
  EsitiConformita::verifica(sprintf('coperto il %s', $atteso), in_array($atteso, $paragrafi, TRUE));
}

$completi = TRUE;
foreach ($requisiti as $requisito) {
  foreach (['paragrafo', 'titolo', 'richiesta', 'attuazione', 'responsabilita', 'stato'] as $campo) {
    if (trim((string) $requisito[$campo]) === '') {
      $completi = FALSE;
    }
  }
}
EsitiConformita::verifica('ogni requisito è compilato in ogni sua parte', $completi);
EsitiConformita::verifica('il riepilogo torna con i requisiti', array_sum($attestazione['riepilogo']) === count($requisiti));

echo "\n[2] Ciò che il modulo non copre è dichiarato\n";
$aCarico = array_filter($requisiti, static fn (array $r): bool => $r['stato'] === AttestazioneConformita::STATO_A_CARICO);
EsitiConformita::verifica('esistono requisiti dichiarati a carico dell\'istituzione', $aCarico !== []);
$titoliACarico = array_column($aCarico, 'titolo');
foreach (['Cifratura dei dati', 'Segregazione degli ambienti', "Valutazione d'impatto", "Regolamento d'istituto"] as $atteso) {
  EsitiConformita::verifica(
    sprintf('dichiarato a carico: %s', $atteso),
    (bool) array_filter($titoliACarico, static fn (string $t): bool => str_contains($t, $atteso))
  );
}
$conNota = array_filter($aCarico, static fn (array $r): bool => trim($r['nota']) !== '');
EsitiConformita::verifica(
  'i requisiti a carico dicono anche come attuarli',
  count($conNota) >= count($aCarico) - 1
);

echo "\n[3] L'attestazione segue la configurazione\n";
$statoDi = static function (string $titolo) use ($attestatore): string {
  foreach ($attestatore->requisiti() as $requisito) {
    if (str_contains($requisito['titolo'], $titolo)) {
      return $requisito['stato'];
    }
  }
  return '';
};

// Il §3.2 non segue più la sola impostazione: segue quel che risulta erogato.
// Su un sito senza moduli di autenticazione, ogni livello superiore al minimo
// è un rilievo — ed è il comportamento voluto, perché dichiarare un secondo
// fattore che nessuno eroga è la sola condizione peggiore del non averlo.
$moduliAutenticazione = \Drupal::moduleHandler();
$erogatoreAttivo = FALSE;
foreach (['tfa', 'two_factor_authentication', 'google_authenticator_login', 'mfa', 'webauthn'] as $modulo) {
  $erogatoreAttivo = $erogatoreAttivo || $moduliAutenticazione->moduleExists($modulo);
}

EsitiConformita::verifica(
  'il §3.2 riflette se il livello dichiarato sia davvero erogato',
  $statoDi('Autenticazione') === ($erogatoreAttivo
    ? AttestazioneConformita::STATO_ATTUATO
    : AttestazioneConformita::STATO_ATTENZIONE)
);
$configurazione->getEditable('psiphos.settings')->set('autenticazione.livello', 'account')->save();
EsitiConformita::verifica(
  'il livello minimo resta comunque un rilievo',
  $statoDi('Autenticazione') === AttestazioneConformita::STATO_ATTENZIONE
);
$ripristina();

$configurazione->getEditable('psiphos.settings')->set('sessione.sessione_esclusiva', FALSE)->save();
EsitiConformita::verifica(
  'disattivata la sessione esclusiva, il §3.4 passa a «da verificare»',
  $statoDi('Gestione delle sessioni') === AttestazioneConformita::STATO_ATTENZIONE
);
$ripristina();

$configurazione->getEditable('psiphos.settings')->set('conservazione.pdfa_attivo', FALSE)->save();
EsitiConformita::verifica(
  'disattivato il PDF/A, il formato di conservazione è un rilievo',
  $statoDi('Formato di conservazione') === AttestazioneConformita::STATO_ATTENZIONE
);

// L'impedimento va nominato: chiedere all'hosting di installare Ghostscript
// e chiedergli di riabilitare l'esecuzione di processi sono richieste
// diverse, e su hosting condiviso la seconda spesso non viene concessa.
$conservazione = \Drupal::service('psiphos.conservazione_documento');
EsitiConformita::verifica(
  'la disattivazione è dichiarata come tale',
  contiene((string) $conservazione->impedimento(), 'disattivata nelle impostazioni')
);
$ripristina();
$configurazione->getEditable('psiphos.settings')->set('conservazione.ghostscript', '/percorso/inesistente/gs')->save();
EsitiConformita::verifica(
  'un percorso errato è dichiarato come tale',
  contiene((string) \Drupal::service('psiphos.conservazione_documento')->impedimento(), 'non è stato trovato')
);
$ripristina();
EsitiConformita::verifica(
  'ripristinato il percorso, nessun impedimento',
  \Drupal::service('psiphos.conservazione_documento')->impedimento() === NULL
);

// Senza riga di comando non c'è modo di cercare l'eseguibile a mano: il
// modulo interroga il sistema e ripiega su un elenco di percorsi noti.
$trovato = \Drupal::service('psiphos.conservazione_documento')->cercaGhostscript();
EsitiConformita::verifica(
  'la ricerca automatica individua Ghostscript',
  $trovato !== NULL && is_executable($trovato)
);
$configurazione->getEditable('psiphos.settings')->set('conservazione.pdfa_attivo', FALSE)->save();
$requisitoConservazione = array_values(array_filter($attestatore->requisiti(), static fn (array $r): bool => str_contains($r['titolo'], 'Formato di conservazione')))[0];
EsitiConformita::verifica(
  'e lo dichiara esplicitamente nel testo',
  str_contains($requisitoConservazione['attuazione'], 'PDF ordinario')
);
$ripristina();
EsitiConformita::verifica('ripristinato, il formato torna attuato', $statoDi('Formato di conservazione') === AttestazioneConformita::STATO_ATTUATO);

// Produrre un documento nel formato giusto non è conservarlo: la conservazione
// a norma è un processo con un responsabile, un manuale e un conservatore, e
// nessuna configurazione del modulo può renderla attuata.
EsitiConformita::verifica(
  'la conservazione a norma resta comunque a carico dell\'istituzione',
  $statoDi('Conservazione a norma e versamento') === AttestazioneConformita::STATO_A_CARICO
);
EsitiConformita::verifica(
  'e dichiara che i file riservati non sono un sistema di conservazione',
  str_contains(
    array_values(array_filter($attestatore->requisiti(), static fn (array $r): bool => str_contains($r['titolo'], 'Conservazione a norma')))[0]['attuazione'],
    'non è un sistema di conservazione'
  )
);

echo "\n[4] Esportazione\n";
$controller = \Drupal\psiphos\Controller\ConformitaController::create(\Drupal::getContainer());
$esportazione = $controller->esporta()->getContent();
$riletto = json_decode((string) $esportazione, TRUE);
EsitiConformita::verifica('esportazione JSON valida', is_array($riletto));
EsitiConformita::verifica('contiene tutti i requisiti', count($riletto['requisiti']) === count($requisiti));
EsitiConformita::verifica(
  'dichiara che i dati non lasciano il sistema informativo della scuola',
  contiene($riletto['prodotto']['natura'], 'non è un servizio erogato in cloud')
);

echo "\n[5] Documenti a corredo\n";
$cartella = __DIR__ . '/../../documentazione/';
foreach ([
  'dichiarazione-conformita.md' => '§9',
  'dpia-elementi.md' => '§6',
  'regolamento-articolo.md' => '§8',
] as $documento => $paragrafo) {
  $percorso = $cartella . $documento;
  $presente = file_exists($percorso);
  EsitiConformita::verifica(sprintf('presente %s', $documento), $presente);
  if ($presente) {
    $contenuto = (string) file_get_contents($percorso);
    EsitiConformita::verifica(
      sprintf('%s richiama la nota 3803 e il %s', $documento, $paragrafo),
      contiene($contenuto, '3803') && contiene($contenuto, $paragrafo)
    );
  }
}
$dichiarazione = (string) file_get_contents($cartella . 'dichiarazione-conformita.md');
EsitiConformita::verifica(
  'la dichiarazione dà atto di ciò che non attua',
  contiene($dichiarazione, 'non sono attuati dal modulo')
);
// La formula da respingere è quella che qualifica il rischio come statistico,
// non l'occorrenza della parola: il testo corretto la usa per negarla.
EsitiConformita::verifica(
  'e del limite residuo sul voto segreto, descritto come è',
  contiene($dichiarazione, 'registri del motore di banca dati')
    && contiene($dichiarazione, 'deterministica')
    && !preg_match('/(?<!una )correlazione statistica(?! ma)/u', $dichiarazione)
);
EsitiConformita::verifica(
  'ricordando che la verifica del §9 è preventiva',
  contiene($dichiarazione, 'prima della prima seduta deliberativa')
);
$dpia = (string) file_get_contents($cartella . 'dpia-elementi.md');
EsitiConformita::verifica(
  'gli elementi per la DPIA non si spacciano per una DPIA',
  contiene($dpia, 'non è una DPIA compilata')
);
$regolamento = (string) file_get_contents($cartella . 'regolamento-articolo.md');
EsitiConformita::verifica(
  'la bozza di regolamento disciplina il voto segreto',
  contiene($regolamento, 'scrutinio segreto')
);
EsitiConformita::verifica(
  'e i malfunzionamenti',
  contiene($regolamento, 'Malfunzionamenti')
);

echo "\n[Cifratura del canale]\n";
// Riferire «da attuare» senza guardare è un difetto dell'attestazione: chi ha
// già disposto HTTPS si vede contestare qualcosa che ha fatto, e impara a non
// fidarsi del resto del documento.
$cifratura = NULL;
foreach ($attestazione['requisiti'] as $requisito) {
  if ($requisito['titolo'] === 'Cifratura dei dati') {
    $cifratura = $requisito;
  }
}
EsitiConformita::verifica('il requisito sulla cifratura è presente', $cifratura !== NULL);
EsitiConformita::verifica(
  'da riga di comando il canale non è dichiarato né cifrato né in chiaro',
  str_contains($cifratura['attuazione'] ?? '', 'non è determinabile da riga di comando')
);
EsitiConformita::verifica(
  'la nota distingue ciò che è attuabile da ciò che non lo è su hosting condiviso',
  str_contains($cifratura['nota'] ?? '', 'non è verificabile dal cliente')
    && str_contains($cifratura['nota'] ?? '', 'copie di sicurezza è invece sempre attuabile')
);
EsitiConformita::verifica(
  "e la responsabilità è condivisa, non della sola istituzione",
  ($cifratura['responsabilita'] ?? '') === \Drupal\psiphos\Service\AttestazioneConformita::RESPONSABILITA_CONDIVISA
);

echo "\n[Aggiornamenti di sicurezza]\n";
/** Ritrova un requisito dal titolo. */
$requisitoPerTitolo = static function (string $inizio): ?array {
  foreach (\Drupal::service('psiphos.attestazione_conformita')->requisiti() as $requisito) {
    if (str_starts_with($requisito['titolo'], $inizio)) {
      return $requisito;
    }
  }
  return NULL;
};

$aggiornamenti = $requisitoPerTitolo('Aggiornamenti');
EsitiConformita::verifica('il requisito sugli aggiornamenti è presente', $aggiornamenti !== NULL);
EsitiConformita::verifica(
  'la responsabilità è condivisa: applicarli non spetta alla sola istituzione',
  ($aggiornamenti['responsabilita'] ?? '') === \Drupal\psiphos\Service\AttestazioneConformita::RESPONSABILITA_CONDIVISA
);
EsitiConformita::verifica(
  'e la nota rimanda al contratto di manutenzione',
  str_contains($aggiornamenti['nota'] ?? '', 'manutenzione del sito')
    && str_contains($aggiornamenti['nota'] ?? '', 'per iscritto')
);

// L'attestazione riferisce quando il sito ha controllato l'ultima volta: senza
// quel dato non si può affermare che non vi siano aggiornamenti pendenti, si
// può solo dire che non lo si sa.
$statoDrupal = \Drupal::state();
$ultimoControllo = $statoDrupal->get('update.last_check');
$statoDrupal->delete('update.last_check');
$senzaControllo = $requisitoPerTitolo('Aggiornamenti');
EsitiConformita::verifica(
  'senza alcun controllo eseguito lo dichiara',
  str_contains($senzaControllo['attuazione'] ?? '', 'Nessun controllo risulta ancora eseguito')
);
EsitiConformita::verifica(
  'e non attesta che tutto sia aggiornato',
  !str_contains($senzaControllo['attuazione'] ?? '', 'Nessun aggiornamento di sicurezza risulta non applicato')
);
if ($ultimoControllo !== NULL) {
  $statoDrupal->set('update.last_check', $ultimoControllo);
}

echo "\n[Come l'attestazione si presenta]\n";
// Le etichette degli stati sono ciò che il dirigente legge prima di firmare:
// se dicono il contrario di quel che significano, l'attestazione inganna anche
// quando i dati sotto sono corretti.
$commutatoreConformita = \Drupal::service('account_switcher');
$commutatoreConformita->switchTo(\Drupal\user\Entity\User::load(1));
$pagina = (string) \Drupal::service('renderer')->renderInIsolation(
  \Drupal::service('class_resolver')
    ->getInstanceFromDefinition(\Drupal\psiphos\Controller\ConformitaController::class)
    ->pagina()
);
$commutatoreConformita->switchBack();
$leggibile = html_entity_decode(strip_tags($pagina), ENT_QUOTES, 'UTF-8');

EsitiConformita::verifica(
  "lo stato di rilievo non si presenta come un controllo da fare",
  !str_contains($leggibile, 'Da verificare')
);
EsitiConformita::verifica(
  'ma come qualcosa che il modulo ha trovato',
  str_contains($leggibile, 'Richiede attenzione') || !str_contains($leggibile, 'Richiede')
);
EsitiConformita::verifica(
  'e il riepilogo usa la stessa lettura',
  str_contains($leggibile, 'Con rilievi:')
);

echo "\n[Ruoli privacy verso i fornitori]\n";
// Chi vende e mantiene il modulo tratta dati per conto della scuola: è un
// responsabile del trattamento. Un'attestazione che dicesse il contrario —
// firmata proprio da chi quel ruolo lo riveste — indurrebbe la scuola a non
// stipulare l'atto che definisce e delimita gli obblighi di entrambi.
$attestazioneRuoli = \Drupal::service('psiphos.attestazione_conformita')->attestazione();
EsitiConformita::verifica(
  "la natura del prodotto non nega l'esistenza di responsabili del trattamento",
  !str_contains($attestazioneRuoli['prodotto']['natura'], 'non vi è alcun responsabile')
    && str_contains($attestazioneRuoli['prodotto']['natura'], 'art. 28')
);

$ruoli = $requisitoPerTitolo('Ruoli privacy');
EsitiConformita::verifica(
  'il requisito sui ruoli non dichiara che la nomina non ricorre',
  !str_contains($ruoli['attuazione'] ?? '', 'Non ricorre')
);
EsitiConformita::verifica(
  'e nomina hosting e manutentore fra i responsabili',
  str_contains($ruoli['attuazione'] ?? '', "fornitore dell'hosting")
    && str_contains($ruoli['attuazione'] ?? '', 'manutenzione del sito')
);

$terzi = $requisitoPerTitolo('Localizzazione dei dati');
EsitiConformita::verifica(
  "l'hosting è riconosciuto come servizio di terzi da verificare",
  str_contains($terzi['attuazione'] ?? '', 'hosting condiviso')
    && ($terzi['stato'] ?? '') === \Drupal\psiphos\Service\AttestazioneConformita::STATO_A_CARICO
);

echo "\n[Coerenza interna sulla segretezza]\n";
// Lo stesso oggetto è attestato in due punti: il principio del §2 e il
// requisito tecnico del §4.3. Se il principio dichiarasse una garanzia piena
// dove il requisito dichiara un margine residuo, l'attestazione si
// contraddirebbe da sola, e chi la controlla se ne accorge prima di chi
// l'ha firmata.
$principio = $requisitoPerTitolo('Segretezza del voto');
$tecnico = $requisitoPerTitolo('Voto a scrutinio segreto');
EsitiConformita::verifica(
  'principio e requisito tecnico hanno la stessa responsabilità',
  ($principio['responsabilita'] ?? 'a') === ($tecnico['responsabilita'] ?? 'b')
);
EsitiConformita::verifica(
  'e il principio non tace il margine residuo',
  str_contains($principio['attuazione'] ?? '', 'margine residuo')
);

echo "\n[Livello di autenticazione dichiarato e in essere (§3.2)]\n";
// L'impostazione dichiara il livello che si intende adottare; da sola non lo
// eroga. Riportarla come un fatto significa affermare in un documento firmato
// l'esistenza di una misura che nessuno ha riscontrato — e il §3.2 riguarda
// proprio la prevenzione dell'impersonificazione.
$servizio = \Drupal::service('psiphos.attestazione_conformita');

$senzaModulo = $servizio->statoAutenticazione('mfa', '');
EsitiConformita::verifica(
  'il secondo fattore dichiarato senza modulo che lo eroghi è un rilievo',
  $senzaModulo['stato'] === \Drupal\psiphos\Service\AttestazioneConformita::STATO_ATTENZIONE
);
EsitiConformita::verifica(
  'e non viene riferito come se fosse in essere',
  str_contains($senzaModulo['riferisce'], 'non risulta attivo alcun modulo')
    && str_contains($senzaModulo['riferisce'], 'sola password')
);

$forteAssente = $servizio->statoAutenticazione('forte', 'spid');
EsitiConformita::verifica(
  "l'autenticazione forte dichiarata senza il suo modulo è un rilievo",
  $forteAssente['stato'] === \Drupal\psiphos\Service\AttestazioneConformita::STATO_ATTENZIONE
    && str_contains($forteAssente['riferisce'], 'non risulta attivo')
);

$minimo = $servizio->statoAutenticazione('account', '');
EsitiConformita::verifica(
  'il livello minimo è dichiarato per quello che è',
  str_contains($minimo['riferisce'], 'senza secondo fattore')
);
EsitiConformita::verifica(
  "e rimanda alla motivazione nel Regolamento d'istituto",
  str_contains($minimo['nota'], "Regolamento d'istituto")
);

echo "\n[Corrispondenza con le voci del §5]\n";
// Chi controlla l'attestazione la scorre accanto all'allegato, voce per voce.
// Una voce dell'allegato che non trova un requisito corrispondente è un
// rilievo, anche quando la sostanza è coperta altrove: il §5 elenca sei
// misure e vi aggiunge, in un capoverso a sé, la continuità operativa.
$titoli = [];
foreach (\Drupal::service('psiphos.attestazione_conformita')->requisiti() as $requisito) {
  if ($requisito['paragrafo'] === '§5') {
    $titoli[] = $requisito['titolo'];
  }
}
foreach ([
  'la cifratura dei dati' => 'Cifratura dei dati',
  'la protezione da vulnerabilità note' => 'Aggiornamenti e vulnerabilità note',
  'il monitoraggio e la registrazione degli accessi' => 'Monitoraggio e registrazione degli accessi',
  'la disponibilità di sistemi di audit' => 'Disponibilità di sistemi di audit',
  'la segregazione degli ambienti' => 'Segregazione degli ambienti',
  'la gestione degli incidenti di sicurezza' => 'Gestione degli incidenti di sicurezza',
  'le misure di continuità operativa' => 'Continuità operativa',
  'i servizi di terze parti' => 'Localizzazione dei dati e servizi di terzi',
] as $voceAllegato => $titolo) {
  EsitiConformita::verifica(
    "«{$voceAllegato}» ha un requisito proprio",
    array_filter($titoli, static fn (string $t): bool => str_starts_with($t, $titolo)) !== []
  );
}

$accessi = $requisitoPerTitolo('Monitoraggio e registrazione');
EsitiConformita::verifica(
  "la registrazione degli accessi distingue l'aula dal sito",
  str_contains($accessi['attuazione'] ?? '', "dall'aula")
    && str_contains($accessi['attuazione'] ?? '', 'accessi al sito')
);

echo "\n[Dati personali effettivamente trattati (§6)]\n";
// L'attestazione enumera che cosa il modulo conserva. Se un giorno una
// colonna nuova comparisse senza che l'enumerazione la segua, il documento
// direbbe il falso: la verifica confronta l'enunciato con lo schema.
$schemaVerifica = \Drupal::database()->schema();
$minimizzazione = $requisitoPerTitolo('Minimizzazione');

EsitiConformita::verifica(
  "l'attestazione enumera i dati personali trattati",
  str_contains($minimizzazione['attuazione'] ?? '', "l'utenza dell'avente diritto")
);
EsitiConformita::verifica(
  'e dichiara di non conservare indirizzi di rete',
  str_contains($minimizzazione['attuazione'] ?? '', 'Nessun indirizzo di rete')
);
foreach (['psiphos_presenza', 'psiphos_urna', 'psiphos_attestazione', 'psiphos_voto_palese', 'psiphos_audit'] as $tabella) {
  $indirizzi = FALSE;
  foreach (['ip', 'indirizzo_ip', 'user_agent', 'agente'] as $colonna) {
    $indirizzi = $indirizzi || $schemaVerifica->fieldExists($tabella, $colonna);
  }
  EsitiConformita::verifica("e in $tabella non ve ne sono davvero", $indirizzi === FALSE);
}
EsitiConformita::verifica(
  "avverte che la giustificazione dell'assenza può diventare un dato dell'art. 9",
  str_contains($minimizzazione['nota'] ?? '', 'art. 9')
);

$misure = $requisitoPerTitolo('Misure tecniche');
EsitiConformita::verifica(
  "il §6 ha una voce propria per le misure di sicurezza",
  $misure !== NULL && str_contains($misure['attuazione'] ?? '', 'anonimizzazione')
);
$garanzie = $requisitoPerTitolo('Garanzie contrattuali');
EsitiConformita::verifica(
  'e una per le garanzie contrattuali, con il richiamo all\'art. 28 §3',
  $garanzie !== NULL && str_contains($garanzie['attuazione'] ?? '', 'art. 28')
);

echo "\n[La dichiarazione da sottoscrivere (§9)]\n";
// È il documento che il fornitore firma. Ciò che vi è scritto lo impegna: una
// descrizione del rischio residuo più mite di quella nota è precisamente ciò
// che una dichiarazione di conformità non deve contenere.
$impostazioniFornitore = $configurazione->getEditable('psiphos.settings');
$fornitoreOriginale = $impostazioniFornitore->get('fornitore.denominazione');

$impostazioniFornitore->set('fornitore.denominazione', '')->save();
EsitiConformita::verifica(
  'senza fornitore indicato, il §9 è un rilievo',
  $statoDi('Verifica di conformità') === AttestazioneConformita::STATO_ATTENZIONE
);
$impostazioniFornitore->set('fornitore.denominazione', 'Fornitore di prova')->save();
EsitiConformita::verifica(
  'indicato il fornitore, resta da sottoscrivere',
  $statoDi('Verifica di conformità') === AttestazioneConformita::STATO_A_CARICO
);

$attestatoreFornitore = \Drupal::service('psiphos.attestazione_conformita')->attestazione();
EsitiConformita::verifica(
  "l'attestazione identifica il fornitore dichiarante",
  ($attestatoreFornitore['fornitore']['denominazione'] ?? '') === 'Fornitore di prova'
);

$costruzioneDichiarazione = [
  '#theme' => 'psiphos_conformita',
  '#attestazione' => $attestatoreFornitore,
  '#documento' => TRUE,
  '#cache' => ['max-age' => 0],
];
$dichiarazione = (string) \Drupal::service('renderer')->renderInIsolation($costruzioneDichiarazione);
$leggibileDichiarazione = html_entity_decode(strip_tags($dichiarazione), ENT_QUOTES, 'UTF-8');
EsitiConformita::verifica(
  'la dichiarazione riporta il fornitore che la rende',
  str_contains($leggibileDichiarazione, 'Fornitore di prova')
);
EsitiConformita::verifica(
  'non descrive più il rischio residuo come correlazione statistica',
  !str_contains($leggibileDichiarazione, 'correlazione statistica')
);
EsitiConformita::verifica(
  "e lo descrive come è: i registri del motore di banca dati",
  str_contains($leggibileDichiarazione, 'registri del motore di banca dati')
    && str_contains($leggibileDichiarazione, "fornitore dell'hosting")
);
EsitiConformita::verifica(
  'e porta i due blocchi di firma, del fornitore e del dirigente',
  substr_count($leggibileDichiarazione, 'Luogo e data') === 2
);

$impostazioniFornitore->set('fornitore.denominazione', $fornitoreOriginale)->save();

echo "\n[Documenti per l'istituzione (§9)]\n";
// Il §9 chiede all'istituzione di *acquisire* la documentazione tecnica.
// Finché quei documenti erano soltanto file nella cartella del modulo,
// l'acquisizione presupponeva un accesso al codice che un dirigente non ha.
$biblioteca = \Drupal::service('psiphos.documento_testuale');
$attesi = [
  'richieste-al-fornitore-hosting',
  'nomina-del-manutentore',
  'regolamento-articolo',
  'dpia-elementi',
  'registro-art-30',
  'conservazione-a-norma',
  'dichiarazione-conformita',
];
EsitiConformita::verifica(
  'i sette documenti sono pubblicati e leggibili',
  $biblioteca->elenco() === $attesi
);
// Il registro consuma ciò che gli altri producono — destinatari dagli atti di
// nomina, categorie di dati e misure dalla valutazione d'impatto — e compilarlo
// prima significa doverlo riscrivere.
EsitiConformita::verifica(
  "e nell'ordine in cui vanno affrontati",
  array_search('regolamento-articolo', $biblioteca->elenco(), TRUE)
    < array_search('dpia-elementi', $biblioteca->elenco(), TRUE)
    && array_search('dpia-elementi', $biblioteca->elenco(), TRUE)
      < array_search('registro-art-30', $biblioteca->elenco(), TRUE)
);
EsitiConformita::verifica(
  'un identificativo inventato non è servito',
  !$biblioteca->esiste('../../../settings')
);

// La pagina del singolo documento deve offrire i propri moduli: l'unione con
// «+» le lasciava cadere, perché le chiavi degli uni e delle azioni fisse sono
// le stesse, e il bottone non compariva senza che nulla segnalasse l'errore.
$paginaDocumento = static function (string $documento): string {
  $controllore = \Drupal::service('class_resolver')
    ->getInstanceFromDefinition('Drupal\psiphos\Controller\DocumentazioneController');

  return html_entity_decode(
    strip_tags((string) \Drupal::service('renderer')->renderInIsolation($controllore->documento($documento))),
    ENT_QUOTES,
    'UTF-8'
  );
};
EsitiConformita::verifica(
  'la pagina del documento offre la guida in PDF e il testo sorgente',
  str_contains($paginaDocumento('registro-art-30'), 'Scarica la guida')
    && str_contains($paginaDocumento('registro-art-30'), 'Testo sorgente')
);
EsitiConformita::verifica(
  'la pagina del documento offre il suo modulo precompilato',
  str_contains($paginaDocumento('registro-art-30'), 'Scarica il modulo precompilato')
);
EsitiConformita::verifica(
  'e quando i moduli sono due li nomina entrambi',
  str_contains($paginaDocumento('richieste-al-fornitore-hosting'), 'Richiesta al fornitore')
    && str_contains($paginaDocumento('richieste-al-fornitore-hosting'), 'Atto di nomina del fornitore')
);
EsitiConformita::verifica(
  'e non ne offre dove non ve ne sono',
  !str_contains($paginaDocumento('dichiarazione-conformita'), 'Scarica il modulo')
);

$resi = 0;
foreach ($attesi as $documento) {
  $reso = (string) $biblioteca->reso($documento);
  // Il documento non contiene HTML: ciò che vi somigliasse va mostrato, non
  // eseguito. Gli unici tag ammessi sono quelli generati dalla resa.
  $tagAmmessi = ['h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li', 'table',
    'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'pre', 'code', 'strong',
    'em', 'hr', 'div', 'span'];
  preg_match_all('#</?([a-z0-9]+)#i', $reso, $trovati);
  $estranei = array_diff(array_unique($trovati[1]), $tagAmmessi);
  if ($estranei === [] && $reso !== '' && $biblioteca->titolo($documento) !== $documento) {
    $resi++;
  }
}
EsitiConformita::verifica(
  'ciascuno è reso con il proprio titolo e senza marcatori estranei',
  $resi === count($attesi)
);
EsitiConformita::verifica(
  'il testo sorgente resta scaricabile per chi voglia rielaborarlo',
  str_starts_with($biblioteca->sorgente('regolamento-articolo'), '# Bozza di articolo')
    && $biblioteca->nomeFile('regolamento-articolo') === 'regolamento-articolo.md'
);

// Ma non è quello che si inoltra: il Responsabile della protezione dei dati e
// il Consiglio d'istituto ricevono un file, e un «.md» non hanno con che cosa
// aprirlo. La guida si scarica in PDF.
$guide = 0;
foreach ($attesi as $documento) {
  $prodotto = $biblioteca->pdf($documento);
  if (str_starts_with($prodotto['contenuto'], '%PDF')
    && strlen($prodotto['contenuto']) > 20000
    && $biblioteca->nomeFilePdf($documento) === $documento . '.pdf') {
    $guide++;
  }
}
EsitiConformita::verifica(
  'ciascuna guida si scarica in PDF, con il proprio nome di file',
  $guide === count($attesi)
);
EsitiConformita::verifica(
  'e nel formato di conservazione quando il server lo consente',
  !\Drupal::service('psiphos.conservazione_documento')->conservazioneDisponibile()
    || $biblioteca->pdf('registro-art-30')['formato'] === 'PDF/A-2B'
);
EsitiConformita::verifica(
  'un identificativo inventato non produce alcun PDF',
  (static function (): bool {
    try {
      \Drupal::service('psiphos.documento_testuale')->pdf('inesistente');
      return FALSE;
    }
    catch (\InvalidArgumentException) {
      return TRUE;
    }
  })()
);

$novemo = $requisitoPerTitolo('Verifica di conformità');
EsitiConformita::verifica(
  "l'attestazione rimanda alla pagina e non a un percorso di file",
  str_contains($novemo['attuazione'] ?? '', '/admin/reports/psiphos/documentazione')
    && !str_contains($novemo['attuazione'] ?? '', 'documentazione/dichiarazione')
);

echo "\n[Voci di menù]\n";
// Due voci sciolte fra i resoconti non dicono che si usano nell'ordine: prima
// gli adempimenti che i documenti descrivono, poi l'attestazione che ne
// riferisce l'esito. Invertirle inviterebbe a firmare prima di aver fatto.
$sottovoci = [];
$parametri = (new \Drupal\Core\Menu\MenuTreeParameters())
  ->setRoot('psiphos.resoconti')
  ->setMaxDepth(1)
  ->onlyEnabledLinks();
foreach (\Drupal::service('menu.link_tree')->load('admin', $parametri) as $elemento) {
  foreach ($elemento->subtree as $sotto) {
    $sottovoci[$sotto->link->getPluginId()] = $sotto->link->getWeight();
  }
}
EsitiConformita::verifica(
  'le due pagine stanno sotto una sola voce Psíphos',
  isset($sottovoci['psiphos.documentazione'], $sottovoci['psiphos.conformita'])
);
EsitiConformita::verifica(
  "e nell'ordine in cui si usano: prima i documenti, poi l'attestazione",
  ($sottovoci['psiphos.documentazione'] ?? 0) < ($sottovoci['psiphos.conformita'] ?? 0)
);
EsitiConformita::verifica(
  'la pagina che le raccoglie risponde e le elenca entrambe',
  (static function (): bool {
    // La pagina è protetta dal permesso: senza un utente che lo possieda si
    // otterrebbe un 403, e il controllo direbbe che la pagina non esiste.
    $commutatore = \Drupal::service('account_switcher');
    $commutatore->switchTo(\Drupal\user\Entity\User::load(1));
    try {
      $risposta = \Drupal::service('http_kernel')->handle(
        \Symfony\Component\HttpFoundation\Request::create('/admin/reports/psiphos')
      );
      $contenuto = (string) $risposta->getContent();

      return $risposta->getStatusCode() === 200
        && str_contains($contenuto, 'documentazione')
        && str_contains($contenuto, 'conformita');
    }
    finally {
      $commutatore->switchBack();
    }
  })()
);

echo "\n[Moduli precompilati]\n";
// Scaricare la guida non è scaricare la lettera: chi la riceve dovrebbe
// ritagliare il riquadro, togliere i marcatori e riempire a mano dati che il
// sito conosce già.
$moduli = \Drupal::service('psiphos.moduli_precompilati');
foreach ([
  'richieste-al-fornitore-hosting',
  'nomina-del-manutentore',
  'regolamento-articolo',
  'conservazione-a-norma',
  'dpia-elementi',
  'registro-art-30',
] as $conModello) {
  EsitiConformita::verifica("«{$conModello}» ha un modulo precompilato", $moduli->disponibile($conModello));
}
// La guida sul fornitore dell'infrastruttura ne porta due: la richiesta si
// spedisce, l'atto di nomina si sottoscrive, e sono due destini diversi.
EsitiConformita::verifica(
  "la guida sul fornitore dell'infrastruttura porta la richiesta e l'atto di nomina",
  $moduli->modelli('richieste-al-fornitore-hosting') === ['richiesta', 'nomina']
);
EsitiConformita::verifica(
  'senza indicarne alcuno si ottiene il primo, e resta la richiesta',
  $moduli->nomeFile('richieste-al-fornitore-hosting') === 'richiesta-fornitore-hosting.pdf'
    && $moduli->nomeFile('richieste-al-fornitore-hosting', 'nomina') === 'nomina-responsabile-hosting.pdf'
);
EsitiConformita::verifica(
  'un modello inventato non è servito',
  !$moduli->disponibile('richieste-al-fornitore-hosting', 'inesistente')
);
EsitiConformita::verifica(
  'e la dichiarazione di conformità non ne ha, perché è già un documento prodotto',
  !$moduli->disponibile('dichiarazione-conformita')
);

// L'articolo di Regolamento deve riportare i valori configurati, non quelli
// predefiniti: è il punto in cui il Regolamento comincia a promettere ciò che
// il sistema non fa, e nessuno se ne accorge finché non lo si contesta.
$configurazioneRegolamento = \Drupal::configFactory()->getEditable('psiphos.settings');
$originali = [
  'sessione.timeout_inattivita' => $configurazioneRegolamento->get('sessione.timeout_inattivita'),
  'sessione.sessione_esclusiva' => $configurazioneRegolamento->get('sessione.sessione_esclusiva'),
  'autenticazione.livello' => $configurazioneRegolamento->get('autenticazione.livello'),
  'audit.ritenzione_giorni' => $configurazioneRegolamento->get('audit.ritenzione_giorni'),
];

/** Rende l'articolo di Regolamento come testo leggibile. */
$regolamentoReso = static function (): string {
  $costruzione = [
    '#theme' => 'psiphos_modello_regolamento',
    '#istituto' => \Drupal::service('psiphos.intestazione_istituto')->dati(),
    '#fornitore' => [],
    '#dominio' => 'esempio.edu.it',
    '#configurazione' => (function (): array {
      $c = \Drupal::config('psiphos.settings');
      $giorni = (int) $c->get('audit.ritenzione_giorni');
      return [
        'minuti' => (int) round(((int) $c->get('sessione.timeout_inattivita')) / 60),
        'sessione_esclusiva' => (bool) $c->get('sessione.sessione_esclusiva'),
        'livello' => (string) $c->get('autenticazione.livello'),
        'ritenzione_giorni' => $giorni,
        'ritenzione' => $giorni > 0 && $giorni % 365 === 0 ? intdiv($giorni, 365) : 0,
      ];
    })(),
    '#cache' => ['max-age' => 0],
  ];
  return html_entity_decode(
    strip_tags((string) \Drupal::service('renderer')->renderInIsolation($costruzione)),
    ENT_QUOTES,
    'UTF-8'
  );
};

$configurazioneRegolamento
  ->set('sessione.timeout_inattivita', 600)
  ->set('sessione.sessione_esclusiva', FALSE)
  ->set('autenticazione.livello', 'account')
  ->set('audit.ritenzione_giorni', 1825)
  ->save();

$articolo = $regolamentoReso();
EsitiConformita::verifica(
  "l'articolo riporta la tolleranza di collegamento configurata",
  str_contains($articolo, 'superiore a 10 minuti')
);
EsitiConformita::verifica(
  'e la conservazione delle tracciature in anni',
  str_contains($articolo, 'conservate per 5 anni')
);
EsitiConformita::verifica(
  "e il livello di autenticazione in essere, senza promettere il secondo fattore",
  str_contains($articolo, 'credenziali personali del sito istituzionale.')
    && !str_contains($articolo, 'due fattori')
);
EsitiConformita::verifica(
  'e tace il dispositivo unico quando la sessione esclusiva è disattivata',
  !str_contains($articolo, 'un solo dispositivo per volta')
);

$configurazioneRegolamento
  ->set('sessione.sessione_esclusiva', TRUE)
  ->set('autenticazione.livello', 'mfa')
  ->save();
$articolo = $regolamentoReso();
EsitiConformita::verifica(
  'riattivata la sessione esclusiva, il comma 4 la prevede',
  str_contains($articolo, 'un solo dispositivo per volta')
    && str_contains($articolo, 'due fattori')
);

foreach ($originali as $chiave => $valore) {
  $configurazioneRegolamento->set($chiave, $valore);
}
$configurazioneRegolamento->save();

$prodotto = $moduli->produci('nomina-del-manutentore');
EsitiConformita::verifica(
  "l'atto di nomina esce come documento conservabile",
  str_starts_with($prodotto['contenuto'], '%PDF') && strlen($prodotto['contenuto']) > 10000
);

// Il modulo è precompilato quando riporta ciò che il sito conosce: se
// contenesse solo caselle vuote non sarebbe diverso dalla guida.
$costruzione = [
  '#theme' => 'psiphos_modello_nomina',
  '#istituto' => \Drupal::service('psiphos.intestazione_istituto')->dati(),
  '#fornitore' => [
    'denominazione' => 'Fornitore di prova',
    'partita_iva' => '01234567890',
    'contatto' => 'prova@example.test',
  ],
  '#dominio' => 'esempio.edu.it',
  '#cache' => ['max-age' => 0],
];
$reso = html_entity_decode(
  strip_tags((string) \Drupal::service('renderer')->renderInIsolation($costruzione)),
  ENT_QUOTES,
  'UTF-8'
);
$nomeIstituto = \Drupal::service('psiphos.intestazione_istituto')->dati()['istituto'];
foreach ([
  "la denominazione dell'istituto" => $nomeIstituto,
  'la denominazione del fornitore' => 'Fornitore di prova',
  'la partita IVA del fornitore' => '01234567890',
  'il dominio del sito' => 'esempio.edu.it',
] as $descrizione => $atteso) {
  EsitiConformita::verifica("l'atto riporta $descrizione", str_contains($reso, $atteso));
}
EsitiConformita::verifica(
  'e lascia in bianco soltanto ciò che il sito non può conoscere',
  str_contains($reso, 'Dirigente scolastico pro tempore') && str_contains($reso, 'Luogo e data')
);

// Senza fornitore indicato l'atto non tace: dichiara di essere incompleto.
// L'array va ricostruito: quello reso porta con sé lo stato della resa, e
// riutilizzarlo restituirebbe il documento precedente.
$costruzioneVuota = [
  '#theme' => 'psiphos_modello_nomina',
  '#istituto' => \Drupal::service('psiphos.intestazione_istituto')->dati(),
  '#fornitore' => ['denominazione' => '', 'partita_iva' => '', 'contatto' => ''],
  '#dominio' => 'esempio.edu.it',
  '#cache' => ['max-age' => 0],
];
$senzaFornitore = html_entity_decode(
  strip_tags((string) \Drupal::service('renderer')->renderInIsolation($costruzioneVuota)),
  ENT_QUOTES,
  'UTF-8'
);
EsitiConformita::verifica(
  "senza fornitore indicato, l'atto avverte di essere incompleto",
  str_contains($senzaFornitore, 'non è indicato nelle impostazioni')
);

echo "\n[Descrizione tecnica per la valutazione d'impatto]\n";
// La valutazione è del titolare: se il modulo precompilato somigliasse a una
// DPIA compiuta, il dirigente la firmerebbe senza istruttoria e la scuola si
// troverebbe con un adempimento apparente. Il documento deve dire ciò che il
// titolare non può ricavare senza leggere il codice, e nient'altro.
$descrizioneResa = static function (array $configurazione = []): string {
  $costruzione = [
    '#theme' => 'psiphos_modello_dpia',
    '#istituto' => \Drupal::service('psiphos.intestazione_istituto')->dati(),
    '#fornitore' => [],
    '#dominio' => 'esempio.edu.it',
    '#configurazione' => $configurazione + [
      'minuti' => 5,
      'sessione_esclusiva' => TRUE,
      'livello' => 'account',
      'provider_forte' => '',
      'ritenzione_giorni' => 1825,
      'ritenzione' => 5,
      'autenticazione' => ['riferisce' => 'credenziali personali del sito'],
      'conservazione_disponibile' => TRUE,
    ],
    '#cache' => ['max-age' => 0],
  ];
  return html_entity_decode(
    strip_tags((string) \Drupal::service('renderer')->renderInIsolation($costruzione)),
    ENT_QUOTES,
    'UTF-8'
  );
};

$descrizione = $descrizioneResa();
EsitiConformita::verifica(
  "il documento si intitola descrizione tecnica, non valutazione d'impatto",
  str_contains($descrizione, 'Descrizione tecnica del trattamento')
    && !str_contains($descrizione, "Valutazione d'impatto sulla protezione dei dati\n")
);
EsitiConformita::verifica(
  'e dichiara di non sostituire la valutazione del titolare',
  str_contains($descrizione, "non è una valutazione d'impatto e non la sostituisce")
);
EsitiConformita::verifica(
  "avverte che il testo libero può accogliere dati dell'art. 9",
  str_contains($descrizione, 'Categorie particolari di dati')
    && str_contains($descrizione, 'motivo di salute')
);
EsitiConformita::verifica(
  'descrive il rischio residuo come deterministico e non statistico',
  str_contains($descrizione, 'binary log')
    && str_contains($descrizione, 'Non è una correlazione statistica ma deterministica')
);
EsitiConformita::verifica(
  "avverte che la presenza attesta il collegamento, non l'attenzione",
  str_contains($descrizione, "la presenza dall'attenzione")
);
EsitiConformita::verifica(
  "elenca le misure che restano a carico dell'istituzione",
  str_contains($descrizione, 'Il modulo non le attua e non può attuarle')
);
// È la prima domanda che un Responsabile della protezione dei dati fa sui
// gruppi di lavoro operativi: chi legge quel verbale, e con quale titolo.
EsitiConformita::verifica(
  'dichiara chi accede ai verbali e per quale titolo',
  str_contains($descrizione, 'Chi accede ai verbali, e per quale titolo')
    && str_contains($descrizione, 'gruppo di lavoro operativo di un alunno di cui non fa parte')
);
EsitiConformita::verifica(
  'e che i titoli sono ancorati alla seduta, non al ruolo in essere',
  str_contains($descrizione, 'ancorati alla seduta, non al ruolo in essere')
    && str_contains($descrizione, 'ricambio annuale degli incarichi')
);

// Il § sulla configurazione vale solo se riporta i valori in essere: una
// descrizione che mostri i predefiniti descrive un altro trattamento.
$descrizione = $descrizioneResa([
  'minuti' => 10,
  'sessione_esclusiva' => FALSE,
  'ritenzione_giorni' => 90,
  'ritenzione' => 0,
  'autenticazione' => ['riferisce' => 'secondo fattore richiesto da mfa'],
  'conservazione_disponibile' => FALSE,
]);
EsitiConformita::verifica(
  'la configurazione riporta la tolleranza di collegamento in essere',
  str_contains($descrizione, 'dopo 10 minuti senza contatto')
);
EsitiConformita::verifica(
  'e la ritenzione in giorni quando non è un multiplo di anni',
  str_contains($descrizione, '90 giorni dalla chiusura')
);
EsitiConformita::verifica(
  "e l'autenticazione osservata, non quella dichiarata",
  str_contains($descrizione, 'secondo fattore richiesto da mfa')
);
EsitiConformita::verifica(
  'e avverte quando il formato di conservazione non è producibile',
  str_contains($descrizione, 'non è producibile su questo server')
);
EsitiConformita::verifica(
  'e dichiara la sessione esclusiva disattivata quando lo è',
  str_contains($descrizione, 'più dispositivi contemporaneamente')
);

$prodotto = $moduli->produci('dpia-elementi');
EsitiConformita::verifica(
  'la descrizione tecnica esce come documento conservabile',
  str_starts_with($prodotto['contenuto'], '%PDF') && strlen($prodotto['contenuto']) > 10000
);

echo "\n[Fornitore dell'infrastruttura]\n";
// Chi ospita il sito tratta gli stessi dati e ne detiene i supporti: senza
// identificarlo, il rischio residuo sul voto segreto non è attribuibile ad
// alcun soggetto determinato. Ma identificarlo non è verificarlo, ed è la
// distinzione che questi controlli difendono.
$impostazioniHosting = \Drupal::configFactory()->getEditable('psiphos.settings');
$hostingOriginale = $impostazioniHosting->get('hosting');

/** Rende un modello come testo leggibile. */
$modelloReso = static function (string $documento, ?string $modello = NULL): string {
  return html_entity_decode(
    strip_tags((string) \Drupal::service('psiphos.moduli_precompilati')->produci($documento, $modello)['contenuto']),
    ENT_QUOTES,
    'UTF-8'
  );
};

/** Rende il tema di un modello, saltando la produzione del PDF. */
$temaReso = static function (string $tema): string {
  $costruzione = [
    '#theme' => $tema,
    '#istituto' => \Drupal::service('psiphos.intestazione_istituto')->dati(),
    '#fornitore' => ['denominazione' => 'ADVICE', 'partita_iva' => '99999999999', 'contatto' => 'advice@example.test'],
    '#hosting' => (array) \Drupal::config('psiphos.settings')->get('hosting'),
    '#dominio' => 'esempio.edu.it',
    '#configurazione' => (function (): array {
      $c = \Drupal::config('psiphos.settings');
      $giorni = (int) $c->get('audit.ritenzione_giorni');
      return [
        'minuti' => (int) round(((int) $c->get('sessione.timeout_inattivita')) / 60),
        'sessione_esclusiva' => (bool) $c->get('sessione.sessione_esclusiva'),
        'livello' => (string) $c->get('autenticazione.livello'),
        'provider_forte' => (string) $c->get('autenticazione.provider_forte'),
        'ritenzione_giorni' => $giorni,
        'ritenzione' => $giorni > 0 && $giorni % 365 === 0 ? intdiv($giorni, 365) : 0,
        'autenticazione' => \Drupal::service('psiphos.attestazione_conformita')->statoAutenticazione(
          (string) $c->get('autenticazione.livello'),
          (string) $c->get('autenticazione.provider_forte')
        ),
        'conservazione_disponibile' => \Drupal::service('psiphos.conservazione_documento')->conservazioneDisponibile(),
      ];
    })(),
    '#cache' => ['max-age' => 0],
  ];
  return html_entity_decode(
    strip_tags((string) \Drupal::service('renderer')->renderInIsolation($costruzione)),
    ENT_QUOTES,
    'UTF-8'
  );
};

/** Il requisito sulla localizzazione, così come l'attestazione lo riferisce. */
$requisitoLocalizzazione = static function (): array {
  foreach (\Drupal::service('psiphos.attestazione_conformita')->requisiti() as $requisito) {
    if (str_contains($requisito['titolo'], 'Localizzazione')) {
      return $requisito;
    }
  }
  return [];
};

// Senza fornitore indicato, nessun documento tace: ciascuno dichiara che cosa
// manca, perché un atto silenzioso su questo punto sembrerebbe completo.
$impostazioniHosting->set('hosting', [
  'denominazione' => '',
  'partita_iva' => '',
  'sede' => '',
  'contatto' => '',
  'ubicazione_dati' => '',
  'nomina_protocollo' => '',
  'nomina_data' => '',
  'riscontro_protocollo' => '',
  'riscontro_data' => '',
])->save();
drupal_flush_all_caches();

EsitiConformita::verifica(
  "senza fornitore indicato, la richiesta lascia il destinatario in bianco e lo dice",
  str_contains($temaReso('psiphos_modello_hosting'), 'indicarlo in /admin/config/psiphos')
);
EsitiConformita::verifica(
  "e l'atto di nomina avverte di essere incompleto",
  str_contains($temaReso('psiphos_modello_nomina_hosting'), "non è indicato nelle impostazioni del modulo")
);
EsitiConformita::verifica(
  'e la descrizione tecnica dichiara il rischio non attribuibile',
  str_contains($temaReso('psiphos_modello_dpia'), "non è attribuibile ad alcun soggetto determinato")
);
EsitiConformita::verifica(
  'e il registro non compila la voce sui trasferimenti',
  str_contains($temaReso('psiphos_modello_registro'), "Non risulta acquisita")
);
$requisito = $requisitoLocalizzazione();
EsitiConformita::verifica(
  "e l'attestazione dà atto che nessun fornitore è indicato",
  str_contains($requisito['nota'] ?? '', "non è indicato alcun fornitore")
);

// Compilati i campi, l'identità scorre nei quattro documenti che ne hanno
// bisogno.
$impostazioniHosting->set('hosting', [
  'denominazione' => 'Infrastrutture di prova S.r.l.',
  'partita_iva' => '01234567890',
  'sede' => 'Via della Prova 1, Roma (RM)',
  'contatto' => 'prova@pec.example.test',
  'ubicazione_dati' => 'Italia',
  'nomina_protocollo' => '4521/2026',
  'nomina_data' => '2026-09-15',
  'riscontro_protocollo' => '4390/2026',
  'riscontro_data' => '2026-09-02',
])->save();
drupal_flush_all_caches();

foreach ([
  'la richiesta' => 'psiphos_modello_hosting',
  "l'atto di nomina" => 'psiphos_modello_nomina_hosting',
  'la descrizione tecnica' => 'psiphos_modello_dpia',
  'il registro' => 'psiphos_modello_registro',
] as $descrizione => $tema) {
  EsitiConformita::verifica(
    "{$descrizione} riporta il fornitore dell'infrastruttura",
    str_contains($temaReso($tema), 'Infrastrutture di prova S.r.l.')
  );
}
EsitiConformita::verifica(
  'il registro cita protocollo e data della nomina, in forma leggibile',
  str_contains($temaReso('psiphos_modello_registro'), 'prot. 4521/2026')
    && str_contains($temaReso('psiphos_modello_registro'), 'del 15/09/2026')
);
EsitiConformita::verifica(
  "e riporta l'ubicazione dichiarata avvertendo che non è verificata",
  str_contains($temaReso('psiphos_modello_registro'), 'risiedono in Italia')
    && str_contains($temaReso('psiphos_modello_registro'), 'riferito dal fornitore e non verificato')
);
EsitiConformita::verifica(
  "il registro descrive la restrizione per organo fra le misure di sicurezza",
  str_contains($temaReso('psiphos_modello_registro'), "circoscritta all'organo che ha prodotto l'atto")
);
EsitiConformita::verifica(
  "e i due responsabili restano distinti: chi ospita e chi mantiene",
  str_contains($temaReso('psiphos_modello_registro'), 'Infrastrutture di prova S.r.l.')
    && str_contains($temaReso('psiphos_modello_registro'), 'ADVICE')
);

// Il punto sul quale l'intera impostazione sta o cade: compilare i campi non
// attua la misura. Se questi controlli cadessero, l'attestazione dichiarerebbe
// attuato ciò che nessuno ha verificato.
$requisito = $requisitoLocalizzazione();
EsitiConformita::verifica(
  "compilati i campi, il requisito del §5 resta a carico dell'istituzione",
  ($requisito['stato'] ?? '') === 'a_carico'
);
EsitiConformita::verifica(
  "e l'attestazione riferisce gli atti senza dirli verificati",
  str_contains($requisito['nota'] ?? '', 'Infrastrutture di prova S.r.l.')
    && str_contains($requisito['nota'] ?? '', 'prot. 4521/2026')
    && str_contains($requisito['nota'] ?? '', 'riferito, non verificato')
);

// Fornitore indicato ma nessun atto: è il caso peggiore, perché sembra a posto.
$impostazioniHosting
  ->set('hosting.nomina_protocollo', '')
  ->set('hosting.nomina_data', '')
  ->set('hosting.riscontro_protocollo', '')
  ->set('hosting.riscontro_data', '')
  ->save();
drupal_flush_all_caches();

EsitiConformita::verifica(
  'fornitore indicato ma nomina non registrata: la descrizione tecnica lo dichiara',
  str_contains($temaReso('psiphos_modello_dpia'), 'resta senza presidio organizzativo')
);
EsitiConformita::verifica(
  "e senza riscontro richiama l'ipotesi dell'art. 36",
  str_contains($temaReso('psiphos_modello_dpia'), 'art. 36')
);
EsitiConformita::verifica(
  'e il registro segnala la nomina mancante fra i destinatari',
  str_contains($temaReso('psiphos_modello_registro'), 'Atto di nomina non registrato')
);
$requisito = $requisitoLocalizzazione();
EsitiConformita::verifica(
  "e l'attestazione dà atto che nessun atto è registrato",
  str_contains($requisito['nota'] ?? '', 'nessun atto registrato')
);

$impostazioniHosting->set('hosting', $hostingOriginale)->save();
drupal_flush_all_caches();
EsitiConformita::verifica(
  'i modelli restano producibili in PDF/A',
  str_starts_with($modelloReso('registro-art-30'), '%PDF')
    && str_starts_with($modelloReso('richieste-al-fornitore-hosting', 'nomina'), '%PDF')
);

printf("\n--- %d superate, %d fallite ---\n", EsitiConformita::$superate, EsitiConformita::$fallite);

$ripristina();
echo "configurazione ripristinata\n";
