<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Core\Session\AccountInterface;

/**
 * Come si scrive il nome di una persona negli atti della seduta.
 *
 * Il nome utente è un identificativo tecnico e non ha posto in un verbale:
 * un atto amministrativo nomina le persone con cognome e nome. La forma
 * «Cognome Nome» è quella degli elenchi scolastici, e ha il vantaggio di
 * ordinare correttamente il registro delle presenze, che è alfabetico.
 *
 * Se i campi anagrafici non sono compilati si ripiega sul nome visualizzato:
 * meglio un identificativo tecnico che una riga vuota in un verbale.
 */
final class Nominativo {

  /**
   * Nominativo di un utente, nella forma usata negli atti.
   */
  public static function perUtente(mixed $utente): string {
    if (!$utente instanceof AccountInterface) {
      return (string) t('utente non disponibile');
    }

    $parti = [];

    foreach (['field_cognome', 'field_nome'] as $campo) {
      $valore = self::campo($utente, $campo);

      if ($valore !== '') {
        $parti[] = $valore;
      }
    }

    return $parti === [] ? (string) $utente->getDisplayName() : implode(' ', $parti);
  }

  /**
   * Valore di un campo anagrafico, se presente e compilato.
   */
  private static function campo(AccountInterface $utente, string $campo): string {
    if (!$utente instanceof \Drupal\Core\Entity\FieldableEntityInterface || !$utente->hasField($campo)) {
      return '';
    }

    return trim((string) $utente->get($campo)->value);
  }

}
