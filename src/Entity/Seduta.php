<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\psiphos\Enum\QuorumCostitutivo;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Exception\TransizioneNonAmmessaException;

/**
 * Seduta di un organo collegiale svolta in tutto o in parte a distanza.
 *
 * @ContentEntityType(
 *   id = "psiphos_seduta",
 *   label = @Translation("Seduta collegiale"),
 *   label_collection = @Translation("Sedute collegiali"),
 *   label_singular = @Translation("seduta collegiale"),
 *   label_plural = @Translation("sedute collegiali"),
 *   handlers = {
 *     "view_builder" = "Drupal\psiphos\SedutaViewBuilder",
 *     "list_builder" = "Drupal\psiphos\SedutaListBuilder",
 *     "access" = "Drupal\psiphos\Access\SedutaAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "add" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "edit" = "Drupal\psiphos\Form\ContenutoSedutaForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "psiphos_seduta",
 *   admin_permission = "administer psiphos",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "titolo",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "canonical" = "/psiphos/seduta/{psiphos_seduta}",
 *     "add-form" = "/admin/content/psiphos/seduta/aggiungi",
 *     "edit-form" = "/admin/content/psiphos/seduta/{psiphos_seduta}/modifica",
 *     "delete-form" = "/admin/content/psiphos/seduta/{psiphos_seduta}/elimina",
 *     "collection" = "/admin/content/psiphos/seduta",
 *   },
 * )
 */
class Seduta extends ContentEntityBase implements SedutaInterface {

  use EntityChangedTrait;

  public function organo(): TipoOrgano {
    return TipoOrgano::from((string) $this->get('organo')->value);
  }

  public function stato(): StatoSeduta {
    return StatoSeduta::from((string) $this->get('stato')->value);
  }

  public function quorumCostitutivo(): QuorumCostitutivo {
    return QuorumCostitutivo::from((string) $this->get('quorum_costitutivo')->value);
  }

  public function transitaA(StatoSeduta $destinazione): static {
    $attuale = $this->stato();

    if (!$attuale->ammetteTransizioneA($destinazione)) {
      throw TransizioneNonAmmessaException::per(
        sprintf('seduta %s', $this->id() ?? 'nuova'),
        $attuale->value,
        $destinazione->value,
        array_map(static fn (StatoSeduta $s): string => $s->value, $attuale->transizioniAmmesse())
      );
    }

    $this->set('stato', $destinazione->value);

    if ($destinazione === StatoSeduta::APERTA) {
      $this->set('aperta_il', \Drupal::time()->getRequestTime());
      // L'elenco degli aventi diritto è il denominatore di ogni quorum:
      // va congelato qui, perché resti verificabile anche se muta dopo.
      $this->set('aventi_diritto_apertura', $this->numeroAventiDiritto());
    }

    if ($destinazione === StatoSeduta::CHIUSA) {
      $this->set('chiusa_il', \Drupal::time()->getRequestTime());
    }

    return $this;
  }

  public function numeroAventiDiritto(): int {
    if ($this->isNew()) {
      return 0;
    }
    return (int) $this->interrogaPresenze()->count()->execute();
  }

  public function numeroPresenti(): int {
    if ($this->isNew()) {
      return 0;
    }
    return (int) $this->interrogaPresenze()
      ->condition('stato', StatoPresenza::PRESENTE->value)
      ->count()
      ->execute();
  }

  public function aventiDirittoAllApertura(): ?int {
    $valore = $this->get('aventi_diritto_apertura')->value;
    return $valore === NULL ? NULL : (int) $valore;
  }

  public function validamenteCostituita(): bool {
    // Prima dell'apertura si usa l'elenco corrente, dopo il valore congelato:
    // il quorum va sempre verificato sul denominatore effettivamente in
    // vigore al momento della verifica.
    $aventiDiritto = $this->aventiDirittoAllApertura() ?? $this->numeroAventiDiritto();
    return $this->numeroPresenti() >= $this->quorumCostitutivo()->minimoPresenti($aventiDiritto);
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Difesa in profondità: transitaA() è la via corretta, ma una scrittura
    // diretta su "stato" non deve poter aggirare la macchina a stati.
    if (!$this->isNew() && isset($this->original)) {
      $precedente = StatoSeduta::from((string) $this->original->get('stato')->value);
      $nuovo = $this->stato();
      if ($precedente !== $nuovo && !$precedente->ammetteTransizioneA($nuovo)) {
        throw TransizioneNonAmmessaException::per(
          sprintf('seduta %s', $this->id()),
          $precedente->value,
          $nuovo->value,
          array_map(static fn (StatoSeduta $s): string => $s->value, $precedente->transizioniAmmesse())
        );
      }

      if ($nuovo === StatoSeduta::CHIUSA && $precedente !== StatoSeduta::CHIUSA) {
        $this->vietaChiusuraConVotazioniPendenti();
      }
    }
  }

