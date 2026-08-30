<?php

declare(strict_types=1);

namespace Drupal\psiphos\Plugin\EntityReferenceSelection;

use Drupal\user\Plugin\EntityReferenceSelection\UserSelection;

/**
 * Seleziona fra le sole utenze attive.
 *
 * Il selettore predefinito di Drupal mostra anche le utenze bloccate a chi ha
 * il permesso di amministrare gli utenti, ed è ragionevole per la gestione
 * dell'anagrafica. Non lo è per l'elenco degli aventi diritto: un'utenza
 * bloccata non può accedere, quindi non potrà mai entrare in aula, ma
 * gonfierebbe il denominatore dei quorum e renderebbe più difficile
 * raggiungere il numero legale.
 *
 * @EntityReferenceSelection(
 *   id = "psiphos_utente_attivo",
 *   label = @Translation("Utenze attive"),
 *   entity_types = {"user"},
 *   group = "psiphos_utente_attivo",
 *   weight = 0
 * )
 */
class UtenteAttivoSelection extends UserSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $query->condition('status', 1);

    return $query;
  }

}
