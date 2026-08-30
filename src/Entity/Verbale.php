<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\psiphos\Enum\StatoVerbale;

/**
 * Verbale di una seduta collegiale.
 *
 * Una volta sigillato non è più modificabile in alcuna parte: il §7 lega
 * l'immodificabilità del documento alla sua conservabilità, e un verbale che
 * possa essere ritoccato dopo la chiusura non documenta più la seduta che si
 * è svolta ma quella che si preferisce ricordare.
 *
 * Le impronte sono due perché due sono le cose da garantire: quella del
 * contenuto attesta che i dati della seduta non sono cambiati e può essere
 * ricalcolata da chiunque a partire dall'esportazione strutturata; quella
 * del file attesta che il PDF consegnato alla conservazione è esattamente
 * quello prodotto al momento del sigillo.
 *
 * @ContentEntityType(
 *   id = "psiphos_verbale",
 *   label = @Translation("Verbale di seduta"),
 *   label_collection = @Translation("Verbali di seduta"),
 *   handlers = {
 *     "view_builder" = "Drupal\psiphos\VerbaleViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\psiphos\Access\VerbaleAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\psiphos\Form\VerbaleForm",
 *       "edit" = "Drupal\psiphos\Form\VerbaleForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "psiphos_verbale",
 *   admin_permission = "administer psiphos",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/psiphos/verbale/{psiphos_verbale}",
 *     "edit-form" = "/psiphos/verbale/{psiphos_verbale}/redigi",
 *     "collection" = "/admin/content/psiphos/verbale",
 *   },
 * )
 */
class Verbale extends ContentEntityBase {

  use EntityChangedTrait;

  public function stato(): StatoVerbale {
    return StatoVerbale::from((string) $this->get('stato')->value);
  }

  public function seduta(): ?SedutaInterface {
    $seduta = $this->get('seduta')->entity;
    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

  public function sigillato(): bool {
    return $this->stato() === StatoVerbale::SIGILLATO;
  }

  public function label(): string {
    $seduta = $this->seduta();
    return $seduta === NULL
      ? (string) t('Verbale')
      : (string) t('Verbale — @seduta', ['@seduta' => $seduta->label()]);
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->isNew() || !isset($this->original)) {
      return;
    }

    if ($this->original->stato() !== StatoVerbale::SIGILLATO) {
      return;
    }

    // Nessuna eccezione al sigillo, nemmeno per gli amministratori: se un
    // verbale sigillato risulta errato la strada è un verbale di rettifica,
    // che lascia traccia di entrambi, non una modifica silenziosa.
    throw new \LogicException(sprintf(
      'Il verbale %s è sigillato e non è più modificabile. Per correggerlo occorre un verbale di rettifica.',
      $this->id()
    ));
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $campi = parent::baseFieldDefinitions($entity_type);

    $campi['seduta'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seduta'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'psiphos_seduta')
      ->setDisplayConfigurable('view', TRUE);

    $campi['testo'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Svolgimento della seduta'))
      ->setDescription(t("Resoconto redatto dal segretario verbalizzante. Le presenze, le votazioni e gli esiti sono già documentati automaticamente e non vanno ricopiati qui: questo spazio serve alla discussione, agli interventi e a quanto non è deducibile dai dati."))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'rows' => 20, 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['stato'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Stato'))
      ->setRequired(TRUE)
      ->setDefaultValue(StatoVerbale::BOZZA->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniStato'])
      ->setDisplayConfigurable('view', TRUE);

    $campi['contenuto'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Esportazione conservata'))
      ->setDescription(t("Esportazione strutturata della seduta, serializzata una volta sola al momento del sigillo. È il documento su cui l'impronta è calcolata e da cui il PDF è generato: conservare i byte anziché ricostruirli è ciò che rende l'impronta ripetibile nel tempo."))
      ->setDisplayConfigurable('view', FALSE);

    $campi['impronta_contenuto'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Impronta del contenuto'))
      ->setDescription(t("SHA-256 dell'esportazione conservata. Ricalcolabile da chiunque disponga dell'esportazione, con un qualunque strumento."))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $campi['impronta_pdf'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Impronta del documento'))
      ->setDescription(t('SHA-256 del file consegnato alla conservazione.'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $campi['documento'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Documento del verbale'))
      ->setSetting('file_extensions', 'pdf')
      ->setSetting('uri_scheme', 'private')
      ->setDisplayConfigurable('view', TRUE);

    $campi['formato'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Formato di archiviazione'))
      ->setDescription(t('PDF/A-2B se la conversione conforme alle Linee guida AgID è riuscita, PDF altrimenti.'))
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('view', TRUE);

    $campi['sigillato_il'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Sigillato il'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['sigillato_da'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Sigillato da'))
      ->setSetting('target_type', 'user')
      ->setDisplayConfigurable('view', TRUE);

    $campi['created'] = BaseFieldDefinition::create('created')->setLabel(t('Creato il'));
    $campi['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Modificato il'));

    return $campi;
  }

  public static function opzioniStato(): array {
    return StatoVerbale::opzioni();
  }

}
