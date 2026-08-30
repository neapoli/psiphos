<?php

declare(strict_types=1);

namespace Drupal\psiphos\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Service\Verbalizzazione;

/**
 * Accesso al verbale di una seduta.
 *
 * La scheda del verbale non deve comparire a chi non ha nulla da vederci.
 * Drupal ricava la visibilità delle schede dall'accesso alla rotta, e una
 * rotta accessibile che porta a una pagina negata è peggio di una scheda
 * assente: chi la vede pensa di avere un problema di permessi quando invece
 * il verbale semplicemente non esiste ancora.
 *
 * Chi verbalizza vi accede sempre, perché deve poterlo redigere. Gli altri
 * solo quando esiste un verbale sigillato: la bozza documenta una seduta di
 * cui non si è ancora chiusa la redazione e non è un atto consultabile.
 */
final class AccessoVerbaleSeduta implements AccessInterface {

  public function __construct(private readonly Verbalizzazione $verbalizzazione) {}

  public function access(SedutaInterface $psiphos_seduta, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer psiphos')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $segretario = (int) ($psiphos_seduta->get('segretario')->target_id ?? 0);
    if ($segretario === (int) $account->id() && $account->hasPermission('psiphos verbalizzare')) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($psiphos_seduta);
    }

    $verbale = $this->verbalizzazione->esistente($psiphos_seduta);

    if ($verbale === NULL || !$verbale->sigillato()) {
      return AccessResult::forbidden()
        ->cachePerPermissions()
        ->addCacheableDependency($psiphos_seduta)
        ->addCacheableDependency($verbale);
    }

    // La consultazione segue l'accesso all'entità, che distingue fra chi
    // apparteneva a quell'organo e chi vede ogni verbale. Ripetere qui il
    // controllo del solo permesso aprirebbe la scheda a chi la pagina poi
    // negherebbe — o, peggio, non negherebbe affatto.
    return $verbale->access('view', $account, TRUE)
      ->addCacheableDependency($psiphos_seduta)
      ->addCacheableDependency($verbale);
  }

}
