<?php

declare(strict_types=1);

namespace Drupal\psiphos\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\psiphos\Enum\EsitoDelibera;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\TipoVoto;

/**
 * Contratto di una singola votazione.
 */
interface DeliberaInterface extends ContentEntityInterface, EntityChangedInterface {

  public function stato(): StatoDelibera;

  public function tipoVoto(): TipoVoto;

  public function regolaMaggioranza(): RegolaMaggioranza;

  public function esito(): ?EsitoDelibera;

  public function schemaScheda(): SchemaScheda;

  /**
   * Opzioni personalizzate della scheda, nell'ordine di presentazione.
   *
   * @return array<int, string>
   */
  public function opzioni(): array;

  /**
   * Voci stampate sulla scheda: chiave tecnica mappata sul testo mostrato.
   *
   * @return array<string, string>
   */
  public function vociScheda(): array;

  /**
   * Numero massimo di preferenze esprimibili su una singola scheda.
   */
  public function preferenzeMassime(): int;

  /**
   * Conteggio dello scrutinio: chiave di voce mappata sul numero di voti.
   *
   * @return array<string, int>
   */
  public function conteggio(): array;

  /**
   * Verifica la coerenza della scheda.
   *
   * @throws \InvalidArgumentException
   */
  public function validaScheda(): void;

  public function seduta(): ?SedutaInterface;

  /**
   * Porta la votazione a un nuovo stato, verificando che sia ammesso.
   *
   * @param string|null $motivazione
   *   Obbligatoria per sospensione e annullamento (§8 dell'allegato tecnico).
   *
   * @throws \Drupal\psiphos\Exception\TransizioneNonAmmessaException
   * @throws \InvalidArgumentException
   */
  public function transitaA(StatoDelibera $destinazione, ?string $motivazione = NULL): static;

  /**
   * Vero se l'urna accetta schede in questo momento.
   */
  public function urnaAperta(): bool;

  /**
   * Oggetto dell'atto, ossia il titolo con cui la delibera circola da sola.
   */
  public function oggettoAtto(): string;

  /**
   * Premesse dell'atto: i «visto», i «tenuto conto», i «considerato».
   */
  public function premesse(): string;

  /**
   * Vero se la delibera è un atto da formalizzare in un proprio documento.
   */
  public function daFormalizzare(): bool;

  /**
   * Che cosa manca perché l'atto possa essere sigillato.
   *
   * @return array<int, string>
   *   Elenco vuoto quando l'atto è completo.
   */
  public function lacuneAtto(): array;

  /**
   * Le lacune dell'atto in forma di frase, vuota se non ve ne sono.
   */
  public function descrizioneLacune(): string;

  /**
   * Vero se l'atto è già stato sigillato e non è più redigibile.
   */
  public function attoSigillato(): bool;

  /**
   * Delibera annullata di cui questa costituisce ripetizione, se esiste.
   */
  public function ripetizioneDi(): ?DeliberaInterface;

}
