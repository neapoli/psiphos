<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Punto all'ordine del giorno di una seduta collegiale.
 *
 * Non tutti i punti sono deliberativi: la nota MIM riguarda le sole attività
 * «che rivestono carattere deliberativo», mentre l'ordine del giorno di una
 * seduta ne contiene abitualmente anche di informativi. La distinzione va
 * mantenuta a dato, perché determina quali punti richiedono i presidi
 * tecnici dell'allegato e quali no.
 *
 * @ContentEntityType(
 *   id = "psiphos_punto_odg",
 *   label = @Translation("Punto all'ordine del giorno"),
 *   label_collection = @Translation("Punti all'ordine del giorno"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
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
 *   base_table = "psiphos_punto_odg",
 *   admin_permission = "administer psiphos",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "oggetto",
 *   },
 *   links = {
 *     "canonical" = "/psiphos/punto/{psiphos_punto_odg}",
 *     "add-form" = "/admin/content/psiphos/punto/aggiungi",
 *     "edit-form" = "/admin/content/psiphos/punto/{psiphos_punto_odg}/modifica",
 *     "delete-form" = "/admin/content/psiphos/punto/{psiphos_punto_odg}/elimina",
 *     "collection" = "/admin/content/psiphos/punto",
 *   },
 * )
 */
class PuntoOdg extends ContentEntityBase {

  use EntityChangedTrait;

  public function seduta(): ?SedutaInterface {
    $seduta = $this->get('seduta')->entity;
    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

  public function deliberativo(): bool {
    return (bool) $this->get('deliberativo')->value;
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $campi = parent::baseFieldDefinitions($entity_type);

    $campi['seduta'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seduta'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'psiphos_seduta')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['numero'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Numero del punto'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('form', ['weight' => -9])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['oggetto'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Oggetto'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 500)
      ->setDisplayOptions('form', ['weight' => -8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['descrizione'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Illustrazione del punto'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => -7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['deliberativo'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Punto deliberativo'))
      ->setDescription(t('Se disattivato il punto è di sola informazione o discussione e non dà luogo a votazioni.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => -6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $campi['created'] = BaseFieldDefinition::create('created')->setLabel(t('Creato il'));
    $campi['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Modificato il'));

    return $campi;
  }

}
