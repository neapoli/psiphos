<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\psiphos\Enum\EsitoDelibera;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\psiphos\Exception\TransizioneNonAmmessaException;

/**
 * Singola votazione su un punto deliberativo dell'ordine del giorno.
 *
 * Tipo di voto e regola di maggioranza sono immutabili una volta aperta
 * l'urna: il §4.1 chiede che l'esito sia «determinato in modo corretto e
 * verificabile», e un criterio modificabile a votazione iniziata renderebbe
 * la verifica priva di oggetto.
 *
 * @ContentEntityType(
 *   id = "psiphos_delibera",
 *   label = @Translation("Delibera"),
 *   label_collection = @Translation("Delibere"),
 *   handlers = {
 *     "view_builder" = "Drupal\psiphos\DeliberaViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\psiphos\Access\SedutaAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "add" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "edit" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "atto" = "Drupal\psiphos\Form\AttoDeliberaForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "psiphos_delibera",
 *   admin_permission = "administer psiphos",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "quesito",
 *   },
 *   links = {
 *     "canonical" = "/psiphos/delibera/{psiphos_delibera}",
 *     "add-form" = "/admin/content/psiphos/delibera/aggiungi",
 *     "edit-form" = "/admin/content/psiphos/delibera/{psiphos_delibera}/modifica",
 *     "delete-form" = "/admin/content/psiphos/delibera/{psiphos_delibera}/elimina",
 *     "collection" = "/admin/content/psiphos/delibera",
 *   },
 * )
 */
class Delibera extends ContentEntityBase implements DeliberaInterface {

  use EntityChangedTrait;

  public function stato(): StatoDelibera {
    return StatoDelibera::from((string) $this->get('stato')->value);
  }

  public function tipoVoto(): TipoVoto {
    return TipoVoto::from((string) $this->get('tipo_voto')->value);
  }

  public function regolaMaggioranza(): RegolaMaggioranza {
    return RegolaMaggioranza::from((string) $this->get('regola_maggioranza')->value);
  }

  public function schemaScheda(): SchemaScheda {
    return SchemaScheda::from((string) $this->get('schema_scheda')->value);
  }

  /**
   * Opzioni personalizzate, nell'ordine di presentazione sulla scheda.
   *
   * @return array<int, string>
   */
  public function opzioni(): array {
    $opzioni = [];
    foreach ($this->get('opzioni') as $elemento) {
      $testo = trim((string) $elemento->value);
      if ($testo !== '') {
        $opzioni[] = $testo;
      }
    }
    return $opzioni;
  }

  /**
   * Voci stampate sulla scheda: chiave tecnica mappata sul testo mostrato.
   *
   * @return array<string, string>
   */
  public function vociScheda(): array {
    return $this->schemaScheda()->voci($this->opzioni());
  }

  public function preferenzeMassime(): int {
    return $this->schemaScheda() === SchemaScheda::SCELTA_MULTIPLA
      ? max(1, (int) $this->get('preferenze_massime')->value)
      : 1;
  }

  /**
   * Conteggio dello scrutinio: chiave di voce mappata sul numero di voti.
   *
   * @return array<string, int>
   */
  public function conteggio(): array {
    $valore = $this->get('conteggio')->first()?->getValue();
    if (!is_array($valore)) {
      return [];
    }
    // MapItem può restituire il contenuto annidato sotto "value".
    $mappa = isset($valore['value']) && is_array($valore['value']) ? $valore['value'] : $valore;
    return array_map('intval', array_filter($mappa, 'is_numeric'));
  }

