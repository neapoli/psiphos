<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\DeliberaInterface;

/**
 * Pagina di una delibera: l'atto, non la scheda di votazione.
 *
 * Chi apre una delibera cerca l'atto — numero, oggetto, premesse, dispositivo
 * — perché è quello che deve protocollare o pubblicare. La resa per campi
 * mostrerebbe invece la configurazione della scheda, che serviva prima del
 * voto e dopo non interessa più nessuno.
 */
final class DeliberaViewBuilder extends EntityViewBuilder {

  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    assert($entity instanceof DeliberaInterface);

    return [
      '#theme' => 'psiphos_estratto_delibera',
      '#delibera' => $entity,
      '#dati' => \Drupal::service('psiphos.verbalizzazione')->strutturaAtto($entity),
      '#documento' => FALSE,
      '#azioni' => $this->azioni($entity),
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function viewMultiple(array $entities = [], $view_mode = 'full', $langcode = NULL): array {
    $costruzione = [];
    foreach ($entities as $chiave => $entita) {
      $costruzione[$chiave] = $this->view($entita, $view_mode, $langcode);
    }

    return $costruzione;
  }

  /**
   * Collegamenti operativi disponibili sull'atto.
   *
   * @return array<int, \Drupal\Core\Link>
   */
  private function azioni(DeliberaInterface $delibera): array {
    $azioni = [];

    $seduta = $delibera->seduta();
    if ($seduta !== NULL && $seduta->access('view')) {
      $azioni[] = Link::fromTextAndUrl(
        $this->t('Torna alla seduta «@seduta»', ['@seduta' => $seduta->label()]),
        $seduta->toUrl(options: ['attributes' => ['class' => ['button']]])
      );
    }

    $redazione = Url::fromRoute('psiphos.delibera.atto', ['psiphos_delibera' => $delibera->id()], [
      'attributes' => ['class' => ['button']],
    ]);
    if ($redazione->access()) {
      $azioni[] = Link::fromTextAndUrl(
        $delibera->lacuneAtto() === [] ? $this->t("Modifica l'atto") : $this->t("Redigi l'atto"),
        $redazione
      );
    }

    if ($delibera->attoSigillato() && $delibera->get('documento')->target_id !== NULL) {
      $azioni[] = Link::fromTextAndUrl(
        $this->t("Scarica l'estratto di delibera"),
        Url::fromRoute('psiphos.delibera.documento', ['psiphos_delibera' => $delibera->id()], [
          'attributes' => ['class' => ['button', 'button--primary']],
        ])
      );
    }

    $esportazione = Url::fromRoute('psiphos.delibera.esporta', ['psiphos_delibera' => $delibera->id()], [
      'attributes' => ['class' => ['button']],
    ]);
    if ($delibera->attoSigillato() && $esportazione->access()) {
      $azioni[] = Link::fromTextAndUrl($this->t("Esporta i dati dell'atto"), $esportazione);
    }

    return $azioni;
  }

}
