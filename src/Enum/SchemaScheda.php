<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Struttura della scheda posta ai voti.
 *
 * Va scelta alla predisposizione della delibera e non è più modificabile una
 * volta aperta l'urna: il §4.1 chiede che l'esito sia «determinato in modo
 * corretto e verificabile», e una scheda che cambia forma a votazione
 * iniziata rende il conteggio inconfrontabile con quanto messo ai voti.
 *
 * La scheda bianca è sempre disponibile nelle schede a scelta: è
 * l'equivalente dell'astensione e va tenuta distinta dalla mancata
 * partecipazione al voto, che invece non produce alcuna scheda.
 */
enum SchemaScheda: string {

  case APPROVAZIONE = 'approvazione';
  case SCELTA_SINGOLA = 'scelta_singola';
  case SCELTA_MULTIPLA = 'scelta_multipla';

  public const VOCE_FAVOREVOLE = 'favorevole';
  public const VOCE_CONTRARIO = 'contrario';
  public const VOCE_ASTENUTO = 'astenuto';
  public const VOCE_SCHEDA_BIANCA = 'scheda_bianca';

  public function etichetta(): string {
    return match ($this) {
      self::APPROVAZIONE => (string) t('Approvazione: favorevole, contrario, astenuto'),
      self::SCELTA_SINGOLA => (string) t('Scelta fra opzioni: una sola preferenza'),
      self::SCELTA_MULTIPLA => (string) t('Scelta fra opzioni: più preferenze'),
    };
  }

  public function descrizione(): string {
    return match ($this) {
      self::APPROVAZIONE => (string) t('Scheda standard per l\'approvazione di una proposta. Le voci sono fisse e non richiedono configurazione.'),
      self::SCELTA_SINGOLA => (string) t('Scheda con opzioni definite dal proponente, ad esempio per designazioni o elezioni. Ogni votante esprime una preferenza o una scheda bianca.'),
      self::SCELTA_MULTIPLA => (string) t('Come la precedente, ma ogni votante può esprimere fino a un numero massimo di preferenze stabilito in convocazione.'),
    };
  }

  /**
   * Vero se la scheda richiede opzioni definite dal proponente.
   */
  public function richiedeOpzioni(): bool {
    return $this !== self::APPROVAZIONE;
  }

  /**
   * Voci effettivamente stampate sulla scheda.
   *
   * @param array<int, string> $opzioni
   *   Opzioni personalizzate, nell'ordine di presentazione.
   *
   * @return array<string, string>
   *   Chiave tecnica, immutabile e conservata nell'urna, mappata sul testo
   *   mostrato al votante.
   */
  public function voci(array $opzioni = []): array {
    if ($this === self::APPROVAZIONE) {
      return [
        self::VOCE_FAVOREVOLE => (string) t('Favorevole'),
        self::VOCE_CONTRARIO => (string) t('Contrario'),
        self::VOCE_ASTENUTO => (string) t('Astenuto'),
      ];
    }

    $voci = [];
    foreach (array_values($opzioni) as $posizione => $testo) {
      $voci[self::chiaveOpzione($posizione)] = $testo;
    }
    $voci[self::VOCE_SCHEDA_BIANCA] = (string) t('Scheda bianca');

    return $voci;
  }

  /**
   * Chiave tecnica di un'opzione data la sua posizione sulla scheda.
   *
   * L'urna conserva questa chiave e non il testo dell'opzione: è breve, non
   * rivela nulla di per sé e resta stabile perché le opzioni sono bloccate
   * dall'apertura della votazione in poi.
   */
  public static function chiaveOpzione(int $posizione): string {
    return 'opzione_' . ($posizione + 1);
  }

  /**
   * Voci che esprimono un voto valido a favore di qualcosa.
   *
   * Escluse astensione e scheda bianca, che sono partecipazione al voto ma
   * non preferenza.
   *
   * @return array<int, string>
   */
  public function vociDiPreferenza(array $opzioni = []): array {
    $voci = array_keys($this->voci($opzioni));
    return array_values(array_diff($voci, [self::VOCE_ASTENUTO, self::VOCE_CONTRARIO, self::VOCE_SCHEDA_BIANCA]));
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
