<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Entity\Verbale;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\StatoVerbale;

/**
 * Redazione, sigillo e conservazione del verbale di seduta.
 *
 * Il sigillo è un'operazione sola e indivisibile: serializza il contenuto, ne
 * calcola le impronte, produce i documenti e porta la seduta allo stato
 * verbalizzato. Se una qualsiasi di queste fasi non riesce non ne resta
 * traccia parziale, perché un verbale sigillato senza documento, o un
 * documento senza impronta, non sono documenti conservabili ai sensi del §7.
 *
 * Il contenuto viene serializzato una volta sola e conservato: verbale ed
 * estratti nascono da quei byte, e su quei byte è calcolata l'impronta. È la
 * differenza fra un documento conservato e uno rigenerato a ogni richiesta —
 * il secondo dipende dal codice che lo rigenera e dai dati vivi da cui lo
 * ricava, e smette di verificare per ragioni che non c'entrano nulla con la
 * sua integrità.
 */
final class Verbalizzazione {

  private const CARTELLA_VERBALI = 'private://psiphos/verbali';

  /**
   * Gli estratti stanno per conto loro perché sono atti per conto loro.
   *
   * Chi cerca una delibera negli archivi la cerca fra le delibere, non
   * dentro i verbali: sono documenti con destinazioni diverse — l'albo, la
   * trasparenza, gli uffici — e tenerli mescolati costringe a distinguerli
   * dal nome del file.
   */
  private const CARTELLA_DELIBERE = 'private://psiphos/delibere';

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly CostruttoreVerbale $costruttore,
    private readonly CostruttoreAtto $costruttoreAtto,
    private readonly ConservazioneDocumento $conservazione,
    private readonly RendererInterface $renderizzatore,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $archivioFile,
    private readonly TimeInterface $orologio,
  ) {}

  /**
   * Verbale della seduta, creandone la bozza se non esiste ancora.
   */
  public function perSeduta(SedutaInterface $seduta): Verbale {
    $esistente = $this->esistente($seduta);
    if ($esistente !== NULL) {
      return $esistente;
    }

    $verbale = Verbale::create([
      'seduta' => $seduta->id(),
      'stato' => StatoVerbale::BOZZA->value,
    ]);
    $verbale->save();

    return $verbale;
  }

  /**
   * Verbale già esistente per la seduta, se c'è.
   */
  public function esistente(SedutaInterface $seduta): ?Verbale {
    if ($seduta->isNew()) {
      return NULL;
    }

    $trovati = $this->gestoreEntita->getStorage('psiphos_verbale')
      ->loadByProperties(['seduta' => $seduta->id()]);

    $verbale = reset($trovati);

    return $verbale instanceof Verbale ? $verbale : NULL;
  }

  /**
   * Vero se il verbale può essere sigillato.
   *
   * @return array{ammesso: bool, motivo: string}
   */
  public function sigillabile(Verbale $verbale): array {
    $seduta = $this->sedutaDi($verbale);

    if ($verbale->sigillato()) {
      return ['ammesso' => FALSE, 'motivo' => (string) t('Il verbale è già sigillato.')];
    }

    if ($seduta === NULL) {
      return ['ammesso' => FALSE, 'motivo' => (string) t('Il verbale non è collegato ad alcuna seduta.')];
    }

    if ($seduta->stato() !== StatoSeduta::CHIUSA) {
      return [
        'ammesso' => FALSE,
        'motivo' => (string) t('La seduta non è ancora chiusa: il verbale documenta una seduta conclusa e non una in corso.'),
      ];
    }

    // Il sigillo del verbale è anche il sigillo degli estratti di delibera:
    // sono lo stesso atto di chiusura e non possono avvenire in momenti
    // diversi, altrimenti verbale e delibere potrebbero divergere. Ne segue
    // che un atto incompleto impedisce il sigillo di tutto, ed è giusto che
    // sia così: un estratto senza numero non è protocollabile e uno senza
    // dispositivo non dice che cosa l'organo ha deliberato.
    $incomplete = [];
    foreach ($this->delibereDaFormalizzare($seduta) as $delibera) {
      $lacune = $delibera->descrizioneLacune();
      if ($lacune !== '') {
        $incomplete[] = (string) t('«@quesito»: @lacune', [
          '@quesito' => $delibera->label(),
          '@lacune' => $lacune,
        ]);
      }
    }

    if ($incomplete !== []) {
      return [
        'ammesso' => FALSE,
        'motivo' => (string) t('Alcune delibere non sono ancora redatte come atti e vanno completate prima del sigillo. @elenco.', [
          '@elenco' => implode('; ', $incomplete),
        ]),
      ];
    }

    return ['ammesso' => TRUE, 'motivo' => ''];
  }

  /**
   * Delibere della seduta che vanno formalizzate in un proprio estratto.
   *
   * @return array<int, \Drupal\psiphos\Entity\DeliberaInterface>
   */
  public function delibereDaFormalizzare(SedutaInterface $seduta): array {
    if ($seduta->isNew()) {
      return [];
    }

    $archivio = $this->gestoreEntita->getStorage('psiphos_delibera');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->sort('id')
      ->execute();

    $delibere = [];
    foreach ($archivio->loadMultiple($identificativi) as $delibera) {
      if ($delibera instanceof DeliberaInterface && $delibera->daFormalizzare()) {
        $delibere[] = $delibera;
      }
    }

    return $delibere;
  }

  /**
   * Sigilla il verbale e porta la seduta allo stato verbalizzato.
   *
   * @throws \RuntimeException
   */
  public function sigilla(Verbale $verbale, AccountInterface $sigillante): Verbale {
    $ammissibilita = $this->sigillabile($verbale);
    if (!$ammissibilita['ammesso']) {
      throw new \RuntimeException($ammissibilita['motivo']);
    }

    $seduta = $this->sedutaDi($verbale);

    // Il sigillo va apposto sull'entità prima di generare il documento: il
    // documento è la fotografia del verbale sigillato, e se lo si producesse
    // sullo stato precedente si porterebbe dentro la dicitura di bozza e un
    // campo impronta ancora vuoto. È il documento a doversi conformare al
    // sigillo, non il contrario.
    $verbale->set('stato', StatoVerbale::SIGILLATO->value)
      ->set('sigillato_il', $this->orologio->getRequestTime())
      ->set('sigillato_da', $sigillante->id());

    // La struttura si serializza una volta sola e i byte restano sul verbale.
    // Ricostruirli a ogni richiesta, com'era prima, rendeva l'impronta
    // dipendente dal codice e dai dati vivi: bastava una traduzione rivista o
    // un cognome corretto perché un verbale intatto smettesse di verificare.
    // Un documento da conservare non si rigenera, si conserva.
    $conservata = $this->costruttore->serializza($this->costruttore->strutturaCanonica($seduta, $verbale));
    $verbale->set('contenuto', $conservata)
      ->set('impronta_contenuto', hash('sha256', $conservata));

    // Tutti i documenti si producono prima che se ne scriva uno. Generare e
    // salvare a coppie lascerebbe, se la generazione del terzo estratto non
    // riesce, due delibere sigillate dentro un verbale che non lo è: una
    // seduta a metà fra due stati, e nessuno dei due documentabile.
    $atti = [];
    foreach ($this->delibereDaFormalizzare($seduta) as $delibera) {
      // Come per il verbale: si serializza una volta, si conservano i byte e
      // il documento si genera da quelli. L'assegnazione precede la
      // generazione perché il documento è la fotografia dell'atto sigillato,
      // e prodotto sullo stato precedente si porterebbe dentro la dicitura di
      // bozza. Non altera il calcolo: l'impronta è presa sulla struttura
      // canonica, che non la contiene.
      $conservataAtto = $this->costruttoreAtto->serializza(
        $this->costruttoreAtto->strutturaCanonica($delibera, $verbale)
      );
      $delibera->set('contenuto', $conservataAtto)
        ->set('impronta_atto', hash('sha256', $conservataAtto));

      $atti[] = [
        'delibera' => $delibera,
        'documento' => $this->conservazione->produci($this->documentoAttoHtml($delibera, $verbale)),
      ];
    }

    $documento = $this->conservazione->produci($this->documentoHtml($verbale));

    foreach ($atti as $atto) {
      $delibera = $atto['delibera'];
      $file = $this->salvaDocumento(
        self::CARTELLA_DELIBERE,
        sprintf('delibera-%s.pdf', $delibera->uuid()),
        $atto['documento']['contenuto']
      );
      $delibera->set('impronta_documento', $atto['documento']['impronta'])
        ->set('formato', $atto['documento']['formato'])
        ->set('documento', $file->id())
        ->save();
    }

    $file = $this->salvaDocumento(
      self::CARTELLA_VERBALI,
      sprintf('verbale-%s.pdf', $verbale->uuid()),
      $documento['contenuto']
    );

    // L'impronta del file e il formato si conoscono soltanto a documento
    // prodotto, e per questo non compaiono al suo interno: un file non può
    // contenere la propria impronta. Restano registrati sul verbale e
    // nell'esportazione strutturata, che è dove si verificano.
    $verbale->set('impronta_pdf', $documento['impronta'])
      ->set('formato', $documento['formato'])
      ->set('documento', $file->id())
      ->save();

    $seduta->transitaA(StatoSeduta::VERBALIZZATA)->save();

    return $verbale;
  }

  /**
   * Struttura canonica di un atto, per pagina, documento ed esportazione.
   *
   * @return array<string, mixed>
   */
  public function strutturaAtto(DeliberaInterface $delibera): array {
    // Il verbale può non esistere ancora: l'atto si redige a seduta chiusa e
    // la bozza del verbale si apre quando il segretario ci arriva. La pagina
    // dell'atto deve restare leggibile anche prima, o si redigerebbe alla
    // cieca; i riferimenti al verbale compaiono quando c'è.
    return $this->costruttoreAtto->struttura($delibera, $this->verbaleDi($delibera));
  }

  /**
   * Esportazione strutturata di un atto.
   *
   * Come per il verbale, esporta la struttura canonica e nient'altro:
   * l'impronta registrata sulla delibera è esattamente lo SHA-256 di questo
   * file, ricalcolabile con un qualunque strumento.
   */
  public function esportaAtto(DeliberaInterface $delibera): string {
    $conservata = (string) $delibera->get('contenuto')->value;

    if (trim($conservata) !== '') {
      return $conservata;
    }

    $verbale = $this->verbaleDi($delibera);

    return $verbale === NULL
      ? ''
      : $this->costruttoreAtto->serializza($this->costruttoreAtto->strutturaCanonica($delibera, $verbale));
  }

  /**
   * Verbale della seduta a cui la delibera appartiene, se esiste.
   */
  public function verbaleDi(DeliberaInterface $delibera): ?Verbale {
    $identificativo = $delibera->get('seduta')->target_id;

    if ($identificativo === NULL) {
      return NULL;
    }

    $seduta = $this->gestoreEntita->getStorage('psiphos_seduta')->load($identificativo);

    return $seduta instanceof SedutaInterface ? $this->esistente($seduta) : NULL;
  }

  /**
   * Documento HTML autonomo di un estratto di delibera.
   */
  public function documentoAttoHtml(DeliberaInterface $delibera, Verbale $verbale): string {
    $costruzione = [
      '#theme' => 'psiphos_estratto_delibera',
      '#delibera' => $delibera,
      '#dati' => $this->costruttoreAtto->struttura($delibera, $verbale),
      '#documento' => TRUE,
      '#cache' => ['max-age' => 0],
    ];

    return $this->incorniciaDocumento(
      sprintf('%s — %s', (string) t('Delibera'), $delibera->oggettoAtto()),
      (string) $this->renderizzatore->renderInIsolation($costruzione)
    );
  }

  /**
   * Struttura canonica della seduta, per pagina, documento ed esportazione.
   *
   * @return array<string, mixed>
   */
  public function struttura(Verbale $verbale): array {
    return $this->costruttore->struttura($this->sedutaDi($verbale), $verbale);
  }

  /**
   * Seduta del verbale, letta dall'archivio e non dal campo di riferimento.
   *
   * Il campo entity_reference trattiene l'entità caricata al primo accesso:
   * un verbale consultato mentre la seduta era ancora aperta continuerebbe a
   * vederla aperta anche dopo la chiusura, e il sigillo verrebbe rifiutato
   * per una condizione che non è più vera.
   */
  private function sedutaDi(Verbale $verbale): ?SedutaInterface {
    $identificativo = $verbale->get('seduta')->target_id;

    if ($identificativo === NULL) {
      return NULL;
    }

    $seduta = $this->gestoreEntita->getStorage('psiphos_seduta')->load($identificativo);

    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

  /**
   * Esportazione strutturata del verbale.
   *
   * Esporta la struttura canonica e nient'altro: l'impronta registrata sul
   * verbale è esattamente lo SHA-256 di questo file. Aggiungervi il momento
   * di generazione o i testi derivati costringerebbe chi verifica a sapere
   * quali campi togliere prima di ricalcolare, e una verifica che dipende da
   * un'istruzione esterna al file non è una verifica.
   */
  public function esporta(Verbale $verbale): string {
    $conservata = (string) $verbale->get('contenuto')->value;

    if (trim($conservata) !== '') {
      return $conservata;
    }

    // Il verbale è ancora una bozza: non c'è nulla di conservato perché non
    // c'è ancora nulla di sigillato. L'esportazione si ricava dai dati, ed è
    // un'anteprima di quel che sarà conservato, non un atto.
    $seduta = $this->sedutaDi($verbale);

    return $seduta === NULL
      ? ''
      : $this->costruttore->serializza($this->costruttore->strutturaCanonica($seduta, $verbale));
  }

  /**
   * Documento HTML autonomo da cui si genera il PDF.
   */
  public function documentoHtml(Verbale $verbale): string {
    $costruzione = [
      '#theme' => 'psiphos_verbale',
      '#verbale' => $verbale,
      '#dati' => $this->struttura($verbale),
      '#documento' => TRUE,
      '#cache' => ['max-age' => 0],
    ];

    return $this->incorniciaDocumento(
      (string) $verbale->label(),
      (string) $this->renderizzatore->renderInIsolation($costruzione)
    );
  }

  /**
   * Racchiude il corpo reso in un documento HTML autonomo.
   */
  private function incorniciaDocumento(string $titolo, string $corpo): string {
    return sprintf(
      '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>%s</title><style>%s</style></head><body>%s</body></html>',
      htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8'),
      $this->foglioDiStile(),
      $corpo
    );
  }

  /**
   * Salva un documento fra i file riservati.
   */
  private function salvaDocumento(string $cartella, string $nome, string $contenuto): FileInterface {
    $destinazione = $cartella;

    if (!$this->fileSystem->prepareDirectory($destinazione, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException(sprintf('La cartella dei documenti %s non è utilizzabile.', $cartella));
    }

    $file = $this->archivioFile->writeData($contenuto, $destinazione . '/' . $nome, FileExists::Replace);

    // Il documento non è un allegato temporaneo: senza lo stato permanente
    // la manutenzione periodica dei file lo cancellerebbe.
    if (!$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }

    return $file;
  }

  /**
   * Foglio di stile del documento.
   *
   * Deliberatamente essenziale e in unità assolute: il documento deve
   * restare leggibile fra dieci anni, e il §7 lega la conservabilità alla
   * leggibilità nel tempo. Nessun carattere esterno, nessun colore
   * necessario alla comprensione.
   */
  private function foglioDiStile(): string {
    return <<<'CSS'
      /* Margine superiore ridotto al minimo stampabile: la carta intestata è
         essa stessa il margine visivo del documento. In basso resta pieno,
         perché è lì che si protocolla e si firma. */
      @page { size: A4; margin: 8mm 15mm 28mm; }
      body { font-family: DejaVu Serif, serif; font-size: 10.5pt; line-height: 1.45; color: #000; }
      h1 { font-size: 15pt; margin: 0 0 2mm; }
      h2 { font-size: 12pt; margin: 6mm 0 1.2mm; border-bottom: 0.4pt solid #000; padding-bottom: 1mm; page-break-after: avoid; }
      h3 { font-size: 11pt; margin: 3.5mm 0 1mm; page-break-after: avoid; }
      p { margin: 0 0 2.5mm; }
      table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; }
      th, td { border: 0.4pt solid #000; padding: 1.2mm 2mm; text-align: left; vertical-align: top; font-size: 9.5pt; }
      th { font-weight: bold; }
      dl { margin: 0 0 3mm; }
      dt { font-weight: bold; }
      dd { margin: 0 0 1.5mm; }
      /* Coppie etichetta-valore in linea: su due righe occupavano il doppio
         dello spazio senza guadagnare in leggibilità. Il div che le raccoglie
         fornisce l'andata a capo. */
      .psiphos-verbale__dati dt { display: inline; }
            /* Solo i due punti: lo spazio lo fornisce già il markup fra dt e dd. */
      .psiphos-verbale__dati dt:after { content: ':'; }
      .psiphos-verbale__dati dd { display: inline; margin: 0; }
      .psiphos-verbale__dati > div { margin: 0 0 0.8mm; }
      /* L'ordine del giorno si scorre, non si legge: i punti stanno stretti. */
      .psiphos-verbale__odg-titolo { page-break-before: always; }
      .psiphos-verbale__odg h3 { margin: 0.8mm 0 0.3mm; }
      .psiphos-verbale__odg p { margin: 0 0 0.6mm; }
      /* L'illustrazione del punto è testo di servizio: sta fra il corpo del
         verbale e la nota, appena sopra quest'ultima. */
      .psiphos-verbale__illustrazione { font-size: 9.5pt; margin: 0 0 1mm; }
      .psiphos-verbale__illustrazione p { margin: 0 0 0.6mm; }
      .psiphos-verbale__divisore { width: 30mm; margin: 3mm auto; border: none; border-top: 0.4pt solid #000; }
      /* Le delibere si leggono come schede, non come prosa: le voci stanno
         vicine perché si consultano insieme. */
      .psiphos-verbale__delibere h3 { margin: 2mm 0 0.8mm; }
      .psiphos-verbale__delibere .psiphos-verbale__dati > div { margin: 0 0 0.3mm; }
      .psiphos-verbale__quesito { margin: 0 0 0.8mm; }
      /* Gli elenchi dei votanti occupano più righe: interlinea stretta, o una
         sola votazione riempie mezza pagina di nomi. */
      .psiphos-verbale__elenco { line-height: 1.2; }
      .psiphos-verbale__svolgimento { margin: 0; }
      .psiphos-verbale__svolgimento p:last-child { margin-bottom: 0; }
      .psiphos-verbale__intestazione { margin-bottom: 5mm; line-height: 1.25; text-align: center; }
      .psiphos-verbale__intestazione h1 { font-size: 11pt; margin: 0 0 0.6mm; }
      .psiphos-verbale__intestazione p { font-size: 11pt; margin: 0; }
      .psiphos-verbale__impronta { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; word-break: break-all; }
      .psiphos-verbale__nota { font-size: 8.5pt; }
      .psiphos-estratto__intestazione { margin-bottom: 5mm; text-align: center; line-height: 1.25; }
      .psiphos-estratto__intestazione h1 { font-size: 11pt; font-weight: normal; letter-spacing: 0.3pt; margin: 0 0 0.6mm; }
      .psiphos-estratto__seduta { font-size: 11pt; margin: 0; }
      .psiphos-carta { font-size: 8.5pt; line-height: 1.35; text-align: center; border-bottom: 1pt solid #000; padding-bottom: 3mm; margin: 0 0 4mm; }
      .psiphos-carta p { margin: 0; }
      .psiphos-carta__istituto { font-size: 11pt; font-weight: bold; margin: 0 0 1mm; }
      .psiphos-estratto__oggetto { font-size: 11pt; font-weight: bold; margin: 0 0 0.6mm; }
      .psiphos-estratto__organo { font-weight: bold; margin: 0 0 3mm; }
      .psiphos-estratto__premesse { list-style: none; margin: 0 0 4mm; padding: 0; }
      .psiphos-estratto__premesse li { margin: 0 0 1.2mm; }
      .psiphos-estratto__dispositivo { margin: 0 0 3mm; }
      .psiphos-estratto__proclamazione { margin: 0 0 2.5mm; }
      /* Il colophon sta dentro il margine inferiore, sotto il riquadro del
         testo: non è contenuto del documento. Sull'estratto resta però
         indispensabile, perché circola senza il verbale e chi lo riceve non
         ha altro con cui verificarlo.

         Lo scostamento negativo lo spinge nel margine, che è stato allargato
         apposta. Ancorato a «bottom: 0» resterebbe invece sul bordo inferiore
         del testo, e su un atto lungo abbastanza da riempire la pagina si
         stamperebbe sopra le ultime righe. */
      .psiphos-piede { position: fixed; bottom: -18mm; left: 0; right: 0; font-size: 6.5pt; line-height: 1.3; border-top: 0.4pt solid #000; padding-top: 1.2mm; }
      .psiphos-piede p { margin: 0; }
      /* Nel colophon l'impronta non si spezza: una riga che va a capo in mezzo
         a un SHA-256 costringe a ricomporlo a mano per confrontarlo, ed è
         proprio il confronto la ragione per cui è stampato. */
      .psiphos-piede .psiphos-verbale__impronta { word-break: normal; }
      .psiphos-piede__voce { font-weight: bold; }
      .psiphos-estratto__prospetto { width: auto; min-width: 70mm; margin: 0 0 5mm; }
      .psiphos-estratto__prospetto-titolo { font-size: 8.5pt; letter-spacing: 0.4pt; text-align: left; text-transform: uppercase; padding-bottom: 1mm; }
      .psiphos-estratto__prospetto th, .psiphos-estratto__prospetto td { border: none; border-bottom: 0.4pt solid #000; padding: 0.9mm 8mm 0.9mm 0; }
      .psiphos-estratto__prospetto td.cifra { text-align: right; padding-right: 0; }
      CSS;
  }

}
