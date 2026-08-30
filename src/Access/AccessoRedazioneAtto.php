<?php

declare(strict_types=1);

namespace Drupal\psiphos\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\psiphos\Entity\DeliberaInterface;

/**
 * Accesso alla redazione dell'atto di una delibera.
 *
 * La delibera è congelata dall'apertura dell'urna in poi, e giustamente: il
 * controllo di accesso generale nega ogni scrittura su una votazione già
 * svolta. La redazione dell'atto è però l'unica scrittura che deve restare
 * possibile dopo il voto, perché è proprio quel che si fa a seduta conclusa.
 *
 * La finestra in cui è consentita è delimitata da due atti: si apre alla
 * chiusura della votazione e si chiude al sigillo, che è il momento in cui
 * l'estratto diventa documento. Da lì in avanti nulla è più modificabile,
 * per nessuno.
 */
final class AccessoRedazioneAtto implements AccessInterface {

  public function access(DeliberaInterface $psiphos_delibera, AccountInterface $account): AccessResultInterface {
    $esito = $this->valuta($psiphos_delibera, $account);

    return $esito->addCacheableDependency($psiphos_delibera)->cachePerUser();
  }

  private function valuta(DeliberaInterface $delibera, AccountInterface $account): AccessResultInterface {
    // I divieti che discendono da un atto già compiuto vengono prima di ogni
    // permesso e valgono anche per l'amministratore.
    if ($delibera->attoSigillato()) {
      return AccessResult::forbidden('L\'atto è sigillato e non è più redigibile.');
    }

    if (!$delibera->daFormalizzare()) {
      return AccessResult::forbidden('La votazione non si è conclusa con una deliberazione da formalizzare.');
    }

    if ($account->hasPermission('administer psiphos')) {
      return AccessResult::allowed();
    }

    $seduta = $delibera->seduta();
    if ($seduta === NULL) {
      return AccessResult::forbidden('La delibera non è collegata ad alcuna seduta.');
    }

    // Redige il segretario, sottoscrive il presidente: entrambi devono poter
    // intervenire sul testo dell'atto prima che sia sigillato.
    $incarichi = [
      'segretario' => 'psiphos verbalizzare',
      'presidente' => 'psiphos presiedere seduta',
    ];

    foreach ($incarichi as $campo => $permesso) {
      $designato = (int) ($seduta->get($campo)->target_id ?? 0);
      if ($designato === (int) $account->id() && $account->hasPermission($permesso)) {
        return AccessResult::allowed();
      }
    }

    return AccessResult::forbidden('La redazione dell\'atto spetta al segretario verbalizzante o al presidente della seduta.');
  }

}
