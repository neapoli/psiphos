<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Stati delle operazioni di voto su una singola delibera.
 *
 * Il §8 dell'allegato tecnico impone di prevedere procedure per la gestione
 * dei malfunzionamenti, «inclusa la sospensione delle operazioni di voto e,
 * se necessario, la loro ripetizione». Sospensione e annullamento sono qui
 * stati della singola votazione e non della seduta: il §8 parla di ripetere
 * le operazioni di voto, non i lavori dell'organo collegiale.
 *
 * La ripetizione non riapre una votazione annullata: se ne apre una nuova,
 * che conserva il riferimento a quella annullata. Nessun esito già registrato
 * viene mai riscritto (§2, integrità e immodificabilità dei risultati).
 */
enum StatoDelibera: string {

  case PREDISPOSTA = 'predisposta';
  case IN_VOTAZIONE = 'in_votazione';
  case SOSPESA = 'sospesa';
  case CHIUSA = 'chiusa';
  case ANNULLATA = 'annullata';

  public function etichetta(): string {
    return match ($this) {
      self::PREDISPOSTA => (string) t('Predisposta, non ancora posta ai voti'),
      self::IN_VOTAZIONE => (string) t('Votazione in corso'),
      self::SOSPESA => (string) t('Votazione sospesa'),
      self::CHIUSA => (string) t('Votazione chiusa'),
      self::ANNULLATA => (string) t('Votazione annullata'),
    };
  }

  /**
   * @return array<int, self>
   */
  public function transizioniAmmesse(): array {
    return match ($this) {
      self::PREDISPOSTA => [self::IN_VOTAZIONE, self::ANNULLATA],
      self::IN_VOTAZIONE => [self::SOSPESA, self::CHIUSA, self::ANNULLATA],
      self::SOSPESA => [self::IN_VOTAZIONE, self::ANNULLATA],
      // Una votazione chiusa può essere annullata solo se emerge a posteriori
      // un malfunzionamento: l'esito resta agli atti, la delibera decade e
      // la votazione va ripetuta con una nuova delibera.
      self::CHIUSA => [self::ANNULLATA],
      self::ANNULLATA => [],
    };
  }

  public function ammetteTransizioneA(self $destinazione): bool {
    return in_array($destinazione, $this->transizioniAmmesse(), TRUE);
  }

  /**
   * Vero se la transizione richiede una motivazione scritta.
   *
   * Sospensione e annullamento sono gli eventi che il §8 vuole tracciati:
   * senza motivazione la ricostruzione ex post del procedimento è monca.
   */
  public static function richiedeMotivazione(self $destinazione): bool {
    return in_array($destinazione, [self::SOSPESA, self::ANNULLATA], TRUE);
  }

  /**
   * Vero se in questo stato l'urna accetta schede.
   */
  public function urnaAperta(): bool {
    return $this === self::IN_VOTAZIONE;
  }

  /**
   * Vero se lo stato è definitivo.
   */
  public function definitivo(): bool {
    return $this === self::ANNULLATA;
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
