<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Entity\Verbale;
use Drupal\psiphos\Service\CostruttoreVerbale;
use Drupal\psiphos\Service\Verbalizzazione;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accesso al verbale di una seduta e alla sua esportazione.
 */
final class VerbaleController extends ControllerBase {

  public function __construct(
    private readonly Verbalizzazione $verbalizzazione,
    private readonly CostruttoreVerbale $costruttore,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psiphos.verbalizzazione'),
      $container->get('psiphos.costruttore_verbale'),
    );
  }

  /**
   * Apre il verbale della seduta, creando la bozza se non esiste.
   */
  public function perSeduta(SedutaInterface $psiphos_seduta): Response {
    $verbale = $this->verbalizzazione->esistente($psiphos_seduta);

    // La bozza si apre solo per chi la deve redigere. Crearla al semplice
    // passaggio di chiunque abbia accesso alla seduta produrrebbe verbali
    // vuoti e una tracciatura di apertura che non corrisponde a nulla.
    if ($verbale === NULL) {
      if (!$this->puoVerbalizzare($psiphos_seduta)) {
        throw new NotFoundHttpException('Il verbale di questa seduta non è ancora stato aperto.');
      }

      $verbale = $this->verbalizzazione->perSeduta($psiphos_seduta);
    }

    return $verbale->sigillato()
      ? $this->redirect('entity.psiphos_verbale.canonical', ['psiphos_verbale' => $verbale->id()])
      : $this->redirect('entity.psiphos_verbale.edit_form', ['psiphos_verbale' => $verbale->id()]);
  }

  /**
   * Vero se l'utente corrente è tenuto a redigere il verbale della seduta.
   */
  private function puoVerbalizzare(SedutaInterface $seduta): bool {
    if ($this->currentUser()->hasPermission('administer psiphos')) {
      return TRUE;
    }

    return (int) ($seduta->get('segretario')->target_id ?? 0) === (int) $this->currentUser()->id()
      && $this->currentUser()->hasPermission('psiphos verbalizzare');
  }

  /**
   * Esportazione strutturata del verbale.
   *
   * È l'evidenza documentale del §7: contiene tutto ciò che il verbale
   * riporta, in forma leggibile da un sistema. L'impronta del contenuto
   * registrata sul verbale è lo SHA-256 di questo file, calcolabile con un
   * qualunque strumento e senza accedere alla banca dati.
   */
  public function esporta(Verbale $psiphos_verbale): Response {
    $json = $this->verbalizzazione->esporta($psiphos_verbale);

    $risposta = new Response($json);
    $risposta->headers->set('Content-Type', 'application/json; charset=utf-8');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('attachment; filename="verbale-%s.json"', $psiphos_verbale->uuid())
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * Scarica il documento conservato.
   */
  public function documento(Verbale $psiphos_verbale): Response {
    $file = $psiphos_verbale->get('documento')->entity;

    if ($file === NULL) {
      throw new NotFoundHttpException('Il verbale non ha ancora un documento: va sigillato.');
    }

    $contenuto = file_get_contents($file->getFileUri());

    if ($contenuto === FALSE) {
      throw new NotFoundHttpException('Il documento del verbale non è leggibile.');
    }

    $risposta = new Response($contenuto);
    $risposta->headers->set('Content-Type', 'application/pdf');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('inline; filename="verbale-%s.pdf"', $psiphos_verbale->uuid())
    );

    return $risposta;
  }

  /**
   * Verifica di integrità del verbale sigillato.
   */
  public function verifica(Verbale $psiphos_verbale): array {
    $contenuto = $this->costruttore->verifica($psiphos_verbale);
    $file = $psiphos_verbale->get('documento')->entity;
    $improntaAttesa = (string) $psiphos_verbale->get('impronta_pdf')->value;
    $improntaCalcolata = $file === NULL ? '' : hash_file('sha256', $file->getFileUri());

    $documentoIntegro = $improntaAttesa !== '' && $improntaCalcolata !== FALSE
      && hash_equals($improntaAttesa, (string) $improntaCalcolata);

    $seduta = $psiphos_verbale->seduta();

    return [
      '#theme' => 'psiphos_verifica_verbale',
      '#verbale' => $psiphos_verbale,
      '#azioni' => $seduta !== NULL && $seduta->access('view')
        ? [Link::fromTextAndUrl(
          $this->t('Torna alla seduta «@seduta»', ['@seduta' => $seduta->label()]),
          $seduta->toUrl(options: ['attributes' => ['class' => ['button']]])
        )]
        : [],
      '#sigillato' => $contenuto['sigillato'],
      '#contenuto_integro' => $contenuto['integro'],
      '#contenuto_corrispondente' => $contenuto['corrispondente'],
      '#impronta_contenuto_registrata' => $contenuto['impronta_registrata'],
      '#impronta_contenuto_ricalcolata' => $contenuto['impronta_ricalcolata'],
      '#impronta_dati_attuali' => $contenuto['impronta_dati_attuali'],
      '#documento_integro' => $documentoIntegro,
      '#impronta_documento_registrata' => $improntaAttesa,
      '#impronta_documento_ricalcolata' => (string) $improntaCalcolata,
      '#cache' => ['max-age' => 0],
    ];
  }

  public function titolo(Verbale $psiphos_verbale): string {
    return (string) $psiphos_verbale->label();
  }

}
