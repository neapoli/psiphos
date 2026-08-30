<?php

declare(strict_types=1);

namespace Drupal\psiphos\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Entity\Verbale;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controllo di accesso sui verbali.
 *
 * La redazione spetta al segretario verbalizzante designato nella seduta e a
 * nessun altro: il §8 chiede che le figure responsabili della conduzione e
 * della verbalizzazione siano individuate, e individuarle significa anche
 * impedire che altri esercitino quelle funzioni.
 */
final class VerbaleAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static($entity_type, $container->get('entity_type.manager'));
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    assert($entity instanceof Verbale);

    // I divieti che discendono dal sigillo vengono prima di ogni permesso e
    // valgono anche per l'amministratore. Un verbale sigillato che si possa
    // riaprire non è un verbale sigillato, e mostrarne la scheda di redazione
    // a chi non potrà comunque salvarlo è peggio che non mostrarla: promette
    // un'operazione che l'entità rifiuterà.
    if ($entity->sigillato() && in_array($operation, ['update', 'delete'], TRUE)) {
      return AccessResult::forbidden('Il verbale è sigillato: la correzione avviene con un verbale di rettifica.')
        ->addCacheableDependency($entity);
    }

    if ($operation === 'delete') {
      return AccessResult::forbidden('I verbali non si cancellano.')->addCacheableDependency($entity);
    }

    $amministratore = parent::checkAccess($entity, $operation, $account);
    if ($amministratore->isAllowed()) {
      return $amministratore;
    }

    $seduta = $entity->seduta();

    if ($seduta === NULL) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }

    $esito = match ($operation) {
      // Il verbale sigillato è un atto: si consulta con il permesso di
      // consultare i verbali. La bozza no, perché documenta una seduta di
      // cui non si è ancora chiusa la redazione.
      'view' => $entity->sigillato()
        ? $this->accessoAllaConsultazione($seduta, $account)
        : $this->accessoAllaRedazione($entity, $account),
      'update' => $this->accessoAllaRedazione($entity, $account),
      default => AccessResult::neutral(),
    };

    return $esito->addCacheableDependency($entity)->addCacheableDependency($seduta);
  }

  /**
   * Chi può consultare un verbale sigillato.
   *
   * Non chiunque abbia il permesso: solo chi apparteneva a quell'organo, più
   * dirigente e segreteria. Il verbale di un gruppo di lavoro operativo
   * riferisce della disabilità di un alunno determinato, e un permesso valido
   * per tutti gli organi lo aprirebbe a ogni docente dell'istituto.
   */
  private function accessoAllaConsultazione(SedutaInterface $seduta, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('psiphos visualizzare ogni verbale')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (!$account->hasPermission('psiphos visualizzare verbali')) {
      return AccessResult::neutral()->cachePerPermissions();
    }

    return $this->iscrittoAllElenco($seduta, $account)
      ? AccessResult::allowed()->cachePerUser()
      : AccessResult::neutral()->cachePerUser();
  }

  /**
   * Vero se l'utente figura fra gli aventi diritto della seduta.
   */
  private function iscrittoAllElenco(SedutaInterface $seduta, AccountInterface $account): bool {
    if ($seduta->isNew()) {
      return FALSE;
    }

    $trovati = $this->entityTypeManager
      ->getStorage('psiphos_presenza')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('utente', $account->id())
      ->range(0, 1)
      ->execute();

    return $trovati !== [];
  }

  private function accessoAllaRedazione(Verbale $verbale, AccountInterface $account): AccessResultInterface {
    $seduta = $verbale->seduta();
    $segretario = (int) ($seduta?->get('segretario')->target_id ?? 0);

    if ($segretario === (int) $account->id() && $account->hasPermission('psiphos verbalizzare')) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::neutral()->cachePerPermissions();
  }

}
