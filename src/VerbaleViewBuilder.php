<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\Verbale;

/**
 * Resa del verbale, identica in pagina e nel documento da conservare.
 */
final class VerbaleViewBuilder extends EntityViewBuilder {

  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    assert($entity instanceof Verbale);

    return [
      '#theme' => 'psiphos_verbale',
      '#verbale' => $entity,
      '#dati' => \Drupal::service('psiphos.verbalizzazione')->struttura($entity),
      '#documento' => FALSE,
      '#estratti' => $this->estratti($entity),
      '#azioni' => $this->azioni($entity),
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Collegamenti agli estratti di delibera, per identificativo di delibera.
   *
   * Il verbale documenta la seduta; ciascuna delibera è un atto a sé, con un
   * proprio documento e una propria impronta. Chi legge il verbale deve poter
   * raggiungere l'atto da dove la delibera è riportata, senza cercarlo.
   *
   * Non compaiono nel documento da conservare: fra dieci anni quegli
   * indirizzi non esisteranno più, mentre gli estratti sì.
   *
   * @return array<string, \Drupal\Core\Link>
   */
  private function estratti(Verbale $verbale): array {
    $seduta = $verbale->seduta();

    if ($seduta === NULL) {
      return [];
    }

    $collegamenti = [];

    foreach (\Drupal::service('psiphos.verbalizzazione')->delibereDaFormalizzare($seduta) as $delibera) {
      if (!$delibera->attoSigillato() || $delibera->get('documento')->target_id === NULL) {
        continue;
      }

      $collegamenti[$delibera->uuid()] = Link::fromTextAndUrl(
        $this->t("Scarica l'estratto di delibera"),
        Url::fromRoute('psiphos.delibera.documento', ['psiphos_delibera' => $delibera->id()])
      );
    }

    return $collegamenti;
  }

  /**
   * Collegamenti operativi disponibili sul verbale.
   *
   * Il §7 chiede l'esportazione strutturata dei risultati: perché sia un
   * requisito attuato e non solo una rotta esistente, deve essere
   * raggiungibile da chi ne ha bisogno senza conoscere l'indirizzo.
   *
   * @return array<int, \Drupal\Core\Link>
   */
  private function azioni(Verbale $verbale): array {
    $azioni = [];

    // Il verbale è un'entità propria e porta con sé le proprie schede: senza
    // un rimando esplicito, chi vi arriva perde la seduta di vista e deve
    // ripartire dall'elenco dei contenuti.
    $seduta = $verbale->seduta();
    if ($seduta !== NULL && $seduta->access('view')) {
      $azioni[] = Link::fromTextAndUrl(
        $this->t('Torna alla seduta «@seduta»', ['@seduta' => $seduta->label()]),
        $seduta->toUrl(options: ['attributes' => ['class' => ['button']]])
      );
    }

    if ($verbale->sigillato() && $verbale->get('documento')->target_id !== NULL) {
      // Su un verbale sigillato il documento è ciò per cui la pagina esiste.
      $azioni[] = Link::fromTextAndUrl(
        $this->t('Scarica il documento conservato'),
        Url::fromRoute('psiphos.verbale.documento', ['psiphos_verbale' => $verbale->id()], [
          'attributes' => ['class' => ['button', 'button--primary']],
        ])
      );
    }

    $esportazione = Url::fromRoute('psiphos.verbale.esporta', ['psiphos_verbale' => $verbale->id()], [
      'attributes' => ['class' => ['button']],
    ]);
    if ($esportazione->access()) {
      $azioni[] = Link::fromTextAndUrl($this->t('Esporta i dati della seduta'), $esportazione);
    }

    return $azioni;
  }

  public function viewMultiple(array $entities = [], $view_mode = 'full', $langcode = NULL): array {
    $costruzione = [];
    foreach ($entities as $chiave => $entita) {
      $costruzione[$chiave] = $this->view($entita, $view_mode, $langcode);
    }

    return $costruzione;
  }

}