  /**
   * Verifica la coerenza della scheda, indipendentemente dal salvataggio.
   *
   * Esposta come metodo pubblico perché anche i form la richiamino prima di
   * arrivare a preSave(), dove l'errore diventa un'eccezione anziché un
   * messaggio di validazione leggibile.
   *
   * @throws \InvalidArgumentException
   */
  public function validaScheda(): void {
    $schema = $this->schemaScheda();
    $opzioni = $this->opzioni();

    if ($this->regolaMaggioranza()->richiedeSchedaAScelta() && !$schema->richiedeOpzioni()) {
      throw new \InvalidArgumentException(
        'La maggioranza relativa presuppone una graduatoria fra opzioni: non è applicabile a una scheda di approvazione.'
      );
    }

    if (!$schema->richiedeOpzioni()) {
      if ($opzioni !== []) {
        throw new \InvalidArgumentException('La scheda di approvazione ha voci fisse: non ammette opzioni personalizzate.');
      }
      return;
    }

    if (count($opzioni) < 2) {
      throw new \InvalidArgumentException('Una scheda a scelta richiede almeno due opzioni.');
    }

    if (count(array_unique($opzioni)) !== count($opzioni)) {
      throw new \InvalidArgumentException('Le opzioni della scheda devono essere distinte fra loro.');
    }

    if ($schema === SchemaScheda::SCELTA_MULTIPLA) {
      $massime = (int) $this->get('preferenze_massime')->value;
      if ($massime < 1 || $massime >= count($opzioni)) {
        throw new \InvalidArgumentException(sprintf(
          'Le preferenze esprimibili devono essere comprese fra 1 e %d, il numero di opzioni meno una.',
          count($opzioni) - 1
        ));
      }
    }
  }

  public function esito(): ?EsitoDelibera {
    $valore = $this->get('esito')->value;
    return $valore === NULL || $valore === '' ? NULL : EsitoDelibera::from((string) $valore);
  }

