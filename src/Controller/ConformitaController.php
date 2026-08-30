<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Render\RendererInterface;
use Drupal\psiphos\Service\AttestazioneConformita;
use Drupal\psiphos\Service\ConservazioneDocumento;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attestazione di conformità della singola installazione (§9).
 */
final class ConformitaController extends ControllerBase {

  public function __construct(
    private readonly AttestazioneConformita $attestazione,
    private readonly ConservazioneDocumento $conservazione,
    private readonly RendererInterface $renderizzatore,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psiphos.attestazione_conformita'),
      $container->get('psiphos.conservazione_documento'),
      $container->get('renderer'),
    );
  }

  public function pagina(): array {
    $attestazione = $this->attestazione->attestazione();

    return [
      '#theme' => 'psiphos_conformita',
      '#attestazione' => $attestazione,
      '#documento' => FALSE,
      '#azioni' => [
        Link::fromTextAndUrl(
          $this->t('Scarica l\'attestazione da firmare'),
          Url::fromRoute('psiphos.conformita.documento')
        ),
        Link::fromTextAndUrl(
          $this->t('Esporta i dati in formato strutturato'),
          Url::fromRoute('psiphos.conformita.esporta')
        ),
        // La documentazione tecnica che il §9 chiede di acquisire si raggiunge
        // da qui: rimandarla a percorsi di file dentro la cartella del modulo
        // significava renderla inaccessibile a chi deve acquisirla.
        Link::fromTextAndUrl(
          $this->t("Documenti per l'istituzione"),
          Url::fromRoute('psiphos.documentazione')
        ),
      ],
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Attestazione in forma di documento sottoscrivibile.
   *
   * L'esportazione strutturata serve alla verifica automatica; questo serve
   * agli atti. Il §9 chiede una dichiarazione di conformità, e una
   * dichiarazione è un documento che qualcuno firma e la segreteria
   * protocolla: un file di dati non assolve quella funzione.
   */
  public function documento(): Response {
    $costruzione = [
      '#theme' => 'psiphos_conformita',
      '#attestazione' => $this->attestazione->attestazione(),
      '#documento' => TRUE,
      '#azioni' => [],
      '#cache' => ['max-age' => 0],
    ];

    $html = sprintf(
      '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>%s</title><style>%s</style></head><body>%s</body></html>',
      'Attestazione di conformita',
      $this->foglioDiStile(),
      (string) $this->renderizzatore->renderInIsolation($costruzione)
    );

    $documento = $this->conservazione->produci($html);

    $risposta = new Response($documento['contenuto']);
    $risposta->headers->set('Content-Type', 'application/pdf');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('inline; filename="psiphos-attestazione-conformita-%s.pdf"', date('Y-m-d'))
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  public function esporta(): Response {
    $json = (string) json_encode(
      $this->attestazione->attestazione(),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $risposta = new Response($json);
    $risposta->headers->set('Content-Type', 'application/json; charset=utf-8');
    $risposta->headers->set('Content-Disposition', 'attachment; filename="psiphos-conformita.json"');
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * Foglio di stile del documento, essenziale e in unità assolute.
   */
  private function foglioDiStile(): string {
    return <<<'CSS'
      @page { size: A4; margin: 18mm 15mm; }
      body { font-family: DejaVu Serif, serif; font-size: 9.5pt; line-height: 1.4; color: #000; }
      h1 { font-size: 14pt; margin: 0 0 3mm; }
      h2 { font-size: 11pt; margin: 6mm 0 2mm; page-break-after: avoid; }
      p { margin: 0 0 2.5mm; }
      table { width: 100%; border-collapse: collapse; margin: 3mm 0; }
      .psiphos-conformita__col--paragrafo { width: 4%; }
      .psiphos-conformita__col--requisito { width: 21%; }
      .psiphos-conformita__col--attuazione { width: 47%; }
      .psiphos-conformita__col--carico { width: 15%; }
      .psiphos-conformita__col--stato { width: 13%; }
      th, td { border: 0.4pt solid #000; padding: 1.2mm 1.8mm; text-align: left; vertical-align: top; font-size: 8pt; }
      dt { font-weight: bold; }
      dd { margin: 0 0 1.5mm; }
      .psiphos-verbale__nota { font-size: 7.5pt; }
      .psiphos-conformita__firma { margin-top: 10mm; }
      .psiphos-conformita__firma p { margin-bottom: 8mm; }
      CSS;
  }

}
