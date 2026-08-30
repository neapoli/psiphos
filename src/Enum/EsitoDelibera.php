<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Esito dello scrutinio di una delibera.
 *
 * Registrato alla chiusura dell'urna e da quel momento immodificabile
 * (§2, integrità e immodificabilità dei risultati delle votazioni).
 */
enum EsitoDelibera: string {

  case APPROVATA = 'approvata';
  case RESPINTA = 'respinta';
  case QUORUM_NON_RAGGIUNTO = 'quorum_non_raggiunto';
  case ANNULLATA = 'annullata';

  public function etichetta(): string {
    return match ($this) {
      self::APPROVATA => (string) t('Approvata'),
      self::RESPINTA => (string) t('Respinta'),
      self::QUORUM_NON_RAGGIUNTO => (string) t('Quorum non raggiunto'),
      self::ANNULLATA => (string) t('Votazione annullata'),
    };
  }

  /**
   * Etichetta adattata alla struttura della scheda votata.
   *
   * Gli stessi esiti si leggono diversamente a seconda del quesito: su una
   * scheda di approvazione «respinta» significa che la proposta non passa,
   * su una scheda a scelta significa che nessuna opzione ha raggiunto la
   * maggioranza richiesta e occorre un secondo turno.
   */
  public function etichettaPer(SchemaScheda $schema): string {
    if (!$schema->richiedeOpzioni()) {
      return $this->etichetta();
    }

    return match ($this) {
      self::APPROVATA => (string) t('Scelta proclamata'),
      self::RESPINTA => (string) t('Nessuna opzione ha raggiunto la maggioranza richiesta'),
      self::QUORUM_NON_RAGGIUNTO, self::ANNULLATA => $this->etichetta(),
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
