<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Intestazione dell'istituto da apporre agli atti.
 *
 * I dati stanno dove la scuola li ha già inseriti una volta sola: il luogo
 * marcato come sede legale nella distribuzione Ouitoulía. Duplicarli in una
 * configurazione del modulo significherebbe averne due copie che divergono, e
 * la prima a cambiare — un numero di telefono, una PEC — resterebbe sbagliata
 * proprio sugli atti che circolano fuori dalla scuola.
 *
 * Il modulo non dipende però da quella struttura: se il tipo di contenuto non
 * esiste, se nessun luogo è marcato sede legale o se i campi sono vuoti,
 * l'intestazione si riduce a quel che c'è, fino al solo nome del sito. Un
 * atto con l'intestazione incompleta è un atto perfettibile; un atto che non
 * si riesce a produrre è un problema.
 */
final class IntestazioneIstituto {

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly EntityFieldManagerInterface $gestoreCampi,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly RequestStack $richieste,
  ) {}

  /**
   * Dati dell'istituto, nella forma in cui l'atto li riporta.
   *
   * Restituisce campi e non righe già composte: la composizione è resa, e
   * cambiarla non deve costringere a ricalcolare l'impronta di atti già
   * sigillati.
   *
   * @return array<string, string>
   */
  public function dati(): array {
    $sede = $this->sedeLegale();

    $intestazione = [
      // La denominazione viene dal nome del sito e non dal titolo del luogo:
      // il luogo è l'edificio — «sede centrale», «plesso Marconi» — e solo per
      // coincidenza in qualche installazione porta il nome dell'istituto. Il
      // nome del sito è invece quello con cui la scuola si presenta ovunque.
      'istituto' => (string) $this->configurazione->get('system.site')->get('name'),
      'indirizzo' => '',
      'cap' => '',
      'comune' => '',
      'provincia' => '',
      'telefono' => $this->valore($sede, 'field_telefono'),
      'codice_fiscale' => $this->valore($sede, 'field_codice_fiscale'),
      'codice_meccanografico' => $this->valore($sede, 'field_codice_meccanografico'),
      'email' => $this->valore($sede, 'field_email'),
      'pec' => $this->valore($sede, 'field_pec'),
      'sito' => $this->sito(),
    ];

    if ($sede !== NULL && $sede->hasField('field_indirizzo')) {
      $indirizzo = $sede->get('field_indirizzo')->first()?->getValue() ?? [];
      $intestazione['indirizzo'] = trim((string) ($indirizzo['address_line1'] ?? ''));
      $intestazione['cap'] = trim((string) ($indirizzo['postal_code'] ?? ''));
      $intestazione['comune'] = trim((string) ($indirizzo['locality'] ?? ''));
      $intestazione['provincia'] = trim((string) ($indirizzo['administrative_area'] ?? ''));
    }


    return $intestazione;
  }

  /**
   * Il luogo marcato come sede legale, se ve n'è uno.
   */
  private function sedeLegale(): mixed {
    try {
      $archivio = $this->gestoreEntita->getStorage('node');
    }
    catch (\Throwable) {
      // Nessun tipo di contenuto: il modulo gira anche fuori da Ouitoulía.
      return NULL;
    }

    $definizioni = $this->gestoreCampi->getFieldDefinitions('node', 'luogo');

    if (!isset($definizioni['field_sede_legale'])) {
      return NULL;
    }

    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'luogo')
      ->condition('field_sede_legale', 1)
      ->condition('status', 1)
      // Più sedi marcate sono un errore di redazione, non un caso da
      // gestire: si prende la prima e non si fallisce.
      ->sort('nid')
      ->range(0, 1)
      ->execute();

    if ($identificativi === []) {
      return NULL;
    }

    return $archivio->load(reset($identificativi));
  }

  /**
   * Indirizzo del sito, dal quale la scuola è raggiungibile.
   *
   * Drupal non conserva un indirizzo del sito in configurazione: si ricava
   * dalla richiesta in corso. Congelato nell'atto al momento del sigillo,
   * resta quello con cui la scuola si presentava quel giorno.
   */
  private function sito(): string {
    $richiesta = $this->richieste->getCurrentRequest();

    return $richiesta === NULL ? '' : $richiesta->getHost();
  }

  private function valore(mixed $nodo, string $campo): string {
    if ($nodo === NULL || !$nodo->hasField($campo)) {
      return '';
    }

    return trim((string) $nodo->get($campo)->value);
  }

}
