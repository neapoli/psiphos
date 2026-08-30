<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Organi collegiali ammessi allo svolgimento a distanza deliberativo.
 *
 * L'elenco riproduce l'art. 44, comma 3, lettere a) e b), del CCNL comparto
 * Istruzione e Ricerca del 18/01/2024, richiamato per esteso dalla nota MIM
 * prot. 3803 del 30/06/2026. Il Consiglio d'istituto non compare perché
 * l'art. 44 disciplina le attività collegiali dei docenti: non è un'omissione.
 */
enum TipoOrgano: string {

  case COLLEGIO_DOCENTI = 'collegio_docenti';
  case CONSIGLIO_CLASSE = 'consiglio_classe';
  case CONSIGLIO_INTERCLASSE = 'consiglio_interclasse';
  case CONSIGLIO_INTERSEZIONE = 'consiglio_intersezione';
  case GRUPPO_LAVORO_INCLUSIONE = 'glo';

  public function etichetta(): string {
    return match ($this) {
      self::COLLEGIO_DOCENTI => (string) t('Collegio dei docenti'),
      self::CONSIGLIO_CLASSE => (string) t('Consiglio di classe'),
      self::CONSIGLIO_INTERCLASSE => (string) t('Consiglio di interclasse'),
      self::CONSIGLIO_INTERSEZIONE => (string) t('Consiglio di intersezione'),
      self::GRUPPO_LAVORO_INCLUSIONE => (string) t('Gruppo di lavoro operativo per l\'inclusione'),
    };
  }

  /**
   * Denominazione preceduta dall'articolo determinativo.
   *
   * Negli atti l'organo è il soggetto della frase — «Il Collegio dei docenti
   * approva» — e l'articolo va concordato con la denominazione, non appiccicato
   * davanti: «Il Gruppo di lavoro» ma «Il Consiglio». Sta qui perché è
   * proprietà dell'organo e non di chi compone la frase, che altrimenti
   * dovrebbe indovinarla.
   */
  public function etichettaConArticolo(): string {
    return match ($this) {
      self::COLLEGIO_DOCENTI => (string) t('Il Collegio dei docenti'),
      self::CONSIGLIO_CLASSE => (string) t('Il Consiglio di classe'),
      self::CONSIGLIO_INTERCLASSE => (string) t('Il Consiglio di interclasse'),
      self::CONSIGLIO_INTERSEZIONE => (string) t('Il Consiglio di intersezione'),
      self::GRUPPO_LAVORO_INCLUSIONE => (string) t('Il Gruppo di lavoro operativo per l\'inclusione'),
    };
  }

  /**
   * Lettera dell'art. 44, comma 3, sotto cui ricade l'organo.
   */
  public function letteraArt44(): string {
    return $this === self::COLLEGIO_DOCENTI ? 'a' : 'b';
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
