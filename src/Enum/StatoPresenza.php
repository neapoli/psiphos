<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Stato di un avente diritto rispetto a una seduta.
 *
 * DECADUTA attua il §3.4 dell'allegato tecnico: l'interruzione automatica
 * per inattività non è solo una misura di sicurezza della sessione, incide
 * sul computo del quorum. Una sessione abbandonata che continuasse a
 * contare come presente produrrebbe deliberazioni su un quorum fittizio.
 */
enum StatoPresenza: string {

  case ATTESO = 'atteso';
  case PRESENTE = 'presente';
  case USCITO = 'uscito';
  case DECADUTA = 'decaduta';
  case ASSENTE = 'assente';

  public function etichetta(): string {
    return match ($this) {
      self::ATTESO => (string) t('Non ancora entrato'),
      self::PRESENTE => (string) t('Presente'),
      self::USCITO => (string) t('Uscito dalla seduta'),
      self::DECADUTA => (string) t('Presenza decaduta per inattività'),
      self::ASSENTE => (string) t('Assente'),
    };
  }

  /**
   * Vero se l'avente diritto concorre al quorum e può esprimere il voto.
   */
  public function concorreAlQuorum(): bool {
    return $this === self::PRESENTE;
  }

  /**
   * @return array<string, string>
   */
  public static function opzioni(): array {
    $opzioni = [];
    foreach (self::cases() as $caso) {
      $opzioni[$caso->value] = $caso->etichetta();
    }
    return $opzioni;
  }

}
