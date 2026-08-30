<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Service\Verbalizzazione;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accesso all'estratto di delibera e alla sua esportazione.
 */
final class DeliberaController extends ControllerBase {

  public function __construct(
    private readonly Verbalizzazione $verbalizzazione,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psiphos.verbalizzazione'),
      $container->get('file_system'),
    );
  }

  /**
   * Scarica l'estratto di delibera.
   */
  public function documento(DeliberaInterface $psiphos_delibera): Response {
    $file = $psiphos_delibera->get('documento')->entity;

    // Le tre cause di fallimento si assomigliano da fuori — tutte una pagina
    // non trovata — ma hanno rimedi opposti: sigillare il verbale, ripristinare
    // un file dal backup, sistemare i permessi della cartella riservata.
    // Distinguerle qui evita di cercare alla cieca, e il registro le conserva
    // per chi non ha davanti lo schermo di chi ha cliccato.
    if ($file === NULL) {
      $riferimento = $psiphos_delibera->get('documento')->target_id;

      if ($riferimento !== NULL) {
        $this->getLogger('psiphos')->error("L'estratto della delibera @id rimanda al file @file, che non esiste più.", [
          '@id' => $psiphos_delibera->id(),
          '@file' => $riferimento,
        ]);

        throw new NotFoundHttpException(sprintf(
          "L'estratto della delibera %s risulta prodotto ma il suo file non esiste più: va ripristinato dalla copia di sicurezza.",
          $psiphos_delibera->id()
        ));
      }

      throw new NotFoundHttpException(
        "La delibera non ha ancora un estratto: si produce al sigillo del verbale della seduta."
      );
    }

    $percorso = $this->fileSystem->realpath($file->getFileUri());
    $contenuto = $percorso !== FALSE && is_readable($percorso) ? file_get_contents($percorso) : FALSE;

    if ($contenuto === FALSE) {
      $this->getLogger('psiphos')->error("L'estratto della delibera @id non è leggibile in @uri.", [
        '@id' => $psiphos_delibera->id(),
        '@uri' => $file->getFileUri(),
      ]);

      throw new NotFoundHttpException(sprintf(
        "L'estratto della delibera %s non è leggibile in %s: verificare i permessi della cartella dei file riservati.",
        $psiphos_delibera->id(),
        $file->getFileUri()
      ));
    }

    $risposta = new Response($contenuto);
    $risposta->headers->set('Content-Type', 'application/pdf');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('inline; filename="%s.pdf"', $this->nomeFile($psiphos_delibera))
    );

    return $risposta;
  }

  /**
   * Esportazione strutturata dell'atto.
   *
   * L'impronta dell'atto registrata sulla delibera è lo SHA-256 di questo
   * file: chi riceve un estratto può verificarlo senza disporre del verbale
   * né accedere alla banca dati.
   */
  public function esporta(DeliberaInterface $psiphos_delibera): Response {
    $json = $this->verbalizzazione->esportaAtto($psiphos_delibera);

    $risposta = new Response($json);
    $risposta->headers->set('Content-Type', 'application/json; charset=utf-8');
    $risposta->headers->set(
      'Content-Disposition',
      sprintf('attachment; filename="%s.json"', $this->nomeFile($psiphos_delibera))
    );
    $risposta->setMaxAge(0);

    return $risposta;
  }

  public function titolo(DeliberaInterface $psiphos_delibera): string {
    $numero = trim((string) $psiphos_delibera->get('numero_delibera')->value);

    return $numero === ''
      ? (string) $this->t('Delibera — @oggetto', ['@oggetto' => $psiphos_delibera->oggettoAtto()])
      : (string) $this->t('Delibera n. @numero — @oggetto', [
        '@numero' => $numero,
        '@oggetto' => $psiphos_delibera->oggettoAtto(),
      ]);
  }

  /**
   * Nome del file scaricato.
   *
   * Porta il numero di delibera quando c'è, perché è così che la segreteria
   * archivia: un file identificato dal solo UUID è indistinguibile dagli
   * altri nella cartella in cui finisce.
   */
  private function nomeFile(DeliberaInterface $delibera): string {
    $numero = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $delibera->get('numero_delibera')->value));

    return $numero === '' || $numero === NULL
      ? sprintf('delibera-%s', $delibera->uuid())
      : sprintf('delibera-%s', trim($numero, '-'));
  }

}
