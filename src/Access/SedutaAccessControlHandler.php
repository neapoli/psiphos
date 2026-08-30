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
use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controllo di accesso per seduta, ordine del giorno, delibere e presenze.
 *
 * Attua il §3.3 dell'allegato tecnico, che chiede una chiara distinzione dei
 * ruoli e che ciascun utente acceda «esclusivamente alle funzionalità
 * strettamente necessarie». L'accesso non dipende quindi solo dal permesso
 * ma anche dal ruolo ricoperto nella specifica seduta: il permesso di
 * presiedere non consente di presiedere qualunque seduta, ma solo quella in
 * cui si è designati presidente.
 */
final class SedutaAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EntityTypeManagerInterface $gestoreEntita,
  ) {
    parent::__construct($entity_type);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static($entity_type, $container->get('entity_type.manager'));
  }

  /**
   * Chi può convocare, può creare la convocazione.
   *
   * Senza questo il permesso «convocare una seduta» non consentiva nulla: chi
   * lo aveva non poteva creare la seduta né vederne l'elenco, e per convocare
   * un Consiglio di classe bisognava dare a un coordinatore l'amministrazione
   * dell'intero modulo.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    $amministratore = parent::checkCreateAccess($account, $context, $entity_bundle);
    if ($amministratore->isAllowed()) {
      return $amministratore;
    }

    return AccessResult::allowedIfHasPermission($account, 'psiphos convocare seduta');
  }

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $seduta = $this->sedutaCollegata($entity);

    if ($seduta === NULL) {
      return parent::checkAccess($entity, $operation, $account)->addCacheableDependency($entity);
    }

    // I divieti che discendono da un atto già compiuto vengono prima di ogni
    // permesso, e valgono anche per l'amministratore: modificare una delibera
    // su cui si è votato non è un'operazione che qualcuno possa essere
    // autorizzato a compiere, è una falsificazione. È la stessa ragione per
    // cui il verbale sigillato rifiuta ogni scrittura a livello di entità.
    $divieto = $this->divietoAssoluto($entity, $operation, $seduta);
    if ($divieto !== NULL) {
      return $divieto->addCacheableDependency($entity)->addCacheableDependency($seduta);
    }

    $amministratore = parent::checkAccess($entity, $operation, $account);
    if ($amministratore->isAllowed()) {
      return $amministratore;
    }

    $esito = match ($operation) {
      'view' => $this->accessoInLettura($seduta, $account),
      'update' => $this->accessoInScrittura($seduta, $account),
      'delete' => $this->accessoInCancellazione($seduta, $account, $entity),
      default => AccessResult::neutral(),
    };

    return $esito->addCacheableDependency($seduta)->addCacheableDependency($entity);
  }

  /**
   * Divieti che nessun permesso può superare.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   Il divieto, oppure NULL se l'operazione non ne incontra alcuno.
   */
  private function divietoAssoluto(EntityInterface $entity, string $operation, SedutaInterface $seduta): ?AccessResultInterface {
    if (!in_array($operation, ['update', 'delete'], TRUE)) {
      return NULL;
    }

    if ($entity instanceof SedutaInterface) {
      if ($seduta->stato()->definitivo()) {
        return AccessResult::forbidden('La seduta è in uno stato definitivo.');
      }

      if ($operation === 'delete' && $seduta->stato() !== StatoSeduta::CONVOCATA) {
        return AccessResult::forbidden('Una seduta aperta non può essere cancellata: va annullata.');
      }

      return NULL;
    }

    if ($seduta->stato()->definitivo()) {
      return AccessResult::forbidden('La seduta è in uno stato definitivo.');
    }

    if ($entity instanceof Delibera && $entity->stato() !== StatoDelibera::PREDISPOSTA) {
      return AccessResult::forbidden('La votazione è già stata aperta: la delibera resta agli atti.');
    }

    if ($entity instanceof PuntoOdg && $this->haVotazioniAvviate($entity)) {
      return AccessResult::forbidden('Sul punto si è già votato: resta agli atti.');
    }

    return NULL;
  }

  private function accessoInLettura(SedutaInterface $seduta, AccountInterface $account): AccessResultInterface {
    // Dirigente e segreteria vedono ogni organo: rispondono degli atti e ne
    // curano l'accesso. È un permesso ristretto, non il permesso di base del
    // personale docente.
    if ($account->hasPermission('psiphos visualizzare ogni verbale')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Tutti gli altri vedono le sedute a cui sono iscritti come aventi
    // diritto, e nessun'altra. L'elenco degli aventi diritto traccia da sé il
    // confine giusto: nel Collegio ci sono tutti i docenti, e il verbale resta
    // visibile anche a chi era assente, perché l'elenco è dei componenti e non
    // dei presenti; nel Consiglio di una classe ci sono i suoi soli docenti;
    // nel gruppo di lavoro operativo di un alunno i soli membri di quel
    // gruppo. Quest'ultimo è il caso che conta: quel verbale riferisce della
    // disabilità di un minore identificato, e un docente di un'altra classe
    // non ha titolo per leggerlo.
    $suo = $this->iscrittoAllElenco($seduta, $account);
    if ($suo && ($account->hasPermission('psiphos visualizzare verbali')
      || $account->hasPermission('psiphos partecipare seduta'))) {
      return AccessResult::allowed()->cachePerUser();
    }

    // Gli stessi titoli che governano la scrivania e l'archivio personale:
    // chi presiede, chi verbalizza e chi ha convocato. Senza, un presidente
    // che non componga l'organo potrebbe modificare la seduta ma non aprirne
    // la scheda, e chi ha convocato la perderebbe di vista il giorno dopo.
    $mio = (int) $account->id();
    foreach (['presidente', 'segretario', 'uid'] as $campo) {
      if ((int) ($seduta->get($campo)->target_id ?? 0) === $mio) {
        return AccessResult::allowed()->cachePerUser();
      }
    }

    return AccessResult::neutral()->cachePerPermissions();
  }

  private function accessoInScrittura(SedutaInterface $seduta, AccountInterface $account): AccessResultInterface {

    $ruoli = [
      'presidente' => 'psiphos presiedere seduta',
      'segretario' => 'psiphos verbalizzare',
    ];
    foreach ($ruoli as $campo => $permesso) {
      $designato = (int) ($seduta->get($campo)->target_id ?? 0);
      if ($designato === (int) $account->id() && $account->hasPermission($permesso)) {
        return AccessResult::allowed()->cachePerUser();
      }
    }

    // Prima dell'apertura la seduta è ancora un atto di convocazione.
    if ($seduta->stato() === StatoSeduta::CONVOCATA && $account->hasPermission('psiphos convocare seduta')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::neutral()->cachePerPermissions();
  }

  private function accessoInCancellazione(SedutaInterface $seduta, AccountInterface $account, EntityInterface $entity): AccessResultInterface {
    // La cancellazione di una seduta è riservata all'amministratore, perché
    // rimuove una convocazione già diramata. Punti e delibere non ancora
    // votati sono invece errori di redazione, e li corregge chi redige.
    if ($entity instanceof SedutaInterface) {
      return AccessResult::allowedIfHasPermission($account, 'administer psiphos');
    }

    return $this->accessoInScrittura($seduta, $account);
  }

  /**
   * Vero se su un punto è già stata aperta almeno una votazione.
   */
  private function haVotazioniAvviate(PuntoOdg $punto): bool {
    if ($punto->isNew()) {
      return FALSE;
    }

    $trovate = $this->gestoreEntita->getStorage('psiphos_delibera')->getQuery()
      ->accessCheck(FALSE)
      ->condition('punto_odg', $punto->id())
      ->exists('aperta_il')
      ->range(0, 1)
      ->execute();

    return $trovate !== [];
  }

  /**
   * Risale alla seduta a cui l'entità appartiene.
   */
  private function sedutaCollegata(EntityInterface $entity): ?SedutaInterface {
    if ($entity instanceof SedutaInterface) {
      return $entity;
    }
    if ($entity instanceof PuntoOdg) {
      return $entity->seduta();
    }
    if ($entity instanceof Delibera) {
      return $entity->seduta();
    }
    if ($entity instanceof Presenza) {
      $seduta = $entity->get('seduta')->entity;
      return $seduta instanceof SedutaInterface ? $seduta : NULL;
    }
    return NULL;
  }

  /**
   * Vero se l'utente figura nell'elenco degli aventi diritto della seduta.
   */
  private function iscrittoAllElenco(SedutaInterface $seduta, AccountInterface $account): bool {
    if ($seduta->isNew()) {
      return FALSE;
    }

    $trovati = $this->gestoreEntita
      ->getStorage('psiphos_presenza')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('utente', $account->id())
      ->range(0, 1)
      ->execute();

    return $trovati !== [];
  }

}
