<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Attestazione di conformità ai requisiti dell'allegato tecnico.
 *
 * Il §9 impone alle istituzioni scolastiche di verificare preventivamente la
 * coerenza della soluzione adottata, acquisendo documentazione tecnica e una
 * dichiarazione di conformità del fornitore. Una dichiarazione generica non
 * assolve quell'obbligo: attesterebbe ciò che il modulo può fare, non ciò che
 * la singola installazione fa davvero. Questa attestazione legge la
 * configurazione in essere e riferisce lo stato effettivo di ciascun
 * requisito.
 *
 * Altrettanto importante è quanto il modulo non copre. Cifratura a riposo,
 * copie di sicurezza, segregazione degli ambienti, gestione degli incidenti,
 * valutazione d'impatto e Regolamento d'istituto restano in capo
 * all'istituzione scolastica, che di quei trattamenti è titolare. Tacerli
 * produrrebbe una dichiarazione rassicurante e falsa.
 */
final class AttestazioneConformita {

  use StringTranslationTrait;

  public const RESPONSABILITA_MODULO = 'modulo';

  public const RESPONSABILITA_ISTITUZIONE = 'istituzione';

  public const RESPONSABILITA_CONDIVISA = 'condivisa';

  public const STATO_ATTUATO = 'attuato';

  public const STATO_ATTENZIONE = 'attenzione';

  public const STATO_A_CARICO = 'a_carico';

  private const IMPOSTAZIONI = 'psiphos.settings';

  /**
   * Moduli che erogano un secondo fattore di autenticazione.
   *
   * Drupal da solo non ne ha: il secondo fattore arriva da un modulo, e senza
   * quel modulo l'impostazione resta un'intenzione. L'elenco serve a
   * riscontrare l'intenzione, non a imporne uno.
   */
  private const MODULI_SECONDO_FATTORE = [
    'tfa' => 'TFA',
    'two_factor_authentication' => 'Two-factor Authentication',
    'google_authenticator_login' => 'Google Authenticator Login',
    'mfa' => 'MFA',
    'webauthn' => 'WebAuthn',
  ];

  /**
   * Moduli che erogano l'autenticazione forte.
   */
  private const MODULI_AUTENTICAZIONE_FORTE = [
    'spid' => 'SPID',
    'cie' => 'CIE',
    'openid_connect' => 'OpenID Connect',
    'simplesamlphp_auth' => 'SAML',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configurazione,
    private readonly ConservazioneDocumento $conservazione,
    private readonly RegistroAudit $registro,
    private readonly ModuleExtensionList $moduli,
    private readonly TimeInterface $orologio,
    private readonly RequestStack $richieste,
    private readonly ModuleHandlerInterface $gestoreModuli,
    private readonly StateInterface $stato,
  ) {}

  /**
   * Attestazione completa.
   *
   * @return array<string, mixed>
   */
  public function attestazione(): array {
    $requisiti = $this->requisiti();

    return [
      'formato' => 'psiphos-conformita-v1',
      'prodotto' => [
        'denominazione' => 'Psíphos',
        'versione' => $this->versione(),
        'natura' => (string) $this->t("Modulo Drupal installato sull'infrastruttura dell'istituzione scolastica. Non è un servizio erogato in cloud: i dati di seduta non lasciano il sistema informativo della scuola e non transitano per sistemi del fornitore del modulo. Ciò non esclude la presenza di responsabili del trattamento: chi fornisce l'hosting e chi ha in carico la manutenzione del sito accedono ai dati per conto dell'istituzione e vanno nominati ai sensi dell'art. 28 GDPR."),
      ],
      'fornitore' => [
        'denominazione' => (string) $this->configurazione->get(self::IMPOSTAZIONI)->get('fornitore.denominazione'),
        'partita_iva' => (string) $this->configurazione->get(self::IMPOSTAZIONI)->get('fornitore.partita_iva'),
        'contatto' => (string) $this->configurazione->get(self::IMPOSTAZIONI)->get('fornitore.contatto'),
      ],
      'istituzione' => (string) $this->configurazione->get('system.site')->get('name'),
      'riferimento' => 'Nota MIM prot. 3803 del 30/06/2026 e allegato tecnico «Requisiti tecnico-organizzativi per la gestione digitale degli organi collegiali e delle operazioni di voto nelle istituzioni scolastiche»',
      'prodotta_il' => date('c', $this->orologio->getRequestTime()),
      'requisiti' => $requisiti,
      'riepilogo' => $this->riepilogo($requisiti),
    ];
  }

