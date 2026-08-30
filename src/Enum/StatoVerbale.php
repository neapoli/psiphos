<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Stato di redazione del verbale.
 *
 * Il §7 dell'allegato tecnico chiede documenti «immodificabili, completi e
 * associati ai metadati necessari a garantirne autenticità, integrità e
 * contestualizzazione». La bozza esiste perché la redazione è un'attività
 * umana che richiede tempo; il sigillo è il momento in cui il documento
 * smette di essere modificabile e diventa un atto.
 */
enum StatoVerbale: string {

  case BOZZA = 'bozza';
  case SIGILLATO = 'sigillato';

  public function etichetta(): string {
    return match ($this) {
      self::BOZZA => (string) t('Bozza in redazione'),
      self::SIGILLATO => (string) t('Sigillato'),
    };
  }

  public function modificabile(): bool {
    return $this === self::BOZZA;
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
