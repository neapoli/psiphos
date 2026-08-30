<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Entity\Verbale;
use Drupal\psiphos\Nominativo;
use Drupal\psiphos\Enum\TipoVoto;

/**
 * Costruisce la rappresentazione canonica di una seduta verbalizzata.
 *
 * Una sola struttura alimenta la pagina del verbale, il PDF, l'impronta e
 * l'esportazione strutturata. Il §7 chiede che, quando l'esportazione dei
 * risultati non sia possibile, siano comunque prodotte «evidenze documentali
 * idonee alla verbalizzazione e alla conservazione»: tenendo un'unica fonte
 * per tutte e quattro le rese, documento ed esportazione non possono
 * divergere, e l'impronta calcolata sull'una vale anche per l'altro.
 */
final class CostruttoreVerbale {

  /**
   * Versione della struttura conservata.
   *
   * Cambia quando cambia la struttura: un identificativo che restasse fermo
   * mentre i campi cambiano toglierebbe a chi legge l'unico modo di sapere
   * che cosa aspettarsi.
   */
  public const FORMATO = 'psiphos-verbale-v2';

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly TimeInterface $orologio,
    private readonly Urna $urna,
    private readonly Scrutinio $scrutinio,
    private readonly IntestazioneIstituto $intestazione,
  ) {}

  /**
   * Struttura completa, comprensiva dei metadati volatili.
   *
   * @return array<string, mixed>
   */
  public function struttura(?SedutaInterface $seduta, ?Verbale $verbale = NULL): array {
    // Su un verbale sigillato si legge l'esportazione conservata, non se ne
    // ricostruisce una nuova: pagina, documento ed esportazione devono
    // mostrare gli stessi byte su cui l'impronta è stata calcolata. Finché il
    // verbale è in bozza — o non esiste, come sulla pagina della seduta — la
    // struttura si ricava dai dati, che è quel che si sta ancora componendo.
    //
    // La seduta può mancare, e il verbale sigillato resta leggibile lo stesso:
    // conserva tutto ciò che gli serve. È la ragione per cui i byte si
    // congelano — un documento da conservare non può dipendere dal fatto che
    // i dati da cui è nato siano ancora al loro posto.
    $conservata = $this->conservata($verbale);

    if ($conservata === NULL && $seduta === NULL) {
      return [];
    }

    $dati = $conservata ?? $this->strutturaCanonica($seduta, $verbale);
    $dati['metadati']['generato_il'] = $this->istante($this->orologio->getRequestTime());

    // La motivazione dell'esito è testo tradotto, ricavato dai dati: entra
    // nel documento ma non nell'impronta. Se vi entrasse, l'aggiornamento di
    // una traduzione renderebbe irripetibile l'impronta di un verbale già
    // sigillato, e la verifica di integrità comincerebbe a fallire su
    // documenti che nessuno ha toccato.
    $archivioDelibere = $this->gestoreEntita->getStorage('psiphos_delibera');

    foreach ($dati['ordine_del_giorno'] as $indicePunto => $punto) {
      foreach ($punto['votazioni'] as $indiceVotazione => $votazione) {
        $delibera = $this->deliberaPerIdentificativo($archivioDelibere, (string) ($votazione['identificativo'] ?? ''));
        $dati['ordine_del_giorno'][$indicePunto]['votazioni'][$indiceVotazione]['motivazione_esito'] =
          $delibera === NULL ? '' : $this->scrutinio->motivazioneEsito($delibera);
      }
    }

    return $dati;
  }

  /**
   * Esportazione conservata sul verbale, se ve n'è una.
   *
   * @return array<string, mixed>|null
   */
  private function conservata(?Verbale $verbale): ?array {
    if ($verbale === NULL) {
      return NULL;
    }

    $serializzata = trim((string) $verbale->get('contenuto')->value);

    if ($serializzata === '') {
      return NULL;
    }

    $dati = json_decode($serializzata, TRUE);

    return is_array($dati) ? $dati : NULL;
  }

  /**
   * Ritrova la delibera dal suo identificativo stabile.
   *
   * Il quesito non è una chiave: due votazioni sullo stesso punto possono
   * portare la stessa formulazione, e una ripetizione lo fa quasi sempre.
   */
  private function deliberaPerIdentificativo($archivio, string $identificativo): ?DeliberaInterface {
    if ($identificativo === '') {
      return NULL;
    }

    $trovate = $archivio->loadByProperties(['uuid' => $identificativo]);
    $delibera = reset($trovate);

    return $delibera instanceof DeliberaInterface ? $delibera : NULL;
  }

  /**
   * Struttura su cui si calcola l'impronta.
   *
   * Esclude il momento di generazione: un dato che cambia a ogni chiamata
   * renderebbe l'impronta irripetibile e quindi inservibile alla verifica.
   *
   * @return array<string, mixed>
   */
  public function strutturaCanonica(SedutaInterface $seduta, ?Verbale $verbale = NULL): array {
    $aventiDiritto = $seduta->aventiDirittoAllApertura() ?? $seduta->numeroAventiDiritto();

    return [
      'formato' => self::FORMATO,
      'metadati' => [
        'identificativo' => $seduta->uuid(),
        // Metadati previsti dalle Linee guida AgID per il documento
        // informatico. Senza tipologia documentale e data di chiusura il
        // versamento in conservazione viene respinto: il formato PDF/A da
        // solo non basta, ed è un rifiuto che si scopre al primo versamento
        // vero, quando i documenti sono già decine.
        'tipologia_documentale' => 'Verbale di seduta di organo collegiale',
        'data_chiusura' => $verbale === NULL ? '' : $this->istante($verbale->get('sigillato_il')->value),
        'modalita_formazione' => 'Generazione da base di dati',
        'oggetto' => (string) $seduta->label(),
        'soggetto_produttore' => (string) $this->configurazione->get('system.site')->get('name'),
        // Come per l'estratto: l'intestazione si congela al sigillo, così un
        // verbale conservato porta i recapiti che l'istituto aveva quel
        // giorno e non quelli di oggi.
        'intestazione' => $this->intestazione->dati(),
        'riferimento_normativo' => 'Nota MIM prot. 3803 del 30/06/2026, allegato tecnico; art. 44, commi 3 lett. a) e b) e 6, CCNL comparto Istruzione e Ricerca del 18/01/2024',
        'riferimento_regolamento' => (string) $seduta->get('riferimento_regolamento')->value,
      ],
      'seduta' => [
        'organo' => $seduta->organo()->value,
        'organo_denominazione' => $seduta->organo()->etichetta(),
        'lettera_art_44' => $seduta->organo()->letteraArt44(),
        'numero' => (string) $seduta->get('numero')->value,
        'anno_scolastico' => (string) $seduta->get('anno_scolastico')->value,
        'modalita' => (string) $seduta->get('modalita')->value,
        'strumento_videoconferenza' => (string) $seduta->get('url_videoconferenza')->value,
        'convocata_il' => $this->istante($seduta->get('data_convocazione')->value),
        'convocata_per' => $this->istante($seduta->get('data_seduta')->value),
        'aperta_il' => $this->istante($seduta->get('aperta_il')->value),
        'chiusa_il' => $this->istante($seduta->get('chiusa_il')->value),
        'presidente' => $this->nominativo($seduta->get('presidente')->entity),
        'segretario' => $this->nominativo($seduta->get('segretario')->entity),
        'note_procedurali' => $this->testo($seduta->get('note_procedurali')->value),
        'note_procedurali_formato' => $this->formato($seduta->get('note_procedurali')),
      ],
      'costituzione' => [
        'aventi_diritto' => $aventiDiritto,
        'regola_quorum' => $seduta->quorumCostitutivo()->value,
        'presenti_minimi' => $seduta->quorumCostitutivo()->minimoPresenti($aventiDiritto),
        'presenti' => $seduta->numeroPresenti(),
        'validamente_costituita' => $seduta->validamenteCostituita(),
      ],
      'registro_presenze' => $this->registroPresenze($seduta),
      'ordine_del_giorno' => $this->ordineDelGiorno($seduta),
      'svolgimento' => $verbale === NULL ? '' : $this->testo($verbale->get('testo')->value),
      'svolgimento_formato' => $verbale === NULL ? '' : $this->formato($verbale->get('testo')),
    ];
  }

  /**
   * Impronta della struttura canonica.
   */
  public function impronta(SedutaInterface $seduta, ?Verbale $verbale = NULL): string {
    return hash('sha256', $this->serializza($this->strutturaCanonica($seduta, $verbale)));
  }

  /**
   * Serializzazione deterministica usata per l'impronta e l'esportazione.
   */
  public function serializza(array $struttura): string {
    $this->ordinaChiaviRicorsivamente($struttura);

    return (string) json_encode(
      $struttura,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
  }

  /**
   * Verifica un verbale sigillato.
   *
   * Risponde a due domande che vanno tenute distinte, perché hanno peso
   * diverso e rimedi diversi.
   *
   * L'integrità dice se l'esportazione conservata è ancora quella sigillata.
   * È la domanda che conta davanti a chi controlla, ed è verificabile fuori
   * da qui: basta lo SHA-256 del file esportato.
   *
   * La corrispondenza dice se la banca dati racconta ancora la stessa seduta
   * che il documento racconta. Una differenza non è di per sé una
   * manomissione: può nascere da una correzione legittima — un cognome
   * rettificato, una traduzione rivista — che il documento sigillato non
   * recepisce e non deve recepire. Confonderla con l'integrità, come faceva
   * la versione precedente, produceva allarmi falsi su documenti intatti.
   *
   * @return array{sigillato: bool, integro: bool, corrispondente: bool, impronta_registrata: string, impronta_ricalcolata: string, impronta_dati_attuali: string}
   */
  public function verifica(Verbale $verbale): array {
    $registrata = trim((string) $verbale->get('impronta_contenuto')->value);
    $conservata = (string) $verbale->get('contenuto')->value;

    if ($registrata === '' || trim($conservata) === '') {
      return [
        'sigillato' => FALSE,
        'integro' => FALSE,
        'corrispondente' => FALSE,
        'impronta_registrata' => $registrata,
        'impronta_ricalcolata' => '',
        'impronta_dati_attuali' => '',
      ];
    }

    $ricalcolata = hash('sha256', $conservata);

    $identificativo = $verbale->get('seduta')->target_id;
    $seduta = $identificativo === NULL
      ? NULL
      : $this->gestoreEntita->getStorage('psiphos_seduta')->load($identificativo);
    $attuale = $seduta instanceof SedutaInterface
      ? $this->serializza($this->strutturaCanonica($seduta, $verbale))
      : '';

    return [
      'sigillato' => TRUE,
      'integro' => hash_equals($registrata, $ricalcolata),
      'corrispondente' => $attuale !== '' && hash_equals($ricalcolata, hash('sha256', $attuale)),
      'impronta_registrata' => $registrata,
      'impronta_ricalcolata' => $ricalcolata,
      'impronta_dati_attuali' => $attuale === '' ? '' : hash('sha256', $attuale),
    ];
  }

  /**
   * Elenco degli aventi diritto con la posizione assunta nella seduta.
   *
   * @return array<int, array<string, mixed>>
   */
  private function registroPresenze(SedutaInterface $seduta): array {
    $registro = [];

    foreach ($this->presenze($seduta) as $presenza) {
      $registro[] = [
        'nominativo' => $this->nominativo($presenza->get('utente')->entity),
        'posizione' => $presenza->stato()->value,
        'posizione_denominazione' => $presenza->stato()->etichetta(),
        'concorre_al_quorum' => $presenza->concorreAlQuorum(),
        'ingresso' => $this->istante($presenza->get('ingresso')->value),
        'uscita' => $this->istante($presenza->get('uscita')->value),
        'giustificazione' => (string) $presenza->get('giustificazione')->value,
      ];
    }

    usort($registro, static fn (array $a, array $b): int => strcmp($a['nominativo'], $b['nominativo']));

    return $registro;
  }

  /**
   * Punti all'ordine del giorno con le relative votazioni.
   *
   * @return array<int, array<string, mixed>>
   */
  private function ordineDelGiorno(SedutaInterface $seduta): array {
    $archivioPunti = $this->gestoreEntita->getStorage('psiphos_punto_odg');
    $identificativi = $archivioPunti->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->sort('numero')
      ->sort('id')
      ->execute();

    $punti = [];
    foreach ($archivioPunti->loadMultiple($identificativi) as $punto) {
      assert($punto instanceof PuntoOdg);
      $punti[] = [
        'numero' => (int) $punto->get('numero')->value,
        'oggetto' => (string) $punto->label(),
        'illustrazione' => $this->testo($punto->get('descrizione')->value),
        'illustrazione_formato' => $this->formato($punto->get('descrizione')),
        'deliberativo' => $punto->deliberativo(),
        'votazioni' => $this->votazioni($punto),
      ];
    }

    return $punti;
  }

  /**
   * Votazioni svolte su un punto.
   *
   * @return array<int, array<string, mixed>>
   */
  private function votazioni(PuntoOdg $punto): array {
    $archivio = $this->gestoreEntita->getStorage('psiphos_delibera');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('punto_odg', $punto->id())
      ->sort('id')
      ->execute();

    $votazioni = [];
    foreach ($archivio->loadMultiple($identificativi) as $delibera) {
      assert($delibera instanceof DeliberaInterface);
      $votazioni[] = $this->votazione($delibera);
    }

    return $votazioni;
  }

  /**
   * @return array<string, mixed>
   */
  private function votazione(DeliberaInterface $delibera): array {
    $segreto = $delibera->tipoVoto() === TipoVoto::SEGRETO;

    $prevalenti = [];
    foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
      $prevalenti[] = (string) $elemento->value;
    }

    $voci = $delibera->vociScheda();

    return [
      'identificativo' => $delibera->uuid(),
      'numero' => (string) $delibera->get('numero_delibera')->value,
      'quesito' => (string) $delibera->label(),
      'tipo_voto' => $delibera->tipoVoto()->value,
      'schema_scheda' => $delibera->schemaScheda()->value,
      'opzioni' => $delibera->opzioni(),
      'preferenze_esprimibili' => $delibera->preferenzeMassime(),
      'regola_maggioranza' => $delibera->regolaMaggioranza()->value,
      'regola_maggioranza_denominazione' => $delibera->regolaMaggioranza()->etichetta(),
      'stato' => $delibera->stato()->value,
      'aperta_il' => $this->istante($delibera->get('aperta_il')->value),
      'chiusa_il' => $this->istante($delibera->get('chiusa_il')->value),
      'aventi_diritto_al_voto' => (int) $delibera->get('aventi_diritto_al_voto')->value,
      'presenti_al_voto' => (int) $delibera->get('presenti_al_voto')->value,
      'votanti' => (int) $delibera->get('votanti')->value,
      'conteggio' => $delibera->conteggio(),
      'conteggio_denominazioni' => $voci,
      'esito' => $delibera->esito()?->value ?? '',
      'esito_denominazione' => $delibera->esito()?->etichettaPer($delibera->schemaScheda()) ?? '',
      'opzioni_prevalenti' => $prevalenti,
      'sigillo_urna' => (string) $delibera->get('sigillo_urna')->value,
      'motivazione' => $this->testo($delibera->get('motivazione')->value),
      'motivazione_formato' => $this->formato($delibera->get('motivazione')),
      'ripetizione_di' => $delibera->ripetizioneDi()?->uuid() ?? '',
      // Accanto all'identificativo, il quesito della votazione ripetuta: in
      // un documento cartaceo un codice non dice nulla, e il §8 vuole che la
      // ripetizione sia riconoscibile come tale da chi legge il verbale.
      'ripetizione_di_quesito' => $delibera->ripetizioneDi()?->label() ?? '',
      // Sul voto palese il §4.2 impone l'associazione fra votante e scelta
      // espressa; sul voto segreto il §4.3 la vieta. Il verbale riporta di
      // conseguenza due registri diversi, e nel caso segreto non esiste
      // proprio il dato da cui ricavare come ciascuno abbia votato.
      'registro_votanti' => $this->registroVotanti($delibera, $segreto),
    ];
  }

  /**
   * @return array<int, array<string, string>>
   */
  private function registroVotanti(DeliberaInterface $delibera, bool $segreto): array {
    if ($delibera->get('aperta_il')->value === NULL) {
      return [];
    }

    $archivioUtenti = $this->gestoreEntita->getStorage('user');
    $registro = [];

    foreach ($this->urna->registroVotanti($delibera) as $voce) {
      $riga = ['nominativo' => $this->nominativo($archivioUtenti->load($voce['utente']))];

      if (!$segreto) {
        $voci = $delibera->vociScheda();
        $riga['voto'] = implode(', ', array_map(
          static fn (string $chiave): string => $voci[$chiave] ?? $chiave,
          explode(',', (string) $voce['voci'])
        ));
      }

      $registro[] = $riga;
    }

    usort($registro, static fn (array $a, array $b): int => strcmp($a['nominativo'], $b['nominativo']));

    return $registro;
  }

  /**
   * Presenze della seduta.
   *
   * @return array<int, \Drupal\psiphos\Entity\Presenza>
   */
  private function presenze(SedutaInterface $seduta): array {
    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->sort('id')
      ->execute();

    return array_filter($archivio->loadMultiple($identificativi), static fn ($p): bool => $p instanceof Presenza);
  }

  private function nominativo(mixed $utente): string {
    return Nominativo::perUtente($utente);
  }

  /**
   * Istante in formato ISO 8601, o stringa vuota se non valorizzato.
   */
  private function istante(mixed $marca): string {
    return $marca === NULL || $marca === '' ? '' : date('c', (int) $marca);
  }

  private function testo(mixed $valore): string {
    return trim((string) ($valore ?? ''));
  }

  /**
   * Formato di testo con cui un campo è stato redatto.
   *
   * Viaggia accanto al valore perché la resa possa applicarlo, mentre
   * l'impronta continua a calcolarsi sul valore grezzo: se dipendesse dal
   * formato, una modifica alla configurazione dei filtri renderebbe
   * irripetibile l'impronta di un verbale già sigillato.
   */
  private function formato(mixed $campo): string {
    $elemento = $campo?->first();

    return $elemento === NULL ? '' : (string) ($elemento->format ?? '');
  }

  /**
   * Chiavi che sono mappe ma il cui ordine porta un significato.
   *
   * Il conteggio segue l'ordine delle voci sulla scheda — favorevole,
   * contrario, astenuto — che è quello con cui il collegio ha votato e con
   * cui l'esito si legge. Riordinarlo alfabeticamente lo renderebbe
   * ripetibile allo stesso modo, ma stamperebbe «astenuti, contrari,
   * favorevoli» in ogni verbale e in ogni delibera.
   */
  private const ORDINE_SIGNIFICATIVO = ['conteggio', 'conteggio_denominazioni'];

  /**
   * Ordina le chiavi in profondità, per una serializzazione ripetibile.
   */
  private function ordinaChiaviRicorsivamente(array &$struttura, string $chiave = ''): void {
    foreach ($struttura as $nome => &$valore) {
      if (is_array($valore)) {
        $this->ordinaChiaviRicorsivamente($valore, (string) $nome);
      }
    }
    unset($valore);

    // Le liste non si riordinano: nell'ordine del giorno la posizione è il
    // dato. Le mappe sì, perché l'ordine di inserimento non è garantito —
    // salvo quelle in cui l'ordine è esso stesso informazione.
    if (!array_is_list($struttura) && !in_array($chiave, self::ORDINE_SIGNIFICATIVO, TRUE)) {
      ksort($struttura);
    }
  }

}
