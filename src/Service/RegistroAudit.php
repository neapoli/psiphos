<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\psiphos\Enum\EventoAudit;

/**
 * Registro delle tracciature tecniche del procedimento deliberativo.
 *
 * Attua il §2 dell'allegato tecnico, che chiede di poter «ricostruire e
 * verificare ex post il corretto svolgimento del procedimento deliberativo»,
 * e il §5, che richiede monitoraggio e registrazione degli accessi e la
 * disponibilità di sistemi di audit.
 *
 * Le tracciature sono concatenate: ciascuna incorpora l'impronta della
 * precedente della stessa seduta. Qui la concatenazione è ammessa e anzi
 * necessaria, al contrario di quanto vale per l'urna: una tracciatura è per
 * definizione una cronologia, e conservarne l'ordine non rivela nulla sul
 * contenuto dei voti, che nel registro non compare mai.
 *
 * La catena è per seduta. Una catena unica renderebbe impossibile rimuovere
 * le tracciature di una seduta alla scadenza dei termini di conservazione
 * senza spezzare la verificabilità di tutte le altre.
 */
final class RegistroAudit {

  private const TABELLA = 'psiphos_audit';

  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $utenteCorrente,
    private readonly TimeInterface $orologio,
    private readonly ConfigFactoryInterface $configurazione,
  ) {}

  /**
   * Annota un evento nel registro.
   *
   * @param array<string, mixed> $contesto
   *   Circostanze dell'evento. Non deve mai contenere il contenuto di un
   *   voto né dati che consentano di ricavarlo.
   */
  public function annota(EventoAudit $evento, int $seduta = 0, int $delibera = 0, array $contesto = []): void {
    $momento = $this->orologio->getRequestTime();
    $utente = (int) $this->utenteCorrente->id();
    $contestoSerializzato = $contesto === []
      ? ''
      : (string) json_encode($contesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Il blocco in lettura serve a impedire che due annotazioni concorrenti
    // sulla stessa seduta si aggancino entrambe alla medesima precedente,
    // producendo una biforcazione che la verifica leggerebbe come rottura.
    $transazione = $this->database->startTransaction();

    try {
      $precedente = (string) ($this->database->select(self::TABELLA, 't')
        ->fields('t', ['impronta'])
        ->condition('seduta', $seduta)
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->forUpdate()
        ->execute()
        ->fetchField() ?: '');

      $this->database->insert(self::TABELLA)
        ->fields([
          'seduta' => $seduta,
          'delibera' => $delibera,
          'evento' => $evento->value,
          'utente' => $utente,
          'momento' => $momento,
          'contesto' => $contestoSerializzato,
          'precedente' => $precedente,
          'impronta' => $this->calcolaImpronta($precedente, $seduta, $delibera, $evento->value, $utente, $momento, $contestoSerializzato),
        ])
        ->execute();
    }
    catch (\Throwable $errore) {
      $transazione->rollBack();
      throw $errore;
    }
  }

  /**
   * Tracciature di una seduta, in ordine cronologico.
   *
   * @return array<int, array<string, mixed>>
   */
  public function tracciature(int $seduta): array {
    $righe = $this->database->select(self::TABELLA, 't')
      ->fields('t')
      ->condition('seduta', $seduta)
      ->orderBy('id')
      ->execute();

    $tracciature = [];
    foreach ($righe as $riga) {
      $evento = EventoAudit::tryFrom((string) $riga->evento);
      $tracciature[] = [
        'id' => (int) $riga->id,
        'momento' => (int) $riga->momento,
        'evento' => $riga->evento,
        'evento_denominazione' => $evento?->etichetta() ?? $riga->evento,
        'anomalia' => $evento?->anomalia() ?? FALSE,
        'utente' => (int) $riga->utente,
        'delibera' => (int) $riga->delibera,
        'contesto' => $riga->contesto === '' || $riga->contesto === NULL
          ? []
          : (array) json_decode((string) $riga->contesto, TRUE),
        'impronta' => (string) $riga->impronta,
      ];
    }

    return $tracciature;
  }

  /**
   * Verifica la catena delle tracciature di una seduta.
   *
   * @return array{integra: bool, tracciature: int, prima_rottura: ?int, motivo: string}
   */
  public function verificaCatena(int $seduta): array {
    $righe = $this->database->select(self::TABELLA, 't')
      ->fields('t')
      ->condition('seduta', $seduta)
      ->orderBy('id')
      ->execute()
      ->fetchAll();

    $atteso = '';
    $contate = 0;

    foreach ($righe as $riga) {
      $contate++;

      if ((string) $riga->precedente !== $atteso) {
        return [
          'integra' => FALSE,
          'tracciature' => $contate,
          'prima_rottura' => (int) $riga->id,
          'motivo' => (string) t('La tracciatura non si aggancia alla precedente: una o più annotazioni sono state rimosse o riordinate.'),
        ];
      }

      $ricalcolata = $this->calcolaImpronta(
        (string) $riga->precedente,
        (int) $riga->seduta,
        (int) $riga->delibera,
        (string) $riga->evento,
        (int) $riga->utente,
        (int) $riga->momento,
        (string) ($riga->contesto ?? '')
      );

      if (!hash_equals((string) $riga->impronta, $ricalcolata)) {
        return [
          'integra' => FALSE,
          'tracciature' => $contate,
          'prima_rottura' => (int) $riga->id,
          'motivo' => (string) t("Il contenuto della tracciatura non corrisponde alla sua impronta: l'annotazione è stata alterata."),
        ];
      }

      $atteso = (string) $riga->impronta;
    }

    return [
      'integra' => TRUE,
      'tracciature' => $contate,
      'prima_rottura' => NULL,
      'motivo' => '',
    ];
  }

  /**
   * Sedute di cui esistono tracciature.
   *
   * @return array<int, int>
   */
  public function seduteTracciate(): array {
    return array_map('intval', $this->database->select(self::TABELLA, 't')
      ->fields('t', ['seduta'])
      ->condition('seduta', 0, '>')
      ->distinct()
      ->orderBy('seduta', 'DESC')
      ->execute()
      ->fetchCol());
  }

  /**
   * Rimuove le tracciature delle sedute che hanno superato i termini.
   *
   * Il §6 impone la limitazione della conservazione; il §2 la verificabilità
   * ex post. La rimozione interviene perciò solo su sedute già verbalizzate,
   * dove il verbale sigillato resta come evidenza documentale, e lascia al
   * suo posto un'annotazione del troncamento: una catena che comincia dal
   * nulla è indistinguibile da una a cui è stato tolto l'inizio.
   *
   * @return int
   *   Numero di sedute ripulite.
   */
  public function applicaRitenzione(): int {
    $giorni = (int) $this->configurazione->get('psiphos.settings')->get('audit.ritenzione_giorni');

    if ($giorni <= 0) {
      return 0;
    }

    $soglia = $this->orologio->getRequestTime() - ($giorni * 86400);

    $sedute = $this->database->select('psiphos_seduta', 's')
      ->fields('s', ['id'])
      ->condition('stato', 'verbalizzata')
      ->condition('chiusa_il', $soglia, '<')
      ->execute()
      ->fetchCol();

    $ripulite = 0;

    foreach ($sedute as $seduta) {
      $presenti = (int) $this->database->select(self::TABELLA, 't')
        ->condition('seduta', $seduta)
        ->condition('evento', EventoAudit::TRACCIATURE_TRONCATE->value, '<>')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($presenti === 0) {
        continue;
      }

      $this->database->delete(self::TABELLA)->condition('seduta', $seduta)->execute();
      $this->annota(EventoAudit::TRACCIATURE_TRONCATE, (int) $seduta, 0, [
        'tracciature_rimosse' => $presenti,
        'giorni_di_conservazione' => $giorni,
      ]);
      $ripulite++;
    }

    return $ripulite;
  }

  /**
   * Impronta di una singola tracciatura.
   */
  private function calcolaImpronta(
    string $precedente,
    int $seduta,
    int $delibera,
    string $evento,
    int $utente,
    int $momento,
    string $contesto,
  ): string {
    return hash('sha256', implode("\n", [
      'psiphos-audit-v1',
      $precedente,
      (string) $seduta,
      (string) $delibera,
      $evento,
      (string) $utente,
      (string) $momento,
      $contesto,
    ]));
  }

}
