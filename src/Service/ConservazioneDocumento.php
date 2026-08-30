<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\entity_print\Plugin\EntityPrintPluginManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Produzione del documento del verbale nel formato di conservazione.
 *
 * Il §7 richiede che la conservazione avvenga «nel rispetto delle Linee guida
 * AgID, assicurando nel tempo autenticità, integrità, leggibilità e
 * reperibilità dei documenti». Il formato prescritto per i documenti testuali
 * è il PDF/A, che dompdf da solo non produce: dompdf genera un PDF ordinario,
 * la conversione in PDF/A-2B è affidata a Ghostscript.
 *
 * Ghostscript può non essere installato sul server di destinazione. In quel
 * caso il verbale viene prodotto ugualmente, in PDF ordinario, e il formato
 * effettivo è registrato sul verbale stesso: è un'informazione che deve
 * risultare agli atti, perché un verbale non conforme al formato di
 * conservazione va trattato diversamente dal conservatore.
 */
final class ConservazioneDocumento {

  use StringTranslationTrait;

  public const FORMATO_CONSERVAZIONE = 'PDF/A-2B';

  public const FORMATO_ORDINARIO = 'PDF';

  /**
   * Percorsi in cui Ghostscript si trova abitualmente.
   */
  private const PERCORSI_NOTI = [
    '/usr/bin/gs',
    '/usr/local/bin/gs',
    '/opt/bin/gs',
    '/opt/local/bin/gs',
    '/bin/gs',
    '/usr/bin/ghostscript',
    '/usr/local/bin/ghostscript',
  ];

  public function __construct(
    private readonly EntityPrintPluginManagerInterface $motoriStampa,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly LoggerChannelInterface $registro,
  ) {}

  /**
   * Converte l'HTML del verbale nel documento da conservare.
   *
   * @return array{contenuto: string, formato: string, impronta: string}
   *
   * @throws \RuntimeException
   *   Se la generazione del PDF non riesce: senza documento non c'è nulla da
   *   sigillare, e proseguire produrrebbe un verbale senza il proprio atto.
   */
  public function produci(string $html): array {
    $motore = $this->motoriStampa->createSelectedInstance('pdf');
    $motore->addPage($html);
    $pdf = (string) $motore->getBlob();

    if ($pdf === '') {
      throw new \RuntimeException('La generazione del documento del verbale non ha prodotto alcun contenuto.');
    }

    $convertito = $this->convertiInPdfA($pdf);
    $contenuto = $convertito ?? $pdf;

    return [
      'contenuto' => $contenuto,
      'formato' => $convertito === NULL ? self::FORMATO_ORDINARIO : self::FORMATO_CONSERVAZIONE,
      'impronta' => hash('sha256', $contenuto),
    ];
  }

  /**
   * Vero se il formato di conservazione è ottenibile su questo server.
   */
  public function conservazioneDisponibile(): bool {
    return $this->impedimento() === NULL;
  }

  /**
   * Che cosa impedisce di produrre il formato di conservazione.
   *
   * Restituisce NULL quando nulla lo impedisce. Distinguere le cause serve a
   * chi deve rimediare: chiedere all'hosting di installare Ghostscript e
   * chiedergli di riabilitare l'esecuzione di processi sono due richieste
   * diverse, e su hosting condiviso la seconda è quella che più spesso non
   * viene concessa.
   */
  public function impedimento(): ?string {
    if (!$this->configurazione->get('psiphos.settings')->get('conservazione.pdfa_attivo')) {
      return (string) $this->t('La conversione nel formato di conservazione è disattivata nelle impostazioni.');
    }

    if (!$this->esecuzioneProcessiAmmessa()) {
      // Il file di configurazione in vigore va nominato: su hosting condiviso
      // si finisce spesso a modificare un php.ini che il processo che serve
      // il sito non legge affatto. E disable_functions ha livello di sistema,
      // quindi non è sovrascrivibile da un .user.ini né da ini_set().
      // Il nome del file non basta: la restrizione può arrivare dal pool
      // PHP-FPM, che non compare fra i file caricati. Il valore in vigore,
      // invece, è quello che il processo applica davvero, e confrontarlo con
      // quello della riga di comando dice subito se le due configurazioni
      // divergono.
      return (string) $this->t('La configurazione di PHP non consente di avviare processi esterni: proc_open risulta disabilitata. Il file di configurazione caricato dal processo che serve il sito è @file, e il valore di disable_functions in vigore è: @valore. La direttiva è di livello sistema, quindi non è modificabile da un file .user.ini né da .htaccess: si cambia nel php.ini caricato da questo processo o nella configurazione del pool PHP-FPM del dominio.', [
        '@file' => php_ini_loaded_file() ?: (string) $this->t('non determinabile'),
        '@valore' => $this->funzioniDisabilitate(),
      ]);
    }

    $eseguibile = $this->eseguibileGhostscript();

    if ($eseguibile === '') {
      return (string) $this->t('Non è indicato il percorso di Ghostscript.');
    }

    if (!@is_executable($eseguibile)) {
      return (string) $this->t('Ghostscript non è stato trovato in @percorso, oppure non è avviabile.', [
        '@percorso' => $eseguibile,
      ]);
    }

    return NULL;
  }

