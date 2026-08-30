<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Stati del procedimento di una seduta collegiale.
 *
 * La progressione è monotona: una seduta non torna mai a uno stato
 * precedente. L'immodificabilità del percorso è il presupposto della
 * verificabilità ex post richiesta dal §2 dell'allegato tecnico.
 */
enum StatoSeduta: string {

  case CONVOCATA = 'convocata';
  case APERTA = 'aperta';
  case CHIUSA = 'chiusa';
  case VERBALIZZATA = 'verbalizzata';
  case ANNULLATA = 'annullata';

  public function etichetta(): string {
    return match ($this) {
      self::CONVOCATA => (string) t('Convocata'),
      self::APERTA => (string) t('In corso'),
      self::CHIUSA => (string) t('Chiusa, verbale da redigere'),
      self::VERBALIZZATA => (string) t('Verbalizzata'),
      self::ANNULLATA => (string) t('Annullata'),
    };
  }

  /**
   * Stati raggiungibili da questo stato.
   *
   * @return array<int, self>
   */
  public function transizioniAmmesse(): array {
    return match ($this) {
      self::CONVOCATA => [self::APERTA, self::ANNULLATA],
      self::APERTA => [self::CHIUSA],
      self::CHIUSA => [self::VERBALIZZATA],
      self::VERBALIZZATA, self::ANNULLATA => [],
    };
  }

  public function ammetteTransizioneA(self $destinazione): bool {
    return in_array($destinazione, $this->transizioniAmmesse(), TRUE);
  }

  /**
   * Vero se lo stato è definitivo e la seduta non è più modificabile.
   */
  public function definitivo(): bool {
    return $this->transizioniAmmesse() === [];
  }

  /**
   * Vero se in questo stato è possibile registrare presenze e votare.
   */
  public function consenteOperazioniDiVoto(): bool {
    return $this === self::APERTA;
  }

  /**
   * Valori ammessi per il campo, nel formato atteso da Drupal.
   *
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