  /**
   * Impedisce di chiudere i lavori mentre una votazione è ancora in corso.
   *
   * Il divieto sta qui e non nel banco di presidenza perché una seduta chiusa
   * con l'urna aperta non è uno stato che si possa raggiungere per una strada
   * anziché per un'altra: è uno stato che non deve esistere. Vi si continuava
   * a votare, e la delibera restava priva di esito, fuori dal verbale e fuori
   * dagli atti — un voto raccolto in una seduta che non c'era più.
   *
   * @throws \Drupal\psiphos\Exception\TransizioneNonAmmessaException
   */
  private function vietaChiusuraConVotazioniPendenti(): void {
    $pendenti = \Drupal::entityTypeManager()->getStorage('psiphos_delibera')->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $this->id())
      ->condition('stato', [StatoDelibera::IN_VOTAZIONE->value, StatoDelibera::SOSPESA->value], 'IN')
      ->range(0, 1)
      ->execute();

    if ($pendenti === []) {
      return;
    }

    throw TransizioneNonAmmessaException::per(
      sprintf('seduta %s', $this->id()),
      StatoSeduta::APERTA->value,
      StatoSeduta::CHIUSA->value,
      ['chiudere o annullare prima le votazioni ancora aperte o sospese']
    );
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $campi = parent::baseFieldDefinitions($entity_type);

    $campi['titolo'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Oggetto della seduta'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['organo'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Organo collegiale'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values_function', [self::class, 'opzioniOrgano'])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['numero'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Numero di seduta'))
      ->setDescription(t('Numerazione progressiva adottata dall\'istituto, ad esempio «3/2026-27».'))
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', ['weight' => -8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['anno_scolastico'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Anno scolastico'))
      ->setSetting('max_length', 9)
      ->setDisplayOptions('form', ['weight' => -7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['data_convocazione'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Data della convocazione'))
      ->setDescription(t('Data in cui la convocazione è stata diramata agli aventi diritto.'))
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['data_seduta'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Data e ora di convocazione della seduta'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['modalita'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Modalità di svolgimento'))
      ->setRequired(TRUE)
      ->setDefaultValue('distanza')
      ->setSetting('allowed_values_function', [self::class, 'opzioniModalita'])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => -4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['url_videoconferenza'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Collegamento alla videoconferenza'))
      ->setDescription(t('Strumento audio-video utilizzato per la seduta. Psíphos non lo eroga: l\'allegato tecnico ammette espressamente l\'integrazione di più strumenti, purché ciascuno sia conforme per la propria funzione.'))
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', ['weight' => -3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['presidente'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Presidente'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['segretario'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Segretario verbalizzante'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['quorum_costitutivo'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Quorum costitutivo'))
      ->setRequired(TRUE)
      ->setDefaultValue(QuorumCostitutivo::META_PIU_UNO->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniQuorumCostitutivo'])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['riferimento_regolamento'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Riferimento al Regolamento d\'istituto'))
      // Obbligatorio, non facoltativo. Il §8 impone che il Regolamento
      // disciplini lo svolgimento a distanza, e senza quella copertura la
      // deliberazione è impugnabile: convocare una seduta senza indicare
      // l'articolo che la legittima è il difetto che si scopre dopo, quando
      // qualcuno impugna. Meglio non poterla convocare.
      ->setRequired(TRUE)
      ->setDescription(t('Articolo del Regolamento d\'istituto che disciplina lo svolgimento a distanza di questa tipologia di seduta. Richiesto dal §8 dell\'allegato tecnico: senza copertura regolamentare la deliberazione è impugnabile.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['stato'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Stato'))
      ->setRequired(TRUE)
      ->setDefaultValue(StatoSeduta::CONVOCATA->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniStato'])
      ->setDisplayConfigurable('view', TRUE);

    $campi['aventi_diritto_apertura'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Aventi diritto all\'apertura'))
      ->setDescription(t('Numero di aventi diritto cristallizzato all\'apertura della seduta e usato come denominatore dei quorum.'))
      ->setSetting('unsigned', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['aperta_il'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Apertura effettiva'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['chiusa_il'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Chiusura effettiva'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['note_procedurali'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Note procedurali e malfunzionamenti'))
      ->setDescription(t('Annotazioni su interruzioni, malfunzionamenti e provvedimenti adottati nel corso della seduta (§8 dell\'allegato tecnico).'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Convocata da'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::autoreCorrente')
      ->setDisplayConfigurable('view', TRUE);

    $campi['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Creata il'));

    $campi['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Modificata il'));

    return $campi;
  }

  public static function autoreCorrente(): array {
    return [['target_id' => (int) \Drupal::currentUser()->id()]];
  }

  public static function opzioniOrgano(): array {
    return TipoOrgano::opzioni();
  }

  public static function opzioniStato(): array {
    return StatoSeduta::opzioni();
  }

  public static function opzioniQuorumCostitutivo(): array {
    return QuorumCostitutivo::opzioni();
  }

  public static function opzioniModalita(): array {
    return [
      'distanza' => (string) t('Interamente a distanza'),
      'mista' => (string) t('Mista, in presenza e a distanza'),
    ];
  }

  /**
   * Query sulle presenze di questa seduta.
   */
  private function interrogaPresenze(): \Drupal\Core\Entity\Query\QueryInterface {
    return \Drupal::entityTypeManager()
      ->getStorage('psiphos_presenza')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $this->id());
  }

}
