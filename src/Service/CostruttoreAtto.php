<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Entity\Verbale;
use Drupal\psiphos\Nominativo;

/**
 * Rappresentazione canonica di una singola deliberazione.
 *
 * Le scuole tengono le delibere separate dal verbale: il verbale documenta
 * la seduta, l'estratto documenta il singolo atto e circola da solo, verso
 * l'Amministrazione Trasparente, l'albo, gli uffici. Sono due documenti con
 * due destinazioni diverse, e per questo hanno due impronte diverse: chi
 * riceve un estratto deve poterlo verificare senza disporre del verbale.
 *
 * L'estratto non è però un documento autonomo dalla seduta: porta con sé
 * l'identificativo del verbale e la sua impronta, oltre al sigillo dell'urna
 * da cui l'esito è uscito. Un estratto scambiato con un altro, o riferito a
 * una seduta diversa, si riconosce da questi tre riferimenti.
 */
final class CostruttoreAtto {

  public const FORMATO = 'psiphos-delibera-v2';

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly TimeInterface $orologio,
    private readonly Scrutinio $scrutinio,
    private readonly IntestazioneIstituto $intestazione,
  ) {}

  /**
   * Struttura completa dell'atto, comprensiva di quanto è derivato.
   *
   * Il verbale può ancora non esistere: l'atto si redige a seduta chiusa e la
   * bozza del verbale si apre quando il segretario ci arriva, non prima. La
   * pagina dell'atto deve restare consultabile anche allora, altrimenti chi
   * lo sta redigendo non vede quel che sta scrivendo.
   *
   * @return array<string, mixed>
   */
  public function struttura(DeliberaInterface $delibera, ?Verbale $verbale = NULL): array {
    // Su un atto sigillato si legge l'esportazione conservata e non se ne
    // ricostruisce una nuova: l'estratto che circola e l'impronta che lo
    // attesta devono riferirsi agli stessi byte.
    $dati = $this->conservata($delibera) ?? $this->strutturaCanonica($delibera, $verbale);

    // Proclamazione, prospetto e motivazione dell'esito sono testo tradotto,
    // interamente ricavabile dai numeri che la struttura canonica già
    // contiene. Entrano nel documento ma non nell'impronta: se vi entrassero,
    // la revisione di una traduzione renderebbe irripetibile l'impronta di un
    // atto già sigillato e la verifica comincerebbe a fallire su documenti
    // che nessuno ha toccato.
    $dati['votazione']['proclamazione'] = $this->scrutinio->proclamazione($delibera);
    $dati['votazione']['prospetto'] = $this->scrutinio->prospettoVotazione($delibera);
    $dati['votazione']['motivazione_esito'] = $this->scrutinio->motivazioneEsito($delibera);
    $dati['metadati']['generato_il'] = $this->istante($this->orologio->getRequestTime());

    return $dati;
  }

  /**
   * Struttura su cui si calcola l'impronta dell'atto.
   *
   * @return array<string, mixed>
   */
  public function strutturaCanonica(DeliberaInterface $delibera, ?Verbale $verbale = NULL): array {
    $seduta = $this->sedutaDi($delibera);

    $prevalenti = [];
    foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
      $prevalenti[] = (string) $elemento->value;
    }

    return [
      'formato' => self::FORMATO,
      'metadati' => [
        'identificativo' => $delibera->uuid(),
        // Come per il verbale: senza questi metadati il versamento in
        // conservazione viene respinto.
        'tipologia_documentale' => 'Estratto di deliberazione di organo collegiale',
        'data_chiusura' => $verbale === NULL ? '' : $this->istante($verbale->get('sigillato_il')->value),
        'modalita_formazione' => 'Generazione da base di dati',
        'soggetto_produttore' => (string) $this->configurazione->get('system.site')->get('name'),
        // L'intestazione entra nell'atto e vi resta congelata: un atto porta
        // i recapiti che l'istituto aveva quando l'ha adottato, non quelli di
        // oggi. Ricavarli ogni volta dai dati vivi farebbe cambiare la carta
        // intestata di delibere già protocollate.
        'intestazione' => $this->intestazione->dati(),
        'riferimento_normativo' => 'Nota MIM prot. 3803 del 30/06/2026, allegato tecnico; art. 44, commi 3 lett. a) e b) e 6, CCNL comparto Istruzione e Ricerca del 18/01/2024',
        'riferimento_regolamento' => $seduta === NULL ? '' : (string) $seduta->get('riferimento_regolamento')->value,
      ],
      'atto' => [
        'numero' => trim((string) $delibera->get('numero_delibera')->value),
        'oggetto' => $delibera->oggettoAtto(),
        'quesito' => (string) $delibera->label(),
        'premesse' => $delibera->premesse(),
        'premesse_formato' => $this->formato($delibera->get('premesse')),
        'dispositivo' => $this->testo($delibera->get('dispositivo')->value),
        'dispositivo_formato' => $this->formato($delibera->get('dispositivo')),
        'esito' => $delibera->esito()?->value ?? '',
        'esito_denominazione' => $delibera->esito()?->etichettaPer($delibera->schemaScheda()) ?? '',
        'deliberato_il' => $this->istante($delibera->get('chiusa_il')->value),
      ],
      'organo' => [
        'tipo' => $seduta === NULL ? '' : $seduta->organo()->value,
        'denominazione' => $seduta === NULL ? '' : $seduta->organo()->etichetta(),
        'denominazione_con_articolo' => $seduta === NULL ? '' : $seduta->organo()->etichettaConArticolo(),
        'lettera_art_44' => $seduta === NULL ? '' : $seduta->organo()->letteraArt44(),
      ],
      'seduta' => [
        'identificativo' => $seduta?->uuid() ?? '',
        'numero' => $seduta === NULL ? '' : (string) $seduta->get('numero')->value,
        'anno_scolastico' => $seduta === NULL ? '' : (string) $seduta->get('anno_scolastico')->value,
        'convocata_per' => $seduta === NULL ? '' : $this->istante($seduta->get('data_seduta')->value),
        'presidente' => $seduta === NULL ? '' : Nominativo::perUtente($seduta->get('presidente')->entity),
        'segretario' => $seduta === NULL ? '' : Nominativo::perUtente($seduta->get('segretario')->entity),
      ],
      'votazione' => [
        'tipo_voto' => $delibera->tipoVoto()->value,
        'schema_scheda' => $delibera->schemaScheda()->value,
        'opzioni' => $delibera->opzioni(),
        'regola_maggioranza' => $delibera->regolaMaggioranza()->value,
        'regola_maggioranza_denominazione' => $delibera->regolaMaggioranza()->etichetta(),
        'aperta_il' => $this->istante($delibera->get('aperta_il')->value),
        'chiusa_il' => $this->istante($delibera->get('chiusa_il')->value),
        'aventi_diritto_al_voto' => (int) $delibera->get('aventi_diritto_al_voto')->value,
        'presenti_al_voto' => (int) $delibera->get('presenti_al_voto')->value,
        'votanti' => (int) $delibera->get('votanti')->value,
        'conteggio' => $delibera->conteggio(),
        'conteggio_denominazioni' => $delibera->vociScheda(),
        'opzioni_prevalenti' => $prevalenti,
        'sigillo_urna' => (string) $delibera->get('sigillo_urna')->value,
        'ripetizione_di' => $delibera->ripetizioneDi()?->uuid() ?? '',
        'ripetizione_di_quesito' => $delibera->ripetizioneDi()?->label() ?? '',
      ],
      // Il legame con il verbale è ciò che rende l'estratto un estratto e non
      // un documento a sé: attesta da quale seduta verbalizzata l'atto è
      // tratto, e con quale impronta quella seduta è stata sigillata.
      'verbale' => [
        'identificativo' => $verbale?->uuid() ?? '',
        'impronta_contenuto' => $verbale === NULL ? '' : (string) $verbale->get('impronta_contenuto')->value,
        'sigillato_il' => $verbale === NULL ? '' : $this->istante($verbale->get('sigillato_il')->value),
        'sigillato_da' => $verbale === NULL ? '' : Nominativo::perUtente($verbale->get('sigillato_da')->entity),
      ],
    ];
  }

  /**
   * Impronta della struttura canonica dell'atto.
   */
  public function impronta(DeliberaInterface $delibera, Verbale $verbale): string {
    return hash('sha256', $this->serializza($this->strutturaCanonica($delibera, $verbale)));
  }

  /**
   * Serializzazione deterministica, identica a quella del verbale.
   */
  public function serializza(array $struttura): string {
    $this->ordinaChiaviRicorsivamente($struttura);

    return (string) json_encode(
      $struttura,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
  }

  /**
   * Esportazione conservata sulla delibera, se ve n'è una.
   *
   * @return array<string, mixed>|null
   */
  private function conservata(DeliberaInterface $delibera): ?array {
    $serializzata = trim((string) $delibera->get('contenuto')->value);

    if ($serializzata === '') {
      return NULL;
    }

    $dati = json_decode($serializzata, TRUE);

    return is_array($dati) ? $dati : NULL;
  }

  /**
   * Verifica un atto sigillato.
   *
   * Come per il verbale, integrità e corrispondenza sono due domande
   * distinte: la prima riguarda l'esportazione conservata, la seconda il
   * fatto che la banca dati racconti ancora la stessa deliberazione.
   *
   * @return array{sigillato: bool, integro: bool, corrispondente: bool, impronta_registrata: string, impronta_ricalcolata: string, impronta_dati_attuali: string}
   */
  public function verifica(DeliberaInterface $delibera, ?Verbale $verbale): array {
    $registrata = trim((string) $delibera->get('impronta_atto')->value);
    $conservata = (string) $delibera->get('contenuto')->value;

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
    $attuale = $verbale === NULL
      ? ''
      : $this->serializza($this->strutturaCanonica($delibera, $verbale));

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
   * Seduta della delibera, letta dall'archivio e non dal riferimento.
   *
   * Il campo entity_reference trattiene l'entità caricata al primo accesso:
   * una delibera consultata mentre la seduta era ancora aperta continuerebbe
   * a vederla aperta anche dopo la chiusura.
   */
  private function sedutaDi(DeliberaInterface $delibera): ?SedutaInterface {
    $identificativo = $delibera->get('seduta')->target_id;

    if ($identificativo === NULL) {
      return NULL;
    }

    $seduta = $this->gestoreEntita->getStorage('psiphos_seduta')->load($identificativo);

    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

  private function istante(mixed $marca): string {
    return $marca === NULL || $marca === '' ? '' : date('c', (int) $marca);
  }

  private function testo(mixed $valore): string {
    return trim((string) ($valore ?? ''));
  }

  private function formato(mixed $campo): string {
    $elemento = $campo?->first();

    return $elemento === NULL ? '' : (string) ($elemento->format ?? '');
  }

  /**
   * Chiavi che sono mappe ma il cui ordine porta un significato.
   *
   * Il conteggio segue l'ordine delle voci sulla scheda, che è quello con cui
   * la deliberazione si legge nell'atto.
   */
  private const ORDINE_SIGNIFICATIVO = ['conteggio', 'conteggio_denominazioni'];

  private function ordinaChiaviRicorsivamente(array &$struttura, string $chiave = ''): void {
    foreach ($struttura as $nome => &$valore) {
      if (is_array($valore)) {
        $this->ordinaChiaviRicorsivamente($valore, (string) $nome);
      }
    }
    unset($valore);

    if (!array_is_list($struttura) && !in_array($chiave, self::ORDINE_SIGNIFICATIVO, TRUE)) {
      ksort($struttura);
    }
  }

}
