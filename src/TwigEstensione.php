<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtri Twig per la resa delle date nel verbale.
 *
 * La struttura canonica conserva gli istanti in ISO 8601, formato adatto a
 * essere confrontato e conservato ma non a essere letto. La conversione in
 * forma leggibile avviene qui, in fase di resa, così il dato archiviato
 * resta indipendente dalle convenzioni di visualizzazione.
 */
final class TwigEstensione extends AbstractExtension {

  public function __construct(private readonly DateFormatterInterface $formattatore) {}

  public function getFilters(): array {
    return [
      new TwigFilter('psiphos_istante', [$this, 'istante']),
      new TwigFilter('psiphos_giorno', [$this, 'giorno']),
      new TwigFilter('psiphos_marca', [$this, 'marca']),
      new TwigFilter('psiphos_testo', [$this, 'testoFormattato'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Formatta un istante ISO 8601.
   */
  public function istante(?string $iso): string {
    if ($iso === NULL || trim($iso) === '') {
      return '—';
    }

    $momento = strtotime($iso);

    return $momento === FALSE ? '—' : $this->formattatore->format($momento, 'custom', 'd/m/Y H:i');
  }

  /**
   * Formatta la sola data di un istante ISO 8601.
   *
   * Gli atti si datano al giorno: «Delibera n. 35 – 30/06/2026». L'ora della
   * chiusura dell'urna resta nel verbale e nelle tracciature, dove serve a
   * ricostruire lo svolgimento; nell'intestazione di un atto è rumore.
   */
  public function giorno(?string $iso): string {
    if ($iso === NULL || trim($iso) === '') {
      return '—';
    }

    $momento = strtotime($iso);

    return $momento === FALSE ? '—' : $this->formattatore->format($momento, 'custom', 'd/m/Y');
  }

  /**
   * Rende un testo redatto con un formato di testo.
   *
   * Il valore conservato è quello scritto dall'autore, marcatori compresi:
   * stamparlo tale e quale lo mostrerebbe con i tag in chiaro, e stamparlo
   * senza filtri aprirebbe la porta a contenuti arbitrari. Passa perciò dal
   * formato con cui è stato redatto, che è l'unico a sapere che cosa è
   * lecito in quel campo.
   */
  public function testoFormattato(?string $valore, ?string $formato = NULL): MarkupInterface|string {
    $valore = trim((string) $valore);

    if ($valore === '') {
      return '';
    }

    $formato = trim((string) $formato);

    if ($formato === '') {
      // Senza formato dichiarato il testo è trattato come tale: gli a capo
      // diventano interruzioni di riga e nient'altro passa.
      return Markup::create(nl2br(htmlspecialchars($valore, ENT_QUOTES, 'UTF-8')));
    }

    return Markup::create((string) check_markup($valore, $formato));
  }

  /**
   * Formatta una marca temporale unix.
   */
  public function marca(mixed $marca): string {
    return $marca === NULL || $marca === ''
      ? '—'
      : $this->formattatore->format((int) $marca, 'custom', 'd/m/Y H:i');
  }

}
