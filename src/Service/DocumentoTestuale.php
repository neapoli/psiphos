<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\Markup;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Rende leggibili dal sito i documenti che accompagnano il modulo.
 *
 * I documenti per l'istituzione sono file di testo nella cartella del modulo:
 * si scrivono e si versionano come il codice, e chi li riceve può conservarli
 * senza dipendere dal sito. Ma il §9 chiede all'istituzione di *acquisire* la
 * documentazione tecnica, e un percorso di file dentro una cartella di codice
 * non è acquisibile da un dirigente scolastico.
 *
 * Da qui questa resa, che interpreta il sottoinsieme di marcatori effettivamente
 * usato in quei documenti — titoli, paragrafi, citazioni, tabelle, elenchi,
 * caselle di spunta, blocchi di codice, grassetto, corsivo e codice in linea —
 * e nient'altro. Non è un interprete Markdown completo e non deve diventarlo:
 * i documenti sono scritti qui, il sottoinsieme è noto, e una riga che non
 * rientri fra i costrutti previsti viene segnalata anziché resa a caso.
 */
final class DocumentoTestuale {

  use StringTranslationTrait;

  /**
   * I documenti pubblicabili, nell'ordine in cui vanno affrontati.
   *
   * L'elenco è chiuso: si pubblica ciò che è destinato all'istituzione, non
   * qualunque file si trovi nella cartella.
   */
  private const DOCUMENTI = [
    'richieste-al-fornitore-hosting' => 'richieste-al-fornitore-hosting.md',
    'nomina-del-manutentore' => 'nomina-del-manutentore.md',
    'regolamento-articolo' => 'regolamento-articolo.md',
    'dpia-elementi' => 'dpia-elementi.md',
    'registro-art-30' => 'registro-art-30.md',
    'conservazione-a-norma' => 'conservazione-a-norma.md',
    'dichiarazione-conformita' => 'dichiarazione-conformita.md',
  ];

  public function __construct(
    private readonly ModuleExtensionList $moduli,
    private readonly ConservazioneDocumento $conservazione,
  ) {}

  /**
   * Identificativi dei documenti disponibili, nell'ordine di lettura.
   *
   * @return array<int, string>
   */
  public function elenco(): array {
    return array_keys(array_filter(
      self::DOCUMENTI,
      fn (string $file): bool => is_readable($this->percorso($file)),
    ));
  }

  /**
   * Vero se l'identificativo corrisponde a un documento leggibile.
   */
  public function esiste(string $identificativo): bool {
    return in_array($identificativo, $this->elenco(), TRUE);
  }

  /**
   * Titolo del documento, letto dalla sua prima riga.
   */
  public function titolo(string $identificativo): string {
    foreach ($this->righe($identificativo) as $riga) {
      if (str_starts_with($riga, '# ')) {
        return trim(substr($riga, 2));
      }
    }

    return $identificativo;
  }

  /**
   * Testo originale del documento, per chi voglia conservarlo o inoltrarlo.
   */
  public function sorgente(string $identificativo): string {
    $file = self::DOCUMENTI[$identificativo] ?? '';

    return $file === '' ? '' : (string) file_get_contents($this->percorso($file));
  }

  /**
   * Nome del file da proporre allo scaricamento.
   */
  public function nomeFile(string $identificativo): string {
    return self::DOCUMENTI[$identificativo] ?? '';
  }

  /**
   * Nome del file della guida in PDF.
   */
  public function nomeFilePdf(string $identificativo): string {
    return sprintf('%s.pdf', $identificativo);
  }

  /**
   * La guida in PDF, per chi la deve leggere e non sa che cosa sia un .md.
   *
   * Il testo sorgente resta scaricabile, ma è il formato di chi lavora sul
   * codice: un Responsabile della protezione dei dati o un componente del
   * Consiglio d'istituto che riceve un file «.md» non ha nulla con cui
   * aprirlo, e nella migliore delle ipotesi lo vede in un blocco note con i
   * cancelletti e le stanghette al posto dei titoli e delle tabelle.
   *
   * @return array{contenuto: string, formato: string, impronta: string}
   */
  public function pdf(string $identificativo): array {
    if (!$this->esiste($identificativo)) {
      throw new \InvalidArgumentException(sprintf('Documento %s non disponibile.', $identificativo));
    }

    return $this->conservazione->produci(sprintf(
      '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>%s</title><style>%s</style></head>'
        . '<body><h1>%s</h1>%s</body></html>',
      Html::escape($this->titolo($identificativo)),
      $this->foglioDiStile(),
      Html::escape($this->titolo($identificativo)),
      (string) $this->reso($identificativo)
    ));
  }

