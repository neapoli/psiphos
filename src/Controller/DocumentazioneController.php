<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\psiphos\Service\DocumentoTestuale;
use Drupal\psiphos\Service\ModuliPrecompilati;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Consultazione dei documenti che accompagnano il modulo.
 *
 * Il §9 chiede all'istituzione di acquisire la documentazione tecnica. Finché
 * quei documenti erano soltanto file nella cartella del modulo, l'acquisizione
 * presupponeva un accesso al codice che un dirigente scolastico non ha: erano
 * documenti scritti per la scuola e raggiungibili solo da chi la scuola la
 * serve.
 */
final class DocumentazioneController extends ControllerBase {

  public function __construct(
    private readonly DocumentoTestuale $documenti,
    private readonly ModuliPrecompilati $modelli,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psiphos.documento_testuale'),
      $container->get('psiphos.moduli_precompilati'),
    );
  }

  /**
   * Elenco dei documenti, nell'ordine in cui vanno affrontati.
   */
  public function elenco(): array {
    $voci = [];

    foreach ($this->documenti->elenco() as $identificativo) {
      $voci[] = [
        'titolo' => Link::createFromRoute(
          $this->documenti->titolo($identificativo),
          'psiphos.documentazione.documento',
          ['documento' => $identificativo]
        ),
        // In PDF, non in Markdown: la guida si inoltra al Responsabile della
        // protezione dei dati e al Consiglio d'istituto, e un file «.md» chi
        // lo riceve non ha con che cosa aprirlo.
        'scarica' => Link::createFromRoute(
          $this->t('Scarica la guida'),
          'psiphos.documentazione.guida',
          ['documento' => $identificativo]
        ),
        // Dove la guida contiene un modello di lettera o di atto, il modello
        // si scarica compilato: è quello che la scuola deve spedire, mentre
        // la guida è quella che deve leggere. Una guida può portarne più
        // d'uno, e allora ciascuno si nomina, perché «il modulo precompilato»
        // ripetuto due volte non direbbe quale dei due si sta scaricando.
        'modelli' => $this->collegamentiAiModelli($identificativo),
      ];
    }

    return [
      '#theme' => 'psiphos_documentazione',
      '#documenti' => $voci,
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Un documento reso in pagina.
   */
  public function documento(string $documento): array {
    if (!$this->documenti->esiste($documento)) {
      throw new NotFoundHttpException('Documento non disponibile.');
    }

    return [
      '#theme' => 'psiphos_documento',
      '#contenuto' => $this->documenti->reso($documento),
      // array_merge e non «+»: le chiavi degli uni e degli altri sono
      // entrambe 0 e 1, e l'unione le lascerebbe cadere in silenzio.
      '#azioni' => array_merge([
        Link::fromTextAndUrl(
          $this->t('Torna ai documenti'),
          Url::fromRoute('psiphos.documentazione', [], ['attributes' => ['class' => ['button']]])
        ),
        Link::fromTextAndUrl(
          $this->t('Scarica la guida'),
          Url::fromRoute('psiphos.documentazione.guida', ['documento' => $documento], [
            'attributes' => ['class' => ['button']],
          ])
        ),
        Link::fromTextAndUrl(
          $this->t('Testo sorgente (Markdown)'),
          Url::fromRoute('psiphos.documentazione.sorgente', ['documento' => $documento], [
            'attributes' => ['class' => ['button']],
          ])
        ),
      ], array_map(
        static fn (array $modello): Link => Link::fromTextAndUrl(
          $modello['etichetta'],
          Url::fromRoute(
            'psiphos.documentazione.modello',
            ['documento' => $modello['documento'], 'modello' => $modello['modello']],
            ['attributes' => ['class' => ['button', 'button--primary']]]
          )
        ),
        $this->modelliDelDocumento($documento)
      )),
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * La guida in PDF: è la forma in cui si conserva e si inoltra.
   */
  public function guida(string $documento): Response {
    if (!$this->documenti->esiste($documento)) {
      throw new NotFoundHttpException('Documento non disponibile.');
    }

    $prodotto = $this->documenti->pdf($documento);

    $risposta = new Response($prodotto['contenuto']);
    $risposta->headers->set('Content-Type', 'application/pdf');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('inline; filename="%s"', $this->documenti->nomeFilePdf($documento))
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * Il testo sorgente, per chi voglia rielaborarlo.
   */
  public function sorgente(string $documento): Response {
    if (!$this->documenti->esiste($documento)) {
      throw new NotFoundHttpException('Documento non disponibile.');
    }

    $risposta = new Response($this->documenti->sorgente($documento));
    $risposta->headers->set('Content-Type', 'text/markdown; charset=utf-8');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('attachment; filename="%s"', $this->documenti->nomeFile($documento))
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * Il modello di lettera o di atto, compilato con quanto il sito conosce.
   */
  public function modello(string $documento, ?string $modello = NULL): Response {
    $modello = ($modello === NULL || $modello === '') ? NULL : $modello;

    if (!$this->documenti->esiste($documento) || !$this->modelli->disponibile($documento, $modello)) {
      throw new NotFoundHttpException('Nessun modulo precompilato per questo documento.');
    }

    $prodotto = $this->modelli->produci($documento, $modello);

    $risposta = new Response($prodotto['contenuto']);
    $risposta->headers->set('Content-Type', 'application/pdf');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('inline; filename="%s"', $this->modelli->nomeFile($documento, $modello))
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  /**
   * I modelli del documento, con l'etichetta del collegamento che li offre.
   *
   * @return array<int, array{documento: string, modello: string, etichetta: string}>
   */
  private function modelliDelDocumento(string $documento): array {
    $modelli = $this->modelli->modelli($documento);

    return array_map(
      fn (string $modello): array => [
        'documento' => $documento,
        'modello' => $modello,
        'etichetta' => count($modelli) === 1
          ? (string) $this->t('Scarica il modulo precompilato')
          // t() protegge i segnaposto per l'HTML: senza questo passaggio
          // «dell'infrastruttura» arriverebbe all'etichetta come entità, e
          // vi comparirebbe due volte protetta.
          : PlainTextOutput::renderFromHtml((string) $this->t('Scarica: @titolo', [
            '@titolo' => $this->modelli->titolo($documento, $modello),
          ])),
      ],
      $modelli
    );
  }

  /**
   * @return array<int, \Drupal\Core\Link>
   */
  private function collegamentiAiModelli(string $documento): array {
    return array_map(
      static fn (array $modello): Link => Link::createFromRoute(
        $modello['etichetta'],
        'psiphos.documentazione.modello',
        ['documento' => $modello['documento'], 'modello' => $modello['modello']]
      ),
      $this->modelliDelDocumento($documento)
    );
  }

  public function titolo(string $documento): string {
    return $this->documenti->esiste($documento)
      ? $this->documenti->titolo($documento)
      : (string) $this->t('Documento');
  }

}