  public function seduta(): ?SedutaInterface {
    $seduta = $this->get('seduta')->entity;
    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

  public function urnaAperta(): bool {
    return $this->stato()->urnaAperta();
  }

  public function ripetizioneDi(): ?DeliberaInterface {
    $precedente = $this->get('ripetizione_di')->entity;
    return $precedente instanceof DeliberaInterface ? $precedente : NULL;
  }

  /**
   * Oggetto dell'atto: il titolo con cui la delibera circola da sola.
   *
   * Il quesito è formulato per essere messo ai voti («Approvazione del
   * PAI 2025/2026»); l'atto porta il titolo del documento approvato
   * («Piano Annuale per l'Inclusione 2025/2026»). Dove non si è indicato
   * un oggetto proprio vale il quesito, che è sempre presente.
   */
  public function oggettoAtto(): string {
    $oggetto = trim((string) $this->get('oggetto')->value);

    return $oggetto !== '' ? $oggetto : (string) $this->label();
  }

  /**
   * Premesse dell'atto: i «visto», i «tenuto conto», i «considerato».
   *
   * Un solo campo di testo e non un elenco a valori multipli: chi redige le
   * incolla da una delibera precedente, e un widget che chiede di aggiungere
   * un elemento per volta trasforma un'operazione di dieci secondi in una di
   * due minuti. La struttura per riga non serviva a nessuno: nessuno elabora
   * i singoli «visto» a macchina.
   */
  public function premesse(): string {
    return trim((string) $this->get('premesse')->value);
  }

  /**
   * Vero se la delibera è un atto da formalizzare in un proprio documento.
   *
   * Solo le votazioni concluse lo sono. Una votazione annullata resta agli
   * atti nel verbale ma non produce alcuna deliberazione: darle un estratto
   * significherebbe far circolare come atto ciò che il §8 vuole privo di
   * effetti.
   */
  public function daFormalizzare(): bool {
    return $this->stato() === StatoDelibera::CHIUSA && $this->esito() !== NULL;
  }

  /**
   * Che cosa manca perché l'atto possa essere sigillato.
   *
   * @return array<int, string>
   *   Elenco vuoto quando l'atto è completo.
   */
  public function lacuneAtto(): array {
    $lacune = [];

    if (trim((string) $this->get('numero_delibera')->value) === '') {
      $lacune[] = (string) t('il numero di delibera');
    }

    if (trim((string) $this->get('dispositivo')->value) === '') {
      $lacune[] = (string) t('il dispositivo');
    }

    return $lacune;
  }

  /**
   * Le lacune dell'atto in forma di frase, o stringa vuota se non ve ne sono.
   *
   * Sta qui, e non nei due punti in cui il messaggio compare, perché il
   * numero delle lacune governa sia il verbo sia la congiunzione: «manca il
   * dispositivo» ma «mancano il numero di delibera e il dispositivo». Ripetere
   * la costruzione altrove significa dimenticarsene in uno dei due.
   */
  public function descrizioneLacune(): string {
    $lacune = $this->lacuneAtto();

    if ($lacune === []) {
      return '';
    }

    $ultima = array_pop($lacune);
    $elenco = $lacune === [] ? $ultima : implode(', ', $lacune) . ' ' . t('e') . ' ' . $ultima;

    return (string) \Drupal::translation()->formatPlural(
      count($this->lacuneAtto()),
      'manca @elenco',
      'mancano @elenco',
      ['@elenco' => $elenco]
    );
  }

  /**
   * Vero se l'atto è già stato sigillato e non è più redigibile.
   */
  public function attoSigillato(): bool {
    return trim((string) $this->get('impronta_atto')->value) !== '';
  }

  public function transitaA(StatoDelibera $destinazione, ?string $motivazione = NULL): static {
    $attuale = $this->stato();

    if (!$attuale->ammetteTransizioneA($destinazione)) {
      throw TransizioneNonAmmessaException::per(
        sprintf('delibera %s', $this->id() ?? 'nuova'),
        $attuale->value,
        $destinazione->value,
        array_map(static fn (StatoDelibera $s): string => $s->value, $attuale->transizioniAmmesse())
      );
    }

    if (StatoDelibera::richiedeMotivazione($destinazione) && trim((string) $motivazione) === '') {
      throw new \InvalidArgumentException(sprintf(
        'Il passaggio allo stato "%s" richiede una motivazione scritta (§8 dell\'allegato tecnico).',
        $destinazione->value
      ));
    }

    $this->set('stato', $destinazione->value);

    if ($motivazione !== NULL && trim($motivazione) !== '') {
      $this->set('motivazione', trim($motivazione));
    }

    $adesso = \Drupal::time()->getRequestTime();

    if ($destinazione === StatoDelibera::IN_VOTAZIONE && $this->get('aperta_il')->value === NULL) {
      $this->set('aperta_il', $adesso);
      // Il denominatore dei quorum va congelato all'apertura dell'urna: dopo,
      // ingressi e uscite dall'aula non devono più poter spostare l'esito.
      $seduta = $this->seduta();
      if ($seduta !== NULL) {
        $this->set('presenti_al_voto', $seduta->numeroPresenti());
        $this->set('aventi_diritto_al_voto', $seduta->aventiDirittoAllApertura() ?? $seduta->numeroAventiDiritto());
      }
    }

    if ($destinazione === StatoDelibera::CHIUSA) {
      $this->set('chiusa_il', $adesso);
    }

    if ($destinazione === StatoDelibera::ANNULLATA) {
      $this->set('esito', EsitoDelibera::ANNULLATA->value);
    }

    return $this;
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // La seduta è derivata dal punto all'ordine del giorno: tenerla
    // denormalizzata evita un join su ogni verifica di accesso, ma non deve
    // poter divergere dalla catena punto -> seduta.
    $punto = $this->get('punto_odg')->entity;
    if ($punto instanceof PuntoOdg && $punto->seduta() !== NULL) {
      $this->set('seduta', $punto->seduta()->id());
    }

    if ($this->isNew() || !isset($this->original)) {
      $this->validaScheda();
      return;
    }

    // Sigillato l'atto, la delibera non cambia più in alcuna parte: è la
    // stessa regola del verbale, e vale anche per l'amministratore. Da qui
    // in avanti il documento è circolato, e correggerlo significherebbe
    // avere in giro due atti diversi con lo stesso numero.
    if ($this->original->attoSigillato()) {
      throw new \LogicException(sprintf(
        'La delibera %s è sigillata e non è più modificabile. Per correggerla occorre una nuova deliberazione.',
        $this->id()
      ));
    }

    $precedente = StatoDelibera::from((string) $this->original->get('stato')->value);
    $nuovo = $this->stato();

    if ($precedente !== $nuovo && !$precedente->ammetteTransizioneA($nuovo)) {
      throw TransizioneNonAmmessaException::per(
        sprintf('delibera %s', $this->id()),
        $precedente->value,
        $nuovo->value,
        array_map(static fn (StatoDelibera $s): string => $s->value, $precedente->transizioniAmmesse())
      );
    }

    // Aperta l'urna non cambiano più né il criterio di conteggio né la
    // scheda: il conteggio dev'essere confrontabile con quanto messo ai voti.
    if ($precedente !== StatoDelibera::PREDISPOSTA) {
      if ($this->opzioni() !== $this->original->opzioni()) {
        throw new \LogicException(sprintf(
          'Le opzioni della delibera %s non sono modificabili dopo l\'apertura della votazione.',
          $this->id()
        ));
      }
      foreach (['tipo_voto', 'regola_maggioranza', 'schema_scheda', 'preferenze_massime'] as $bloccato) {
        // Confronto per rappresentazione testuale: i campi interi tornano
        // dal database come stringhe, e un confronto stretto segnalerebbe
        // una modifica là dove il valore è rimasto identico.
        if ((string) $this->get($bloccato)->value !== (string) $this->original->get($bloccato)->value) {
          throw new \LogicException(sprintf(
            'Il campo "%s" della delibera %s non è modificabile dopo l\'apertura della votazione.',
            $bloccato,
            $this->id()
          ));
        }
      }
    }

    // Da ultimo, perché su una votazione già aperta l'utente deve leggere
    // che il dato non è modificabile, non che la scheda è incoerente.
    $this->validaScheda();
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $campi = parent::baseFieldDefinitions($entity_type);

    $campi['punto_odg'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Punto all\'ordine del giorno'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'psiphos_punto_odg')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['seduta'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seduta'))
      ->setDescription(t('Derivata dal punto all\'ordine del giorno e mantenuta per le verifiche di accesso.'))
      ->setSetting('target_type', 'psiphos_seduta')
      ->setDisplayConfigurable('view', TRUE);

    $campi['numero_delibera'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Numero di delibera'))
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', ['weight' => -9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['quesito'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Quesito posto ai voti'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 500)
      ->setDisplayOptions('form', ['weight' => -8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['tipo_voto'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Modalità di voto'))
      ->setRequired(TRUE)
      ->setDefaultValue(TipoVoto::PALESE->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniTipoVoto'])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => -7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['schema_scheda'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Struttura della scheda'))
      ->setRequired(TRUE)
      ->setDefaultValue(SchemaScheda::APPROVAZIONE->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniSchemaScheda'])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['opzioni'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Opzioni della scheda'))
      ->setDescription(t('Solo per le schede a scelta. Almeno due opzioni distinte, nell\'ordine in cui compaiono sulla scheda. La scheda bianca è sempre presente e non va inserita qui.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['weight' => -4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['preferenze_massime'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Preferenze esprimibili'))
      ->setDescription(t('Solo per le schede a scelta multipla. Deve restare inferiore al numero di opzioni, altrimenti la votazione non seleziona nulla.'))
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('form', ['weight' => -3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['regola_maggioranza'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Maggioranza richiesta'))
      ->setRequired(TRUE)
      ->setDefaultValue(RegolaMaggioranza::MAGGIORANZA_VOTANTI->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniRegolaMaggioranza'])
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['stato'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Stato della votazione'))
      ->setRequired(TRUE)
      ->setDefaultValue(StatoDelibera::PREDISPOSTA->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniStato'])
      ->setDisplayConfigurable('view', TRUE);

    $campi['esito'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Esito'))
      ->setSetting('allowed_values_function', [self::class, 'opzioniEsito'])
      ->setDisplayConfigurable('view', TRUE);

    $campi['conteggio'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Conteggio dello scrutinio'))
      ->setDescription(t('Voci della scheda mappate sul numero di voti ricevuti. Una sola rappresentazione per tutte le strutture di scheda, così il verbale e l\'esportazione leggono sempre lo stesso dato.'));

    $campi['opzioni_prevalenti'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Opzioni prevalenti'))
      ->setDescription(t('Voci che hanno raggiunto la maggioranza richiesta. Valorizzato alla chiusura dello scrutinio.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $campi['sigillo_urna'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Sigillo dell\'urna'))
      ->setDescription(t('Impronta SHA-256 calcolata sull\'insieme ordinato delle schede alla chiusura dello scrutinio. Consente di ricontare l\'urna e accorgersi di qualsiasi scheda aggiunta, rimossa o alterata in seguito, senza rivelare alcun voto individuale.'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    foreach ([
      'votanti' => t('Votanti'),
      'presenti_al_voto' => t('Presenti all\'apertura dell\'urna'),
      'aventi_diritto_al_voto' => t('Aventi diritto all\'apertura dell\'urna'),
    ] as $nome => $etichetta) {
      $campi[$nome] = BaseFieldDefinition::create('integer')
        ->setLabel($etichetta)
        ->setSetting('unsigned', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $campi['aperta_il'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Apertura dell\'urna'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['chiusa_il'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Chiusura dell\'urna'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['motivazione'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Motivazione di sospensione o annullamento'))
      ->setDescription(t('Obbligatoria per sospendere o annullare la votazione (§8 dell\'allegato tecnico).'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['ripetizione_di'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Ripetizione della votazione'))
      ->setDescription(t('Votazione annullata che questa delibera ripete. La ripetizione non riapre l\'urna precedente: ne apre una nuova, e l\'esito annullato resta agli atti. Da compilare solo quando si ripete una votazione annullata ai sensi del §8 dell\'allegato tecnico.'))
      ->setSetting('target_type', 'psiphos_delibera')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 12])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['oggetto'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Oggetto dell\'atto'))
      ->setDescription(t('Titolo con cui la delibera circola come documento autonomo, se diverso dal quesito posto ai voti. Lasciandolo vuoto vale il quesito.'))
      ->setSetting('max_length', 500)
      ->setDisplayOptions('form', ['weight' => 19])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['premesse'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Premesse'))
      ->setDescription(t('I riferimenti normativi e istruttori dell\'atto, nella forma in uso: «Visto il DPR 275/1999», «Vista la L. 107/2015», «Tenuto conto della proposta del GLI». Compaiono nell\'estratto fra la denominazione dell\'organo e il dispositivo. Si possono preparare già ora e correggere fino al sigillo.'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'rows' => 8, 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['dispositivo'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Dispositivo'))
      ->setDescription(t('Che cosa l\'organo delibera, nella formulazione che farà testo: «Approva il Piano Annuale per l\'Inclusione 2025/2026, allegato alla presente delibera». I numeri della votazione non vanno scritti qui: li compone il sistema leggendoli dall\'urna.'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'rows' => 4, 'weight' => 21])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['documento'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Estratto di delibera'))
      ->setDescription(t('Documento dell\'atto, prodotto al sigillo del verbale.'))
      ->setSetting('file_extensions', 'pdf')
      ->setSetting('uri_scheme', 'private')
      ->setDisplayConfigurable('view', TRUE);

    $campi['contenuto'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Esportazione conservata'))
      ->setDescription(t("Esportazione strutturata dell'atto, serializzata una volta sola al momento del sigillo. È il documento su cui l'impronta è calcolata e da cui l'estratto è generato."))
      ->setDisplayConfigurable('view', FALSE);

    $campi['impronta_atto'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Impronta dell\'atto'))
      ->setDescription(t('SHA-256 dell\'esportazione strutturata dell\'atto. Ricalcolabile da chiunque ne disponga.'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $campi['impronta_documento'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Impronta dell\'estratto'))
      ->setDescription(t('SHA-256 del file dell\'estratto di delibera.'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $campi['formato'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Formato di archiviazione dell\'estratto'))
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('view', TRUE);

    $campi['created'] = BaseFieldDefinition::create('created')->setLabel(t('Creata il'));
    $campi['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Modificata il'));

    return $campi;
  }

  public static function opzioniTipoVoto(): array {
    return TipoVoto::opzioni();
  }

  public static function opzioniRegolaMaggioranza(): array {
    return RegolaMaggioranza::opzioni();
  }

  public static function opzioniStato(): array {
    return StatoDelibera::opzioni();
  }

  public static function opzioniEsito(): array {
    return EsitoDelibera::opzioni();
  }

  public static function opzioniSchemaScheda(): array {
    return SchemaScheda::opzioni();
  }

}
