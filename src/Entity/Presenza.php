<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\psiphos\Enum\StatoPresenza;

/**
 * Posizione di un avente diritto rispetto a una seduta.
 *
 * Queste righe sono al tempo stesso l'elenco degli aventi diritto e il
 * registro delle presenze: una riga per avente diritto viene creata alla
 * convocazione con stato «atteso» e cambia stato durante la seduta. Tenere
 * un elenco separato dal registro significherebbe avere due fonti di verità
 * per il denominatore dei quorum, con il rischio che divergano.
 *
 * L'impronta di sessione attua il §3.4 sulla prevenzione di accessi
 * simultanei: si conserva l'hash dell'identificativo di sessione, mai
 * l'identificativo in chiaro, in applicazione del principio di
 * minimizzazione del §6.
 *
 * @ContentEntityType(
 *   id = "psiphos_presenza",
 *   label = @Translation("Presenza in seduta"),
 *   label_collection = @Translation("Presenze in seduta"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\psiphos\Access\SedutaAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "psiphos_presenza",
 *   admin_permission = "administer psiphos",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/psiphos/presenza/{psiphos_presenza}",
 *     "delete-form" = "/admin/content/psiphos/presenza/{psiphos_presenza}/elimina",
 *     "collection" = "/admin/content/psiphos/presenza",
 *   },
 * )
 */
class Presenza extends ContentEntityBase {

  public function stato(): StatoPresenza {
    return StatoPresenza::from((string) $this->get('stato')->value);
  }

  public function concorreAlQuorum(): bool {
    return $this->stato()->concorreAlQuorum();
  }

  public function label(): string {
    return sprintf(
      '%s — %s',
      \Drupal\psiphos\Nominativo::perUtente($this->get('utente')->entity),
      $this->stato()->etichetta()
    );
  }

  /**
   * Calcola l'impronta di una sessione senza conservarne l'identificativo.
   */
  public static function improntaSessione(string $identificativoSessione): string {
    return hash_hmac('sha256', $identificativoSessione, (string) \Drupal::service('private_key')->get());
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $campi = parent::baseFieldDefinitions($entity_type);

    $campi['seduta'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seduta'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'psiphos_seduta')
      ->setDisplayConfigurable('view', TRUE);

    $campi['utente'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Avente diritto'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayConfigurable('view', TRUE);

    $campi['stato'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Stato'))
      ->setRequired(TRUE)
      ->setDefaultValue(StatoPresenza::ATTESO->value)
      ->setSetting('allowed_values_function', [self::class, 'opzioniStato'])
      ->setDisplayConfigurable('view', TRUE);

    $campi['ingresso'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Ingresso in aula'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['uscita'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Uscita dall\'aula'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['ultima_attivita'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Ultima attività registrata'))
      ->setDescription(t('Riferimento per l\'interruzione automatica per inattività prevista dal §3.4.'))
      ->setDisplayConfigurable('view', TRUE);

    $campi['impronta_sessione'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Impronta della sessione attiva'))
      ->setDescription(t('Hash dell\'identificativo di sessione, usato per impedire sessioni di voto simultanee. L\'identificativo in chiaro non viene conservato.'))
      ->setSetting('max_length', 64);

    $campi['giustificazione'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Giustificazione dell\'assenza'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    return $campi;
  }

  public static function opzioniStato(): array {
    return StatoPresenza::opzioni();
  }

}
