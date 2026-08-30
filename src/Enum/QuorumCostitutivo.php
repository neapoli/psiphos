<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Regola di validità della costituzione della seduta.
 *
 * Distinto dalla maggioranza deliberativa: il quorum costitutivo dice se la
 * seduta è validamente insediata, la maggioranza dice se il singolo punto è
 * approvato. Il §4.1 richiede il rispetto «dei quorum deliberativi e delle
 * regole procedurali previste»; senza il costitutivo, il deliberativo è
 * calcolato su una seduta che potrebbe non essersi mai validamente aperta.
 */
enum QuorumCostitutivo: string {

  case META_PIU_UNO = 'meta_piu_uno';
  case NESSUNO = 'nessuno';

  public function etichetta(): string {
    return match ($this) {
      self::META_PIU_UNO => (string) t('Metà più uno degli aventi diritto'),
      self::NESSUNO => (string) t('Nessun quorum costitutivo'),
    };
  }

  /**
   * Numero minimo di presenti perché la seduta sia validamente costituita.
   */
  public function minimoPresenti(int $aventiDiritto): int {
    return match ($this) {
      self::META_PIU_UNO => intdiv($aventiDiritto, 2) + 1,
      self::NESSUNO => 0,
    };
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