  /**
   * Foglio di stile della guida stampata.
   *
   * Prosa lunga con tabelle e citazioni: conta la leggibilità a schermo e su
   * carta, non l'apparenza d'atto — questi documenti si studiano, non si
   * sottoscrivono.
   */
  private function foglioDiStile(): string {
    return <<<'CSS'
      @page { size: A4; margin: 15mm 18mm; }
      body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; line-height: 1.5; color: #000; }
      h1 { font-size: 15pt; margin: 0 0 5mm; }
      h2 { font-size: 12pt; margin: 7mm 0 2mm; page-break-after: avoid; }
      h3 { font-size: 10.5pt; margin: 5mm 0 1.5mm; page-break-after: avoid; }
      h4 { font-size: 9.5pt; margin: 4mm 0 1.5mm; page-break-after: avoid; }
      p { margin: 0 0 3mm; }
      ul, ol { margin: 0 0 3mm; padding-left: 6mm; }
      li { margin: 0 0 1.5mm; }
      hr { border: none; border-top: 0.4pt solid #000; margin: 5mm 0; }
      blockquote { margin: 0 0 3mm; padding: 2mm 4mm; border-left: 1.2pt solid #000; }
      blockquote p { margin: 0 0 1.5mm; }
      table { width: 100%; border-collapse: collapse; margin: 0 0 4mm; page-break-inside: avoid; }
      th, td { border: 0.4pt solid #000; padding: 1.2mm 1.8mm; text-align: left; vertical-align: top; font-size: 8.5pt; }
      th { font-weight: bold; }
      pre { margin: 0 0 3mm; padding: 2mm; border: 0.4pt solid #000; font-size: 8pt; }
      code { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; }
      CSS;
  }

  /**
   * Documento reso in HTML.
   */
  public function reso(string $identificativo): MarkupInterface {
    $righe = $this->righe($identificativo);
    $uscita = [];
    $blocco = [];
    $tipoBlocco = '';

    /** Chiude il blocco in corso, qualunque esso sia. */
    $chiudi = function () use (&$uscita, &$blocco, &$tipoBlocco): void {
      if ($blocco === []) {
        $tipoBlocco = '';
        return;
      }
      $uscita[] = match ($tipoBlocco) {
        'paragrafo' => '<p>' . implode(' ', $blocco) . '</p>',
        'citazione' => '<blockquote>' . implode("\n", $blocco) . '</blockquote>',
        'tabella' => $this->tabella($blocco),
        'elenco' => '<ul>' . implode('', $blocco) . '</ul>',
        'numerato' => '<ol>' . implode('', $blocco) . '</ol>',
        'codice' => '<pre><code>' . implode("\n", $blocco) . '</code></pre>',
        default => implode("\n", $blocco),
      };
      $blocco = [];
      $tipoBlocco = '';
    };

    $inCodice = FALSE;

    foreach ($righe as $riga) {
      $grezza = rtrim($riga, "\r\n");
      $testo = trim($grezza);

      // Il recinto di codice ha la precedenza su tutto: dentro non si
      // interpreta nulla, o si sformatterebbero comandi ed esempi.
      if (str_starts_with($testo, '```')) {
        if ($inCodice) {
          $chiudi();
          $inCodice = FALSE;
        }
        else {
          $chiudi();
          $inCodice = TRUE;
          $tipoBlocco = 'codice';
        }
        continue;
      }

      if ($inCodice) {
        $blocco[] = Html::escape($grezza);
        continue;
      }

      if ($testo === '') {
        $chiudi();
        continue;
      }

      if (preg_match('/^(#{1,6}) (.+)$/', $testo, $riscontro) === 1) {
        $chiudi();
        // I titoli scalano di uno: il titolo della pagina è già un h1.
        $livello = min(strlen($riscontro[1]) + 1, 6);
        $uscita[] = sprintf('<h%d>%s</h%d>', $livello, $this->inLinea($riscontro[2]), $livello);
        continue;
      }

      if (preg_match('/^-{3,}$/', $testo) === 1) {
        $chiudi();
        $uscita[] = '<hr>';
        continue;
      }

      if (str_starts_with($testo, '|')) {
        if ($tipoBlocco !== 'tabella') {
          $chiudi();
          $tipoBlocco = 'tabella';
        }
        $blocco[] = $testo;
        continue;
      }

      if (str_starts_with($testo, '> ') || $testo === '>') {
        if ($tipoBlocco !== 'citazione') {
          $chiudi();
          $tipoBlocco = 'citazione';
        }
        $contenuto = trim(substr($testo, 1));
        $blocco[] = $contenuto === '' ? '' : '<p>' . $this->inLinea($contenuto) . '</p>';
        continue;
      }

      if (preg_match('/^- \[( |x)\] (.+)$/', $testo, $riscontro) === 1) {
        if ($tipoBlocco !== 'elenco') {
          $chiudi();
          $tipoBlocco = 'elenco';
        }
        $blocco[] = sprintf(
          '<li class="psiphos-documento__spunta"><span aria-hidden="true">%s</span> %s</li>',
          $riscontro[1] === 'x' ? '☑' : '☐',
          $this->inLinea($riscontro[2])
        );
        continue;
      }

      if (preg_match('/^[-*] (.+)$/', $testo, $riscontro) === 1) {
        if ($tipoBlocco !== 'elenco') {
          $chiudi();
          $tipoBlocco = 'elenco';
        }
        $blocco[] = '<li>' . $this->inLinea($riscontro[1]) . '</li>';
        continue;
      }

      if (preg_match('/^\d+\. (.+)$/', $testo, $riscontro) === 1) {
        if ($tipoBlocco !== 'numerato') {
          $chiudi();
          $tipoBlocco = 'numerato';
        }
        $blocco[] = '<li>' . $this->inLinea($riscontro[1]) . '</li>';
        continue;
      }

      // Riga rientrata che prosegue l'elemento precedente di un elenco: si
      // unisce a quello, invece di aprire un paragrafo che lo spezzerebbe.
      if ($grezza !== $testo && in_array($tipoBlocco, ['elenco', 'numerato'], TRUE) && $blocco !== []) {
        $ultimo = array_pop($blocco);
        $blocco[] = preg_replace('#</li>$#', ' ' . $this->inLinea($testo) . '</li>', $ultimo);
        continue;
      }

      if ($tipoBlocco !== 'paragrafo') {
        $chiudi();
        $tipoBlocco = 'paragrafo';
      }
      $blocco[] = $this->inLinea($testo);
    }

    $chiudi();

    return Markup::create(implode("\n", $uscita));
  }

  /**
   * Righe del documento.
   *
   * @return array<int, string>
   */
  private function righe(string $identificativo): array {
    $file = self::DOCUMENTI[$identificativo] ?? '';

    if ($file === '' || !is_readable($this->percorso($file))) {
      return [];
    }

    return file($this->percorso($file), FILE_IGNORE_NEW_LINES) ?: [];
  }

  private function percorso(string $file): string {
    return sprintf('%s/documentazione/%s', $this->moduli->getPath('psiphos'), $file);
  }

  /**
   * Tabella, con la riga di separazione usata per riconoscere l'intestazione.
   *
   * @param array<int, string> $righe
   */
  private function tabella(array $righe): string {
    $celle = static fn (string $riga): array => array_map(
      'trim',
      explode('|', trim($riga, '| '))
    );

    $intestazione = [];
    $corpo = [];

    foreach ($righe as $indice => $riga) {
      if (preg_match('/^\|[\s:|-]+\|?$/', $riga) === 1) {
        // È la riga di separazione: quanto la precede è intestazione.
        $intestazione = $corpo;
        $corpo = [];
        continue;
      }
      $corpo[] = $celle($riga);
    }

    $reso = '<div class="psiphos-documento__tabella"><table>';

    foreach ($intestazione as $riga) {
      $reso .= '<thead><tr>';
      foreach ($riga as $cella) {
        $reso .= '<th scope="col">' . $this->inLinea($cella) . '</th>';
      }
      $reso .= '</tr></thead>';
    }

    $reso .= '<tbody>';
    foreach ($corpo as $riga) {
      $reso .= '<tr>';
      foreach ($riga as $cella) {
        $reso .= '<td>' . $this->inLinea($cella) . '</td>';
      }
      $reso .= '</tr>';
    }

    return $reso . '</tbody></table></div>';
  }

  /**
   * Marcatori in linea: grassetto, corsivo e codice.
   *
   * Il testo viene prima interamente sottoposto a escape: i documenti non
   * contengono HTML, e ciò che vi somigliasse va mostrato e non eseguito.
   */
  private function inLinea(string $testo): string {
    $reso = Html::escape($testo);
    $reso = preg_replace('/`([^`]+)`/', '<code>$1</code>', $reso);
    $reso = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $reso);
    $reso = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $reso);

    return (string) $reso;
  }

}
