<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Modalità di espressione del voto.
 *
 * La distinzione non è cosmetica: il §4.2 impone per il voto palese la piena
 * tracciabilità e l'associazione fra votante e scelta espressa, mentre il
 * §4.3 impone per il voto segreto l'esatto contrario, cioè una separazione
 * strutturale non reversibile fra le due. I due casi usano perciò archivi
 * distinti, non lo stesso archivio con un interruttore.
 */
enum TipoVoto: string {

  case PALESE = 'palese';
  case SEGRETO = 'segreto';

  public function etichetta(): string {
    return match ($this) {
      self::PALESE => (string) t('Voto palese'),
      self::SEGRETO => (string) t('Voto a scrutinio segreto'),
    };
  }

  /**
   * Vero se la scelta espressa va conservata insieme all'identità del votante.
   */
  public function tracciaIdentita(): bool {
    return $this === self::PALESE;
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
