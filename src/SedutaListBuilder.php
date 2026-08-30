<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;

/**
 * Elenco amministrativo delle sedute collegiali.
 */
final class SedutaListBuilder extends EntityListBuilder {

  /**
   * @var array<string, array<string, string>>|null
   */
  private ?array $opzioniMemorizzate = NULL;

  public function buildHeader(): array {
    return [
      'numero' => $this->t('N.'),
      'titolo' => $this->t('Oggetto'),
      'organo' => $this->t('Organo'),
      'data_seduta' => $this->t('Data'),
      'stato' => $this->t('Stato'),
      'quorum' => $this->t('Presenti / aventi diritto'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof SedutaInterface);

    $data = $entity->get('data_seduta')->value;
    $aventiDiritto = $entity->aventiDirittoAllApertura() ?? $entity->numeroAventiDiritto();

    return [
      'numero' => $entity->get('numero')->value ?? '—',
      'titolo' => $entity->toLink()->toString(),
      'organo' => $entity->organo()->etichetta(),
      'data_seduta' => $data ? \Drupal::service('date.formatter')->format((int) $data, 'short') : '—',
      'stato' => $entity->stato()->etichetta(),
      'quorum' => $this->t('@presenti / @aventi@nota', [
        '@presenti' => $entity->numeroPresenti(),
        '@aventi' => $aventiDiritto,
        '@nota' => $entity->validamenteCostituita() ? '' : ' ⚠',
      ]),
    ] + parent::buildRow($entity);
  }

  public function render(): array {
    $costruzione = parent::render();

    // Cinque anni di sedute non si scorrono a pagine: si cercano. La barra è
    // la stessa dell'archivio personale, perché le due pagine cercano nelle
    // stesse sedute con gli stessi criteri.
    $costruzione['ritorno'] = [
      '#theme' => 'psiphos_ritorno_dashboard',
      '#indirizzo' => $this->dashboard(),
      '#weight' => -20,
    ];

    $costruzione['filtri'] = [
      '#theme' => 'psiphos_filtri_sedute',
      '#filtri' => $this->filtri(),
      '#opzioni' => $this->opzioni(),
      '#filtrato' => array_filter($this->filtri()) !== [],
      '#azione' => Url::fromRoute('entity.psiphos_seduta.collection')->toString(),
      '#weight' => -10,
    ];
    $costruzione['#attached']['library'][] = 'psiphos/verbale';

    // Le condizioni dei filtri stanno nell'indirizzo e l'elenco è per utente:
    // senza dichiararlo, la pagina memorizzata dalla prima richiesta verrebbe
    // riproposta a filtri diversi e a persone diverse.
    $costruzione['#cache']['contexts'] = array_merge(
      $costruzione['#cache']['contexts'] ?? [],
      ['url.query_args', 'user']
    );
    $costruzione['#cache']['max-age'] = 0;
    if (isset($costruzione['table']) && is_array($costruzione['table'])) {
      $costruzione['table']['#cache']['contexts'] = array_merge(
        $costruzione['table']['#cache']['contexts'] ?? [],
        ['url.query_args', 'user']
      );
      $costruzione['table']['#cache']['max-age'] = 0;
    }

    return $costruzione;
  }

  protected function getEntityIds(): array {
    $interrogazione = $this->getStorage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('data_seduta', 'DESC')
      ->sort('id', 'DESC')
      ->pager($this->limit);

    // L'interrogazione con controllo d'accesso non filtra queste entità:
    // senza un gestore d'accesso a livello di interrogazione, «accessCheck»
    // le lascia passare tutte, e un coordinatore vedrebbe in elenco le sedute
    // di ogni altro. La restrizione va quindi imposta qui.
    $visibili = $this->identificativiVisibili();
    if ($visibili !== NULL) {
      if ($visibili === []) {
        return [];
      }
      $interrogazione->condition('id', $visibili, 'IN');
    }

    $filtri = $this->filtri();
    if (($filtri['oggetto'] ?? '') !== '') {
      $interrogazione->condition('titolo', $filtri['oggetto'], 'CONTAINS');
    }
    foreach (['organo' => 'organo', 'anno' => 'anno_scolastico', 'stato' => 'stato'] as $chiave => $campo) {
      if (($filtri[$chiave] ?? '') !== '') {
        $interrogazione->condition($campo, $filtri[$chiave]);
      }
    }

    return $interrogazione->execute();
  }

  /**
   * Il ritorno alla dashboard, se chi guarda può raggiungerla.
   */
  private function dashboard(): ?Url {
    try {
      $indirizzo = Url::fromUserInput('/admin/dashboard');
    }
    catch (\Throwable) {
      return NULL;
    }

    return $indirizzo->isRouted() && $indirizzo->access(\Drupal::currentUser())
      ? $indirizzo
      : NULL;
  }

  /**
   * Gli identificativi che l'utente può vedere, o NULL se può vederli tutti.
   *
   * @return array<int, int>|null
   */
  private function identificativiVisibili(): ?array {
    $utente = \Drupal::currentUser();
    if ($utente->hasPermission('administer psiphos')
      || $utente->hasPermission('psiphos visualizzare ogni verbale')) {
      return NULL;
    }

    $mio = (int) $utente->id();
    $gestore = \Drupal::entityTypeManager();

    $presenze = $gestore->getStorage('psiphos_presenza');
    $riferimenti = [];
    foreach ($presenze->loadMultiple($presenze->getQuery()
      ->accessCheck(FALSE)
      ->condition('utente', $mio)
      ->execute()) as $presenza) {
      $riferimento = (int) ($presenza->get('seduta')->target_id ?? 0);
      if ($riferimento > 0) {
        $riferimenti[$riferimento] = $riferimento;
      }
    }

    $perRuolo = $this->getStorage()->getQuery()->accessCheck(FALSE);
    $perRuolo->condition($perRuolo->orConditionGroup()
      ->condition('presidente', $mio)
      ->condition('segretario', $mio)
      ->condition('uid', $mio));
    foreach ($perRuolo->execute() as $identificativo) {
      $riferimenti[(int) $identificativo] = (int) $identificativo;
    }

    return array_values($riferimenti);
  }

  /**
   * I filtri richiesti, ripuliti di quanto non riconosciuto.
   *
   * @return array<string, string>
   */
  private function filtri(): array {
    // Nessuna memoria: il gestore dell'elenco è riusato per l'intero
    // processo, e una memoria d'istanza servirebbe a tutte le richieste
    // successive i filtri della prima.
    $richiesta = \Drupal::request();
    $letto = static fn (string $chiave): string => trim((string) $richiesta->query->get($chiave, ''));
    $opzioni = $this->opzioni();
    $ammesso = static fn (string $chiave, array $valide): string => isset($valide[$chiave]) ? $chiave : '';

    return [
      'oggetto' => mb_substr($letto('oggetto'), 0, 128),
      'organo' => $ammesso($letto('organo'), $opzioni['organi']),
      'anno' => $ammesso($letto('anno'), $opzioni['anni']),
      'stato' => $ammesso($letto('stato'), $opzioni['stati']),
      'ruolo' => '',
    ];
  }

  /**
   * Le opzioni dei filtri.
   *
   * @return array<string, array<string, string>>
   */
  private function opzioni(): array {
    if ($this->opzioniMemorizzate !== NULL) {
      return $this->opzioniMemorizzate;
    }

    $organi = [];
    foreach (TipoOrgano::cases() as $caso) {
      $organi[$caso->value] = $caso->etichetta();
    }

    $stati = [];
    foreach (StatoSeduta::cases() as $caso) {
      $stati[$caso->value] = $caso->etichetta();
    }

    // Gli anni si ricavano dalle sedute in essere: un elenco costruito a
    // priori proporrebbe annate in cui non si è convocato nulla.
    $anni = [];
    foreach ($this->getStorage()->loadMultiple($this->getStorage()->getQuery()
      ->accessCheck(FALSE)
      ->exists('anno_scolastico')
      ->execute()) as $seduta) {
      $anno = trim((string) $seduta->get('anno_scolastico')->value);
      if ($anno !== '') {
        $anni[$anno] = $anno;
      }
    }
    krsort($anni);

    return $this->opzioniMemorizzate = [
      'organi' => $organi,
      'anni' => $anni,
      'stati' => $stati,
      'ruoli' => [],
    ];
  }

}