  /**
   * Cerca Ghostscript fra i percorsi in cui gli hosting lo collocano.
   *
   * Senza accesso alla riga di comando non c'è modo di cercarlo a mano, e il
   * percorso predefinito è quello di una sola distribuzione fra tante. La
   * ricerca interroga prima il sistema, che è la risposta autorevole, e
   * ripiega su un elenco di percorsi noti.
   *
   * @return string|null
   *   Il percorso trovato, oppure NULL.
   */
  public function cercaGhostscript(): ?string {
    if (!$this->esecuzioneProcessiAmmessa()) {
      return NULL;
    }

    // «command -v» è un builtin della shell e non un eseguibile: invocarlo
    // senza shell fallisce in silenzio. Va perciò eseguito attraverso la
    // shell, mentre «which» è un binario e si invoca direttamente.
    foreach (['gs', 'ghostscript'] as $nome) {
      $tentativi = [
        Process::fromShellCommandline(sprintf('command -v %s', escapeshellarg($nome))),
        new Process(['which', $nome]),
      ];

      foreach ($tentativi as $ricerca) {
        try {
          $ricerca->setTimeout(10);
          $ricerca->run();
          $trovato = trim(strtok($ricerca->getOutput(), "\n") ?: '');

          if ($ricerca->isSuccessful() && $trovato !== '' && @is_executable($trovato)) {
            return $trovato;
          }
        }
        catch (\Throwable) {
          // Si prosegue con il tentativo successivo.
        }
      }
    }

    foreach (self::PERCORSI_NOTI as $percorso) {
      if (@is_executable($percorso)) {
        return $percorso;
      }
    }

    return NULL;
  }

  /**
   * Valore in vigore di disable_functions, in forma leggibile.
   */
  private function funzioniDisabilitate(): string {
    $valore = trim((string) ini_get('disable_functions'));

    if ($valore === '') {
      return (string) $this->t('nessuna funzione disabilitata');
    }

    // Su alcuni server l'elenco è lunghissimo: si mostra quanto basta a
    // riconoscerlo, con il conteggio complessivo.
    $funzioni = array_filter(array_map('trim', explode(',', $valore)));

    if (count($funzioni) <= 12) {
      return implode(', ', $funzioni);
    }

    return (string) $this->t('@elenco e altre @numero', [
      '@elenco' => implode(', ', array_slice($funzioni, 0, 12)),
      '@numero' => count($funzioni) - 12,
    ]);
  }

  /**
   * Vero se PHP può avviare processi esterni.
   */
  private function esecuzioneProcessiAmmessa(): bool {
    if (!function_exists('proc_open')) {
      return FALSE;
    }

    $disabilitate = array_map(
      'trim',
      explode(',', (string) ini_get('disable_functions'))
    );

    return !in_array('proc_open', $disabilitate, TRUE);
  }

  /**
   * Converte un PDF ordinario in PDF/A-2B.
   *
   * @return string|null
   *   Il documento convertito, oppure NULL se la conversione non è
   *   disponibile o non è riuscita.
   */
  private function convertiInPdfA(string $pdf): ?string {
    if (!$this->conservazioneDisponibile()) {
      return NULL;
    }

    $ingresso = $this->fileSystem->tempnam('temporary://', 'psiphos_pdf_');
    $uscita = $this->fileSystem->tempnam('temporary://', 'psiphos_pdfa_');
    $percorsoIngresso = $this->fileSystem->realpath($ingresso);
    $percorsoUscita = $this->fileSystem->realpath($uscita);

    try {
      file_put_contents($percorsoIngresso, $pdf);

      $processo = new Process([
        $this->eseguibileGhostscript(),
        '-dPDFA=2',
        '-dBATCH',
        '-dNOPAUSE',
        '-dNOOUTERSAVE',
        '-sColorConversionStrategy=UseDeviceIndependentColor',
        '-sDEVICE=pdfwrite',
        '-dPDFACompatibilityPolicy=1',
        '-sOutputFile=' . $percorsoUscita,
        $percorsoIngresso,
      ]);
      $processo->setTimeout(120);
      $processo->mustRun();

      $convertito = (string) file_get_contents($percorsoUscita);

      // Una conversione riuscita ma vuota è un fallimento silenzioso: meglio
      // conservare il PDF ordinario dichiarandolo che un file illeggibile.
      return $convertito === '' ? NULL : $convertito;
    }
    catch (\Throwable $errore) {
      // Qualunque cosa impedisca la conversione — Ghostscript assente,
      // esecuzione negata dal sistema, conversione fallita — non deve
      // impedire di sigillare: il verbale si produce in PDF ordinario e il
      // formato effettivo resta registrato. Un verbale non sigillato è un
      // danno maggiore di un verbale nel formato sbagliato ma dichiarato.
      $this->registro->warning('Conversione in PDF/A non riuscita, il verbale sarà conservato in PDF ordinario: @messaggio', [
        '@messaggio' => $errore->getMessage(),
      ]);

      return NULL;
    }
    finally {
      foreach ([$percorsoIngresso, $percorsoUscita] as $temporaneo) {
        if ($temporaneo !== FALSE && file_exists($temporaneo)) {
          @unlink($temporaneo);
        }
      }
    }
  }

  private function eseguibileGhostscript(): string {
    return trim((string) $this->configurazione->get('psiphos.settings')->get('conservazione.ghostscript'));
  }

}
