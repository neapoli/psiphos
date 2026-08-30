<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Service\RegistroAudit;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consultazione delle tracciature tecniche di una seduta.
 */
final class TracciatureController extends ControllerBase {

  public function __construct(private readonly RegistroAudit $registro) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('psiphos.registro_audit'));
  }

  /**
   * Cronologia del procedimento deliberativo di una seduta.
   */
  public function seduta(SedutaInterface $psiphos_seduta): array {
    $tracciature = $this->registro->tracciature((int) $psiphos_seduta->id());
    $catena = $this->registro->verificaCatena((int) $psiphos_seduta->id());
    $archivioUtenti = $this->entityTypeManager()->getStorage('user');

    foreach ($tracciature as &$tracciatura) {
      $tracciatura['operatore'] = $tracciatura['utente'] === 0
        ? (string) $this->t('sistema')
        : \Drupal\psiphos\Nominativo::perUtente($archivioUtenti->load($tracciatura['utente']));
      $tracciatura['contesto_leggibile'] = $this->contestoLeggibile($tracciatura['contesto'], $archivioUtenti);
    }
    unset($tracciatura);

    return [
      '#theme' => 'psiphos_tracciature',
      '#seduta' => $psiphos_seduta,
      '#tracciature' => $tracciature,
      '#catena' => $catena,
      '#azioni' => $tracciature === [] ? [] : [
        Link::fromTextAndUrl(
          $this->t('Esporta le tracciature'),
          Url::fromRoute('psiphos.seduta.tracciature.esporta', ['psiphos_seduta' => $psiphos_seduta->id()])
        ),
      ],
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Esportazione delle tracciature di una seduta.
   */
  public function esporta(SedutaInterface $psiphos_seduta): Response {
    $json = (string) json_encode([
      'formato' => 'psiphos-tracciature-v1',
      'seduta' => $psiphos_seduta->uuid(),
      'catena' => $this->registro->verificaCatena((int) $psiphos_seduta->id()),
      'tracciature' => $this->registro->tracciature((int) $psiphos_seduta->id()),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $risposta = new Response($json);
    $risposta->headers->set('Content-Type', 'application/json; charset=utf-8');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('attachment; filename="tracciature-%s.json"', $psiphos_seduta->uuid())
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * Rende leggibili i riferimenti a utenti contenuti nel contesto.
   *
   * @param array<string, mixed> $contesto
   *
   * @return array<string, string>
   */
  private function contestoLeggibile(array $contesto, $archivioUtenti): array {
    $leggibile = [];

    foreach ($contesto as $chiave => $valore) {
      if (in_array($chiave, ['votante', 'avente_diritto'], TRUE)) {
        $leggibile[$chiave] = \Drupal\psiphos\Nominativo::perUtente($archivioUtenti->load($valore));
        continue;
      }

      $leggibile[$chiave] = is_bool($valore)
        ? ($valore ? (string) $this->t('sì') : (string) $this->t('no'))
        : (string) $valore;
    }

    return $leggibile;
  }

}
