<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\psiphos\Enum\QuorumCostitutivo;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;

/**
 * Contratto di una seduta collegiale.
 */
interface SedutaInterface extends ContentEntityInterface, EntityChangedInterface {

  public function organo(): TipoOrgano;

  public function stato(): StatoSeduta;

  public function quorumCostitutivo(): QuorumCostitutivo;

  /**
   * Porta la seduta a un nuovo stato, verificando che sia ammesso.
   *
   * @throws \Drupal\psiphos\Exception\TransizioneNonAmmessaException
   */
  public function transitaA(StatoSeduta $destinazione): static;

  /**
   * Numero di aventi diritto iscritti all'elenco della seduta.
   */
  public function numeroAventiDiritto(): int;

  /**
   * Numero di presenti che concorrono al quorum in questo momento.
   */
  public function numeroPresenti(): int;

  /**
   * Aventi diritto cristallizzati all'apertura della seduta.
   *
   * È il denominatore usato per i quorum: va congelato all'apertura perché
   * l'elenco resti verificabile ex post anche se muta in seguito (§4.1).
   */
  public function aventiDirittoAllApertura(): ?int;

  /**
   * Vero se la seduta risulta validamente costituita.
   */
  public function validamenteCostituita(): bool;

}