  /**
   * Requisito per requisito, con attuazione e stato effettivo.
   *
   * @return array<int, array<string, string>>
   */
  public function requisiti(): array {
    $impostazioni = $this->configurazione->get('psiphos.settings');
    $livello = (string) $impostazioni->get('autenticazione.livello');
    $conservazioneDisponibile = $this->conservazione->conservazioneDisponibile();
    $catenaIntegra = $this->catenePerfette();
    $fileRiservati = (bool) Settings::get('file_private_path');
    $aggiornamenti = $this->statoAggiornamenti();
    $autenticazione = $this->statoAutenticazione($livello, (string) $impostazioni->get('autenticazione.provider_forte'));
    $accessi = $this->statoRegistrazioneAccessi();
    $conformita = $this->statoVerificaConformita();

    return [
      $this->requisito('§2', 'Identificazione e responsabilità',
        'Identificazione certa dei partecipanti e riconducibilità delle azioni agli utenti autenticati, derogabile solo nel voto segreto.',
        "Ogni operazione è compiuta da un'utenza autenticata e annotata nel registro delle tracciature. La deroga opera nella sola urna segreta, che non conserva alcun riferimento al votante.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§2', 'Validità giuridica',
        'Piena efficacia delle deliberazioni, conformità ai regolamenti e corretta formazione della volontà collegiale.',
        "Quorum costitutivo e maggioranze sono calcolati su denominatori congelati all'apertura. La seduta riporta l'articolo del Regolamento d'istituto che la legittima. La correttezza del Regolamento resta però da accertare in sede propria.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO),

      $this->requisito('§2', 'Integrità e sicurezza',
        'Immodificabilità dei dati e dei risultati delle votazioni, protezione da accessi non autorizzati, alterazioni e perdite.',
        'Le votazioni chiuse non sono riapribili, i verbali sigillati non sono modificabili, urna e tracciature portano impronte verificabili. La protezione perimetrale dell\'infrastruttura è dell\'istituzione.',
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO),

      $this->requisito('§2', 'Trasparenza e verificabilità',
        'Ricostruzione e verifica ex post del procedimento deliberativo attraverso evidenze documentali e tracciature tecniche.',
        'Verbale sigillato con esportazione strutturata come evidenza documentale; registro concatenato delle tracciature come tracciatura tecnica. Entrambi verificabili in qualunque momento.',
        self::RESPONSABILITA_MODULO,
        $catenaIntegra ? self::STATO_ATTUATO : self::STATO_ATTENZIONE,
        $catenaIntegra ? '' : "Una o più catene di tracciature risultano interrotte: verificare in /admin/reports/status."),

      $this->requisito('§2', 'Segretezza del voto',
        'Anonimato effettivo, non reversibile e tecnicamente dimostrabile, tale da escludere qualsiasi correlazione fra identità del votante e voto espresso.',
        "Attestazione di voto e scheda risiedono in tabelle prive di colonne comuni; né l'una né l'altra portano marche temporali; le schede hanno identificativo casuale come chiave primaria, così l'ordine di memorizzazione non conserva quello di deposito. Il principio chiede però di escludere «qualsiasi» correlazione, e un'applicazione non può escludere ciò che avviene sotto di sé: resta il margine residuo descritto al §4.3, accessibile a chi disponga dei registri del motore di banca dati.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "Il margine residuo è lo stesso dichiarato al §4.3 e va contenuto con le medesime misure organizzative: non è un secondo rilievo, è lo stesso letto dal lato del principio."),

      $this->requisito('§3.1', 'Identificazione degli utenti',
        'Identità digitali univoche, non condivise e coerenti con il CAD; corrispondenza fra identità digitale e avente diritto.',
        "L'elenco degli aventi diritto associa ciascuna posizione a un'utenza nominativa del sito. Nessun accesso anonimo o condiviso è ammesso in aula.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "Spetta all'istituzione garantire che le utenze siano personali e non condivise."),

      $this->requisito('§3.2', 'Autenticazione',
        'Meccanismi adeguati al livello di rischio, privilegiando ove possibile modalità di autenticazione forte.',
        $autenticazione['riferisce'],
        self::RESPONSABILITA_ISTITUZIONE,
        $autenticazione['stato'],
        $autenticazione['nota']),

      $this->requisito('§3.3', 'Autorizzazioni e gestione dei ruoli',
        'Chiara distinzione dei ruoli e accesso alle sole funzionalità strettamente necessarie; tracciabilità delle operazioni.',
        'Permessi distinti per convocare, presiedere, verbalizzare, partecipare, consultare i verbali, esportare gli esiti e consultare le tracciature. Il ruolo è verificato sulla singola seduta: presiedere non abilita a presiedere qualunque seduta.',
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§3.4', 'Gestione delle sessioni',
        'Tracciamento delle sessioni attive, interruzione automatica per inattività, prevenzione di accessi simultanei non autorizzati.',
        sprintf(
          "Presenza decaduta dopo %d minuti di assenza di contatto dal dispositivo: finché la pagina dell'aula resta aperta la presenza si mantiene, quando cessa di farsi viva decade. La disconnessione dal sito fa uscire immediatamente dall'aula. Sessione %s: l'ingresso da un nuovo dispositivo revoca l'accreditamento del precedente.",
          (int) round(((int) $impostazioni->get('sessione.timeout_inattivita')) / 60),
          $impostazioni->get('sessione.sessione_esclusiva') ? 'esclusiva attiva' : 'esclusiva disattivata'
        ),
        self::RESPONSABILITA_MODULO,
        $impostazioni->get('sessione.sessione_esclusiva') ? self::STATO_ATTUATO : self::STATO_ATTENZIONE,
        $impostazioni->get('sessione.sessione_esclusiva') ? '' : 'La disattivazione va motivata e documentata.'),

      $this->requisito('§4.1', 'Requisiti generali del voto',
        'Un solo voto per avente diritto, registrazione integra e non modificabile, esito determinato in modo corretto e verificabile, rispetto dei quorum.',
        "L'unicità è imposta da una chiave primaria composta, non da un controllo applicativo. Tipo di voto, scheda e regola di maggioranza si bloccano all'apertura dell'urna. Lo scrutinio si arresta se schede e votanti non coincidono.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§4.2', 'Voto palese',
        'Piena tracciabilità del voto, associazione fra votante e scelta espressa, disponibilità dei risultati per verbalizzazione e conservazione.',
        'I voti palesi sono conservati con il nominativo del votante e riportati per esteso nel verbale.',
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§4.3', 'Voto a scrutinio segreto',
        'Separazione strutturale fra autenticazione ed espressione del voto, anonimizzazione effettiva, non accessibilità anche per amministratori e fornitori, trattamento dei metadati, verificabilità dell\'esito.',
        implode(' ', [
          (string) $this->t("SEPARAZIONE. Partecipazione e scheda sono scritte nella stessa transazione ma in due tabelle che non condividono alcuna colonna: l'una registra chi ha votato, l'altra che cosa è stato votato, e non esiste alcun dato che le colleghi. O esistono entrambe o non esiste nessuna delle due."),
          (string) $this->t("ANONIMATO. La scheda porta una chiave primaria casuale a 62 bit, per cui l'ordine in cui è conservata non è quello in cui è stata deposta. Le preferenze sono ridotte a forma canonica ordinata, così l'ordine di spunta non viene conservato."),
          (string) $this->t("METADATI. Né la scheda né l'attestazione di partecipazione portano una marca temporale: l'istante del voto resta nelle sole tracciature, che non toccano la scheda."),
          (string) $this->t("VERIFICABILITÀ. Il sigillo dell'urna è calcolato sull'insieme ordinato delle schede: consente di ricontare e di accorgersi di qualsiasi scheda aggiunta, rimossa o alterata, senza introdurre alcuna sequenza."),
          (string) $this->t("NON ACCESSIBILITÀ. Resta un margine residuo: chi disponga dei registri del motore di banca dati, e non del solo accesso alle tabelle, può leggervi l'ordine di scrittura delle schede e accostarlo alle tracciature. Su hosting condiviso quei registri sono del fornitore."),
        ]),
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "Il margine residuo non è eliminabile da un'applicazione, perché nasce sotto di essa: va contenuto con misure organizzative sull'accesso alla banca dati e ai suoi registri, richiamato nell'atto di nomina del fornitore dell'hosting e dichiarato nella valutazione d'impatto."),

      $this->requisito('§5', 'Cifratura dei dati',
        'Cifratura dei dati.',
        sprintf(
          '%s %s',
          (string) $this->t("Il modulo non conserva in chiaro alcun segreto: le impronte di sessione sono calcolate con chiave, l'urna non contiene dati personali."),
          $this->cifraturaDelCanale()
        ),
        self::RESPONSABILITA_CONDIVISA, self::STATO_A_CARICO,
        (string) $this->t("Restano a carico dell'istituzione la cifratura dei supporti di archiviazione e quella delle copie di sicurezza. Su hosting condiviso la cifratura a riposo non è verificabile dal cliente: va richiesta al fornitore in sede di nomina a responsabile del trattamento e dichiarata come rischio residuo nella valutazione d'impatto. La cifratura delle copie di sicurezza è invece sempre attuabile da chi le produce.")),

      $this->requisito('§5', 'Aggiornamenti e vulnerabilità note',
        'Protezione da vulnerabilità note e aggiornamenti periodici del sistema.',
        sprintf(
          '%s %s',
          (string) $this->t('Il modulo segue il ciclo di aggiornamento di Drupal 10 e 11.'),
          $aggiornamenti['riferisce']
        ),
        self::RESPONSABILITA_CONDIVISA,
        $aggiornamenti['stato'],
        (string) $this->t("L'applicazione degli aggiornamenti è un'attività continuativa e non un adempimento che si esaurisca: nessuna verifica automatica può attestarla per il futuro. L'istituzione ne resta titolare, ma l'esecuzione spetta di norma a chi ha in carico la manutenzione del sito, e va assegnata per iscritto nel relativo contratto insieme alla periodicità e ai tempi di intervento sugli avvisi di sicurezza.")),

      // Il §5 elenca separatamente la registrazione degli accessi e la
      // disponibilità di sistemi di audit. Tenerle in una riga sola nascondeva
      // che il modulo risponde pienamente alla seconda e solo in parte alla
      // prima: chi controlla l'attestazione scorre l'allegato voce per voce, e
      // una voce che non trova è un rilievo.
      $this->requisito('§5', 'Monitoraggio e registrazione degli accessi',
        'Monitoraggio e registrazione degli accessi.',
        $accessi['riferisce'],
        self::RESPONSABILITA_CONDIVISA,
        $accessi['stato'],
        $accessi['nota']),

      $this->requisito('§5', 'Disponibilità di sistemi di audit',
        'Disponibilità di sistemi di audit.',
        "Registro concatenato delle tracciature per ciascuna seduta: ogni annotazione porta l'impronta della precedente, così una riga rimossa o alterata rompe la catena e la rottura si vede. Consultabile, esportabile e verificato automaticamente nel rapporto sullo stato del sito.",
        self::RESPONSABILITA_MODULO,
        $catenaIntegra ? self::STATO_ATTUATO : self::STATO_ATTENZIONE),

      $this->requisito('§5', 'Segregazione degli ambienti',
        'Segregazione degli ambienti.',
        'Non attuabile a livello di modulo.',
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        'Da attuare tenendo distinti gli ambienti di sviluppo, prova ed esercizio, e non popolando gli ambienti non di esercizio con dati reali di seduta.'),

      $this->requisito('§5', 'Gestione degli incidenti di sicurezza',
        'Gestione degli incidenti di sicurezza.',
        "Il modulo espone gli elementi che rendono un incidente accertabile anziché opinabile: sigillo dell'urna, impronte del verbale e dell'atto, catena delle tracciature. Stabilire se un incidente sia avvenuto è possibile; rilevarlo, classificarlo e notificarlo nei termini di legge è procedura organizzativa.",
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Da attuare con procedura scritta che indichi chi accerta, entro quanto e verso chi si notifica. Il termine di 72 ore dell'art. 33 GDPR decorre dalla conoscenza dell'incidente."),

      // Continuità operativa e copie di sicurezza sono un capoverso a sé
      // nell'allegato, non una delle sei misure: tenerle insieme alla gestione
      // degli incidenti faceva sparire una prescrizione dall'elenco.
      $this->requisito('§5', 'Continuità operativa, copie di sicurezza e ripristino',
        'Misure di continuità operativa, inclusi sistemi di backup e procedure di ripristino.',
        "Fuori dalla portata del modulo: riguardano l'infrastruttura che lo ospita. Vale però sapere che cosa si sta salvando: la banca dati contiene il registro dei voti palesi e i nominativi di tutti i presenti a ogni seduta, mentre i verbali sigillati e gli estratti di delibera stanno nei file riservati. Una copia che prendesse solo l'una o solo gli altri non consentirebbe di ripristinare nulla di utilizzabile.",
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Da attuare con copie che comprendano banca dati e file riservati, cifrate, e con prova periodica del ripristino: una copia mai ripristinata non è una copia."),

      $this->requisito('§5', 'Localizzazione dei dati e servizi di terzi',
        'Per piattaforme cloud o servizi di terze parti: verifica della localizzazione dei dati, delle misure del fornitore e della conformità alle normative europee.',
        "Il modulo non invia dati ad alcun servizio esterno: le operazioni di voto si svolgono per intero nel sito dell'istituzione. Il sito però risiede su infrastruttura di un fornitore, che nella quasi totalità dei casi è un hosting condiviso: la verifica prescritta — dove risiedono fisicamente i dati, quali misure il fornitore dichiara, se sono conformi alle normative europee — riguarda anche quel fornitore, non solo lo strumento di videoconferenza. Che i dati non lascino il sito non significa che restino in casa dell'istituzione.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_A_CARICO,
        "Da attuare acquisendo dal fornitore dell'hosting la dichiarazione sulla localizzazione dei dati e sulle misure di sicurezza, e conservandola agli atti insieme all'atto di nomina. " . $this->riferimentiInfrastruttura()),

      $this->requisito('§6', 'Minimizzazione e limitazione della conservazione',
        'Trattamento limitato ai dati strettamente necessari; limitazione delle finalità e della conservazione.',
        sprintf(
          "Dati personali trattati: l'utenza dell'avente diritto, i suoi ingressi e uscite dall'aula, la partecipazione a ciascuna votazione e — sul solo voto palese — la scelta espressa. Nessun indirizzo di rete, nessun identificativo di dispositivo, nessun agente utente. L'urna segreta non conserva dati personali. Delle sessioni si conserva l'impronta e mai l'identificativo, e l'impronta è azzerata alla chiusura della seduta. Le tracciature sono rimosse dopo %d giorni dalla chiusura, e solo su sedute già verbalizzate.",
          (int) $impostazioni->get('audit.ritenzione_giorni')
        ),
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "Un campo va sorvegliato: la giustificazione dell'assenza è testo libero, e chi verbalizza potrebbe annotarvi il motivo di salute che l'ha determinata. Diventerebbe un dato particolare ai sensi dell'art. 9 GDPR, conservato dentro un verbale destinato a durare e immodificabile una volta sigillato. Va istruito chi redige a indicare la sola qualificazione formale — assenza giustificata — e non la ragione."),

      // Il §6 elenca fra gli aspetti da considerare anche le misure di
      // sicurezza e le garanzie contrattuali: sono voci proprie dell'allegato,
      // e senza una riga dedicata chi controlla non le trova.
      $this->requisito('§6', 'Misure tecniche e organizzative adeguate',
        'Misure adeguate, fra cui cifratura, pseudonimizzazione o anonimizzazione ove necessario, controllo degli accessi, backup e disaster recovery.',
        "Il modulo attua l'anonimizzazione dove il trattamento lo richiede: la scheda segreta non è pseudonimizzata ma priva di qualunque riferimento al votante, e non esiste chiave che consenta di ricostruirlo. Il controllo degli accessi è per ruolo e per singola seduta. Cifratura, copie di sicurezza e ripristino riguardano l'infrastruttura e sono trattati al §5.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "La parte infrastrutturale — cifratura dei supporti, copie di sicurezza, ripristino — resta a carico dell'istituzione e dei suoi fornitori: qui si attesta soltanto quanto il modulo attua per proprio conto."),

      $this->requisito('§6', 'Garanzie contrattuali sul trattamento',
        'Disponibilità di adeguate garanzie contrattuali in relazione al trattamento dei dati.',
        "Non attuabile a livello di modulo: le garanzie stanno nei contratti, non nel software. Riguardano ogni soggetto che tratti dati per conto dell'istituzione — hosting, manutentore del sito, fornitore della videoconferenza — e vanno rese nella forma prevista dall'art. 28, paragrafo 3, del GDPR: oggetto e durata, natura e finalità, tipi di dati e categorie di interessati, obblighi e diritti del titolare.",
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Da attuare insieme all'atto di nomina: la nomina senza le clausole dell'art. 28 §3 è un documento incompleto."),

      $this->requisito('§6', 'Ruoli privacy e responsabile del trattamento',
        'Definizione dei ruoli privacy; nomina a responsabile del trattamento del fornitore esterno che tratti dati per conto dell\'istituzione.',
        "Il modulo non trasmette dati ad alcun sistema esterno: non introduce di per sé un responsabile del trattamento. Ne introducono però le condizioni in cui è esercitato. Sono responsabili da nominare ai sensi dell'art. 28 GDPR: il fornitore dell'hosting, che detiene banca dati e file riservati; chiunque abbia in carico la manutenzione del sito, che vi accede con privilegi amministrativi e ne produce le copie di sicurezza; il fornitore dello strumento di videoconferenza. La nomina non è una formalità: definisce e delimita gli obblighi di ciascuno, e la sua assenza li lascia indeterminati.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_A_CARICO,
        "Da attuare con atto di nomina sottoscritto per ciascun soggetto, prima della prima seduta deliberativa."),

      $this->requisito('§6', 'Valutazione d\'impatto',
        'Valutazione d\'impatto sulla protezione dei dati, in particolare per i sistemi di voto digitale.',
        "Il modulo fornisce la descrizione tecnica del trattamento; la valutazione è dell'istituzione, che ne è titolare.",
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Elementi tecnici disponibili in documentazione/dpia-elementi.md."),

      $this->requisito('§7', 'Produzione dei documenti',
        'Documentazione delle attività mediante verbali digitali, registrazione degli esiti di voto ed evidenze delle operazioni svolte.',
        "Ogni seduta produce un verbale con registro delle presenze, ordine del giorno, esiti e scrutini; ogni deliberazione conclusa produce un estratto autonomo; ogni operazione del procedimento è annotata nella catena delle tracciature. Verbali ed estratti in PDF/A, esportazioni ed evidenze in formato strutturato.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§7', 'Immodificabilità e metadati',
        'Documenti immodificabili, completi e associati ai metadati necessari a garantirne autenticità, integrità e contestualizzazione.',
        "Il verbale sigillato non è modificabile in alcuna parte, nemmeno da un amministratore. Al sigillo la sua esportazione strutturata è serializzata una volta sola e conservata: l'impronta è calcolata su quei byte e il documento è generato dagli stessi, così che l'attestazione resti ripetibile nel tempo. Ogni documento porta i metadati previsti dalle Linee guida AgID: identificativo, tipologia documentale, data di chiusura, modalità di formazione, oggetto e soggetto produttore, oltre alle impronte e al riferimento normativo e regolamentare che lo contestualizzano.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO,
        "La corrispondenza fra questi metadati e quelli richiesti dal sistema di conservazione prescelto va concordata con il conservatore prima del primo versamento: l'insieme minimo è normativo, la sua rappresentazione dipende dalle specifiche del conservatore."),

      $this->requisito('§7', 'Formato di conservazione',
        'Formato idoneo ad assicurare nel tempo leggibilità e integrità, secondo le Linee guida AgID.',
        $conservazioneDisponibile
          ? 'Verbali ed estratti di delibera sono prodotti in PDF/A-2B e archiviati fra i file riservati, in cartelle distinte.'
          : sprintf('Verbali ed estratti di delibera sono prodotti in PDF ordinario. %s', (string) $this->conservazione->impedimento()),
        self::RESPONSABILITA_MODULO,
        $conservazioneDisponibile && $fileRiservati ? self::STATO_ATTUATO : self::STATO_ATTENZIONE,
        $conservazioneDisponibile
          ? ''
          : 'Installare Ghostscript oppure segnalare il formato al responsabile della conservazione.'),

      // Produrre un documento nel formato giusto non è conservarlo. La
      // conservazione a norma è un processo con un responsabile, un manuale e
      // un conservatore: attestarla come attuata perché il PDF è un PDF/A
      // sarebbe la sovradichiarazione più facile da smontare.
      $this->requisito('§7', 'Conservazione a norma e versamento',
        'Conservazione nel rispetto delle Linee guida AgID, assicurando nel tempo autenticità, integrità, leggibilità e reperibilità.',
        "Il modulo produce documenti conservabili e le evidenze che ne attestano autenticità e integrità; non li conserva. La conservazione a norma è un processo distinto, con un responsabile della conservazione, un manuale e un pacchetto di versamento verso un conservatore, e resta dell'istituzione. I documenti restano nel frattempo fra i file riservati del sito, che non è un sistema di conservazione.",
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Da attuare individuando il responsabile della conservazione, adottando il manuale e concordando con il conservatore accreditato il pacchetto di versamento e i metadati. Fino ad allora i documenti sono conservabili ma non conservati."),

      $this->requisito('§7', 'Esportazione strutturata',
        'Esportazione strutturata dei risultati o, in mancanza, produzione di evidenze documentali idonee.',
        "Ogni verbale esporta i byte che conserva, dai quali l'impronta del contenuto è ricalcolabile anche fuori dal sito con un qualunque strumento. Ogni estratto di delibera ha una propria esportazione e una propria impronta, verificabile da chi lo riceve senza disporre del verbale. Le tracciature sono esportabili separatamente.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§8', 'Regolamento d\'istituto',
        'Adeguamento dei regolamenti, con disciplina puntuale delle sedute a distanza e delle modalità di voto, con particolare riferimento al voto segreto.',
        'Ogni seduta richiede il riferimento all\'articolo del Regolamento che la legittima; il dato compare nel verbale. L\'adozione del Regolamento è dell\'istituzione.',
        self::RESPONSABILITA_ISTITUZIONE, self::STATO_A_CARICO,
        "Bozza di articolo e procedura di adozione nella documentazione del modulo, consultabile in /admin/reports/psiphos/documentazione."),

      $this->requisito('§8', 'Individuazione delle figure responsabili',
        'Individuazione delle figure responsabili della conduzione della seduta e della verbalizzazione.',
        "Presidente e segretario verbalizzante sono dati obbligatori della convocazione, e le rispettive funzioni sono esercitabili solo da chi vi è designato: il permesso di presiedere non abilita a presiedere qualunque seduta.",
        self::RESPONSABILITA_CONDIVISA, self::STATO_ATTUATO,
        "Il modulo verifica che una designazione ci sia e che sia rispettata, non che sia conforme all'ordinamento scolastico: che il Collegio dei docenti sia presieduto dal dirigente o da un collaboratore validamente delegato è condizione di validità della deliberazione, e resta da accertare in sede propria."),

      $this->requisito('§8', 'Gestione dei malfunzionamenti',
        'Procedure per la gestione dei malfunzionamenti, inclusa la sospensione delle operazioni di voto e, se necessario, la loro ripetizione.',
        "Sospensione e annullamento di una votazione richiedono una motivazione scritta e sono tracciati. La ripetizione apre una nuova votazione che conserva il riferimento a quella annullata: nessun esito registrato viene mai riscritto. La seduta non può essere chiusa finché una votazione è aperta o sospesa, e su una seduta chiusa nessuna scheda è accettata. Le note procedurali della convocazione raccolgono i malfunzionamenti che non hanno comportato sospensione, e compaiono nel verbale.",
        self::RESPONSABILITA_MODULO, self::STATO_ATTUATO),

      $this->requisito('§9', 'Verifica di conformità',
        'Verifica preventiva della coerenza della soluzione ai requisiti, con documentazione tecnica e dichiarazione di conformità del fornitore.',
        $conformita['riferisce'],
        self::RESPONSABILITA_CONDIVISA, $conformita['stato'], $conformita['nota']),
    ];
  }

  /**
   * Conteggi per stato.
   *
   * @param array<int, array<string, string>> $requisiti
   *
   * @return array<string, int>
   */
  public function riepilogo(array $requisiti): array {
    $riepilogo = [
      self::STATO_ATTUATO => 0,
      self::STATO_ATTENZIONE => 0,
      self::STATO_A_CARICO => 0,
    ];

    foreach ($requisiti as $requisito) {
      $riepilogo[$requisito['stato']]++;
    }

    return $riepilogo;
  }

  /**
   * @return array<string, string>
   */
  /**
   * Che cosa risulta agli atti sul fornitore dell'infrastruttura.
   *
   * Il requisito resta a carico dell'istituzione anche quando questi dati sono
   * compilati, e deve restarci: qui non si osserva dove risiedano i dati né se
   * una misura sia in essere, si riferisce quale soggetto è stato individuato e
   * con quali atti lo si è documentato. È la differenza fra attestare che una
   * verifica è stata fatta e attestarne l'esito — la prima è alla portata del
   * sistema, la seconda no.
   */
  private function riferimentiInfrastruttura(): string {
    $hosting = $this->configurazione->get(self::IMPOSTAZIONI)->get('hosting') ?? [];
    $denominazione = trim((string) ($hosting['denominazione'] ?? ''));

    if ($denominazione === '') {
      return (string) $this->t("Nelle impostazioni del modulo non è indicato alcun fornitore dell'infrastruttura: finché non lo si individua, questo requisito non è riferibile ad alcun soggetto determinato.");
    }

    $atti = [];
    if (trim((string) ($hosting['nomina_protocollo'] ?? '')) !== '' || trim((string) ($hosting['nomina_data'] ?? '')) !== '') {
      $atti[] = (string) $this->t('atto di nomina @riferimento', [
        '@riferimento' => $this->riferimentoAtto((string) ($hosting['nomina_protocollo'] ?? ''), (string) ($hosting['nomina_data'] ?? '')),
      ]);
    }
    if (trim((string) ($hosting['riscontro_protocollo'] ?? '')) !== '' || trim((string) ($hosting['riscontro_data'] ?? '')) !== '') {
      $atti[] = (string) $this->t('riscontro alla richiesta di documentazione @riferimento', [
        '@riferimento' => $this->riferimentoAtto((string) ($hosting['riscontro_protocollo'] ?? ''), (string) ($hosting['riscontro_data'] ?? '')),
      ]);
    }

    $ubicazione = trim((string) ($hosting['ubicazione_dati'] ?? ''));

    return (string) $this->t('Risulta agli atti: fornitore individuato in @nome; @atti@ubicazione', [
      '@nome' => $denominazione,
      '@atti' => $atti === []
        ? (string) $this->t('nessun atto registrato, né la nomina né il riscontro')
        : implode('; ', $atti),
      '@ubicazione' => $ubicazione === ''
        ? (string) $this->t('. Ubicazione dei dati non dichiarata.')
        : (string) $this->t('. Ubicazione dei dati dichiarata dal fornitore: @paese. Il dato è riferito, non verificato.', ['@paese' => $ubicazione]),
    ]);
  }

  /**
   * Protocollo e data di un atto, nella forma in cui un atto se ne cita un altro.
   */
  private function riferimentoAtto(string $protocollo, string $data): string {
    $parti = [];
    if (trim($protocollo) !== '') {
      $parti[] = (string) $this->t('prot. @n', ['@n' => trim($protocollo)]);
    }
    if (trim($data) !== '') {
      $tempo = strtotime(trim($data));
      $parti[] = (string) $this->t('del @d', [
        '@d' => $tempo === FALSE ? trim($data) : date('d/m/Y', $tempo),
      ]);
    }

    return implode(' ', $parti);
  }

  private function requisito(
    string $paragrafo,
    string $titolo,
    string $richiesta,
    string $attuazione,
    string $responsabilita,
    string $stato,
    string $nota = '',
  ): array {
    return [
      'paragrafo' => $paragrafo,
      'titolo' => $titolo,
      'richiesta' => $richiesta,
      'attuazione' => $attuazione,
      'responsabilita' => $responsabilita,
      'stato' => $stato,
      'nota' => $nota,
    ];
  }

  /**
   * Stato della verifica di conformità prevista dal §9.
   *
   * Il §9 pone due atti in capo a due soggetti diversi: l'istituzione verifica
   * preventivamente, il fornitore rende una dichiarazione di conformità.
   * Questa attestazione è lo strumento di entrambi, ma non è né l'uno né
   * l'altro: finché nessuno la sottoscrive resta un rapporto tecnico, e un
   * rapporto tecnico non è la dichiarazione che il §9 chiede di acquisire.
   *
   * Una dichiarazione che non identifichi chi la rende, poi, non assolve
   * l'obbligo: «da parte del fornitore o partner tecnologico» presuppone che
   * il fornitore sia individuabile.
   *
   * @return array{riferisce: string, stato: string, nota: string}
   */
  private function statoVerificaConformita(): array {
    $fornitore = trim((string) $this->configurazione->get(self::IMPOSTAZIONI)->get('fornitore.denominazione'));

    $strumento = (string) $this->t("La presente attestazione riferisce la configurazione in essere, requisito per requisito, ed è esportabile e stampabile nella forma da sottoscrivere. La documentazione tecnica che il §9 chiede di acquisire è consultabile e scaricabile dal sito in @percorso: comprende la descrizione tecnica della soluzione, le richieste da rivolgere ai fornitori, i modelli di atto di nomina, la bozza di articolo per il Regolamento d'istituto e gli elementi per la valutazione d'impatto.", ['@percorso' => '/admin/reports/psiphos/documentazione']);

    if ($fornitore === '') {
      return [
        'riferisce' => $strumento . ' ' . (string) $this->t('Non è però indicato il fornitore che la sottoscrive: la dichiarazione risulterebbe resa da soggetto non identificato.'),
        'stato' => self::STATO_ATTENZIONE,
        'nota' => (string) $this->t('Indicare denominazione, partita IVA e recapito del fornitore in @percorso, quindi stampare la dichiarazione, sottoscriverla e consegnarla all\'istituzione prima della prima seduta deliberativa.', ['@percorso' => '/admin/config/psiphos']),
      ];
    }

    return [
      'riferisce' => $strumento . ' ' . (string) $this->t('Il fornitore che la sottoscrive è @fornitore.', ['@fornitore' => $fornitore]),
      'stato' => self::STATO_A_CARICO,
      'nota' => (string) $this->t("Resta da compiere quel che nessun documento può compiere da sé: il fornitore sottoscrive la dichiarazione, l'istituzione la acquisisce e ne prende atto, entrambe le firme sono datate e il documento è protocollato prima della prima seduta deliberativa. Una verifica successiva al suo oggetto non è preventiva."),
    ];
  }

  /**
   * Stato osservabile della registrazione degli accessi.
   *
   * Il modulo registra gli ingressi in aula, non gli accessi al sito: chi
   * entra, quando, da quale sessione. Ma l'autenticazione avviene prima
   * dell'aula, e i tentativi falliti non arrivano mai fino a lì. Quella parte
   * la registra Drupal, e solo se un canale di registrazione è attivo:
   * disattivarlo — cosa che qualche hosting fa per alleggerire il carico —
   * lascia il sito senza alcuna traccia di chi vi sia entrato.
   *
   * @return array{riferisce: string, stato: string, nota: string}
   */
  private function statoRegistrazioneAccessi(): array {
    $canali = [];
    foreach (['dblog' => 'Database Logging', 'syslog' => 'Syslog'] as $modulo => $denominazione) {
      if ($this->gestoreModuli->moduleExists($modulo)) {
        $canali[] = $denominazione;
      }
    }

    $tracciature = (string) $this->t("Il modulo registra in proprio gli ingressi e le uscite dall'aula, la decadenza delle presenze e la sostituzione di sessione, con utente e istante, nella catena delle tracciature.");

    if ($canali === []) {
      return [
        'riferisce' => $tracciature . ' ' . (string) $this->t("Gli accessi al sito — autenticazioni riuscite e tentativi falliti — avvengono però prima dell'aula, e su questo sito non risulta attivo alcun canale di registrazione di Drupal: di chi si è autenticato non resta traccia."),
        'stato' => self::STATO_ATTENZIONE,
        'nota' => (string) $this->t("Attivare il modulo «Database Logging» oppure «Syslog»: senza, un accesso non autorizzato non è ricostruibile e la registrazione degli accessi prescritta dal §5 non esiste."),
      ];
    }

    return [
      'riferisce' => $tracciature . ' ' . (string) $this->t('Gli accessi al sito sono registrati da Drupal tramite @canali.', ['@canali' => implode(', ', $canali)]),
      'stato' => self::STATO_ATTUATO,
      'nota' => (string) $this->t("La conservazione di quelle registrazioni è configurata in Drupal e non dal modulo: va allineata al termine adottato per le tracciature delle sedute, altrimenti l'una sopravvive all'altra e la ricostruzione si interrompe a metà."),
    ];
  }

  /**
   * Stato osservabile del livello di autenticazione.
   *
   * L'impostazione dichiara il livello che l'istituzione intende adottare; da
   * sola non lo eroga. Riportarla come se fosse un fatto — «account personale
   * con secondo fattore» — significa affermare in un documento firmato
   * l'esistenza di una misura che nessuno ha riscontrato, e il §3.2 riguarda
   * proprio la prevenzione dell'impersonificazione. Qui si distingue quel che
   * è dichiarato da quel che risulta installato.
   *
   * @return array{riferisce: string, stato: string, nota: string}
   */
  public function statoAutenticazione(string $livello, string $provider): array {
    if ($livello === 'forte') {
      $attivo = $provider !== '' && $this->gestoreModuli->moduleExists($provider);
      $denominazione = self::MODULI_AUTENTICAZIONE_FORTE[$provider] ?? $provider;

      return $attivo
        ? [
          'riferisce' => (string) $this->t('Autenticazione forte dichiarata tramite @provider, il cui modulo risulta installato e attivo.', ['@provider' => $denominazione]),
          'stato' => self::STATO_ATTUATO,
          'nota' => (string) $this->t("Che il modulo sia attivo non implica che ogni avente diritto vi acceda per suo tramite: la configurazione dei percorsi di accesso resta dell'istituzione."),
        ]
        : [
          'riferisce' => (string) $this->t("Dichiarata autenticazione forte tramite @provider, ma il modulo corrispondente non risulta attivo su questo sito: il livello dichiarato non è erogato.", ['@provider' => $denominazione]),
          'stato' => self::STATO_ATTENZIONE,
          'nota' => (string) $this->t('Installare e attivare il modulo, oppure dichiarare il livello effettivamente in uso.'),
        ];
    }

    if ($livello === 'mfa') {
      $presenti = [];
      foreach (self::MODULI_SECONDO_FATTORE as $modulo => $denominazione) {
        if ($this->gestoreModuli->moduleExists($modulo)) {
          $presenti[] = $denominazione;
        }
      }

      return $presenti !== []
        ? [
          'riferisce' => (string) $this->t('Dichiarato account personale con secondo fattore. Risulta attivo @moduli, che può erogarlo.', ['@moduli' => implode(', ', $presenti)]),
          'stato' => self::STATO_ATTUATO,
          'nota' => (string) $this->t("Che il modulo sia attivo non implica che il secondo fattore sia obbligatorio per tutti gli aventi diritto: va imposto nella configurazione del modulo stesso e verificato prima della prima seduta."),
        ]
        : [
          'riferisce' => (string) $this->t("Dichiarato account personale con secondo fattore, ma su questo sito non risulta attivo alcun modulo che lo eroghi: gli aventi diritto accedono con la sola password. Il livello dichiarato non corrisponde a quello in essere."),
          'stato' => self::STATO_ATTENZIONE,
          'nota' => (string) $this->t("Attivare un modulo di secondo fattore, oppure correggere il livello dichiarato in @percorso: un livello dichiarato e non erogato è peggiore di un livello minimo dichiarato per quello che è.", ['@percorso' => '/admin/config/psiphos']),
        ];
    }

    return [
      'riferisce' => (string) $this->t('Account personale del sito, senza secondo fattore. È il livello minimo ammesso dal §3.1: credenziali personali non condivise.'),
      'stato' => self::STATO_ATTENZIONE,
      'nota' => (string) $this->t("Il §3.2 chiede di privilegiare, ove possibile, modalità di autenticazione forte: il livello minimo va motivato nel Regolamento d'istituto rispetto al livello di rischio."),
    ];
  }

  /**
   * Stato osservabile degli aggiornamenti di sicurezza.
   *
   * Quel che si può accertare è il presente: se il sito sappia riconoscere gli
   * aggiornamenti di sicurezza e se al momento ne risultino di non applicati.
   * Che vengano poi applicati con regolarità non è accertabile da qui — è un
   * impegno, e gli impegni si prendono per contratto, non si misurano.
   *
   * @return array{riferisce: string, stato: string}
   */
  private function statoAggiornamenti(): array {
    if (!$this->gestoreModuli->moduleExists('update')) {
      // Senza il modulo di aggiornamento il sito non viene avvisato delle
      // vulnerabilità note: non è una lacuna documentale, è cecità.
      return [
        'riferisce' => (string) $this->t("Il modulo «Update Manager» di Drupal non è attivo: questo sito non è in grado di accorgersi degli aggiornamenti di sicurezza pubblicati."),
        'stato' => self::STATO_ATTENZIONE,
      ];
    }

    $ultimoControllo = (int) $this->stato->get('update.last_check', 0);
    $quando = $ultimoControllo > 0
      ? (string) $this->t('Ultimo controllo il @data.', ['@data' => date('d/m/Y', $ultimoControllo)])
      : (string) $this->t('Nessun controllo risulta ancora eseguito.');

    $insicuri = $this->progettiInsicuri();

    if ($insicuri === NULL) {
      return [
        'riferisce' => (string) $this->t("I dati sugli aggiornamenti non sono al momento disponibili: il sito non riesce a raggiungere l'elenco delle versioni, oppure il controllo periodico non viene eseguito. @quando", [
          '@quando' => $quando,
        ]),
        'stato' => self::STATO_ATTENZIONE,
      ];
    }

    if ($insicuri !== []) {
      return [
        'riferisce' => (string) $this->t('Risultano @numero componenti con avviso di sicurezza non applicato: @elenco. @quando', [
          '@numero' => count($insicuri),
          '@elenco' => implode(', ', $insicuri),
          '@quando' => $quando,
        ]),
        'stato' => self::STATO_ATTENZIONE,
      ];
    }

    return [
      'riferisce' => (string) $this->t('Nessun aggiornamento di sicurezza risulta non applicato. @quando', [
        '@quando' => $quando,
      ]),
      'stato' => self::STATO_A_CARICO,
    ];
  }

  /**
   * Componenti con un avviso di sicurezza non applicato.
   *
   * @return array<int, string>|null
   *   L'elenco, vuoto se non ve ne sono; NULL se i dati non sono disponibili e
   *   quindi nulla può essere affermato in un senso o nell'altro.
   */
  private function progettiInsicuri(): ?array {
    $this->gestoreModuli->loadInclude('update', 'inc', 'update.compare');

    if (!function_exists('update_calculate_project_data')) {
      return NULL;
    }

    $progetti = update_calculate_project_data(update_get_available(FALSE));

    $daSegnalare = [
      UpdateManagerInterface::NOT_SECURE,
      UpdateManagerInterface::REVOKED,
      UpdateManagerInterface::NOT_SUPPORTED,
    ];
    $conosciuti = FALSE;
    $insicuri = [];

    foreach ($progetti as $nome => $progetto) {
      $condizione = (int) ($progetto['status'] ?? -1);

      if ($condizione >= 0) {
        $conosciuti = TRUE;
      }

      if (in_array($condizione, $daSegnalare, TRUE)) {
        $insicuri[] = (string) ($progetto['name'] ?? $nome);
      }
    }

    // Nessun progetto con condizione nota significa che il confronto non è
    // stato fatto, non che sia andato bene.
    return $conosciuti ? $insicuri : NULL;
  }

  /**
   * Stato osservabile della cifratura del canale.
   *
   * Dichiarare «da attuare» senza guardare è un difetto dell'attestazione, non
   * una cautela: un'istituzione che ha già disposto HTTPS si vede contestare
   * qualcosa che ha fatto, e impara a non fidarsi del resto del documento. Si
   * riferisce quindi ciò che si osserva, e per il resto si dice che cosa
   * manca.
   */
  private function cifraturaDelCanale(): string {
    $richiesta = $this->richieste->getCurrentRequest();

    if ($richiesta === NULL || PHP_SAPI === 'cli') {
      // Da riga di comando non c'è alcun canale da osservare: dichiararlo non
      // cifrato sarebbe falso quanto dichiararlo cifrato.
      return (string) $this->t("La cifratura del canale non è determinabile da riga di comando: va verificata aprendo questa attestazione dal sito.");
    }

    if (!$richiesta->isSecure()) {
      return (string) $this->t("Questa attestazione è stata prodotta su una connessione non cifrata: il canale non è protetto.");
    }

    $cookieSicuro = filter_var((string) ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN);

    return $cookieSicuro
      ? (string) $this->t("Il canale è cifrato (HTTPS) e il cookie di sessione è ristretto alle sole connessioni cifrate.")
      : (string) $this->t("Il canale è cifrato (HTTPS), ma il cookie di sessione non è ristretto alle sole connessioni cifrate: se il sito resta raggiungibile anche in HTTP, la sessione può viaggiare in chiaro.");
  }

  /**
   * Vero se tutte le catene di tracciature risultano integre.
   */
  private function catenePerfette(): bool {
    foreach ($this->registro->seduteTracciate() as $seduta) {
      if (!$this->registro->verificaCatena($seduta)['integra']) {
        return FALSE;
      }
    }

    return TRUE;
  }

  private function versione(): string {
    $informazioni = $this->moduli->getExtensionInfo('psiphos');

    return (string) ($informazioni['version'] ?? 'n.d.');
  }

}
