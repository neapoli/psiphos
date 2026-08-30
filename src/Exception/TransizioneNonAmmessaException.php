<?php

declare(strict_types=1);

namespace Drupal\psiphos\Exception;

/**
 * Sollevata quando si tenta un passaggio di stato non previsto.
 *
 * È volutamente un'eccezione e non una violazione di validazione: un
 * passaggio di stato illegittimo su una seduta o su una votazione non è un
 * errore di compilazione da mostrare all'utente, è un tentativo di alterare
 * il procedimento deliberativo e va fermato prima della scrittura.
 */
final class TransizioneNonAmmessaException extends \RuntimeException {

  public static function per(string $entita, string $da, string $a, array $ammessi): self {
    return new self(sprintf(
      'Transizione non ammessa su %s: da "%s" a "%s". Transizioni possibili: %s.',
      $entita,
      $da,
      $a,
      $ammessi ? implode(', ', $ammessi) : 'nessuna, lo stato è definitivo'
    ));
  }

}
