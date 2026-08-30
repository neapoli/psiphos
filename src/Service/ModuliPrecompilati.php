<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Atti e richieste precompilati con i dati che il sito già conosce.
 *
 * I documenti di accompagnamento contengono modelli di lettera e di atto, ma
 * scaricarli significava ricevere la guida che li contiene: per spedirli
 * bisognava aprire il file, ritagliare il riquadro, togliere i marcatori di
 * citazione e riempire a mano dati che il sito conosce già — denominazione
 * dell'istituto, indirizzo, codice fiscale, PEC, dominio, fornitore.
 *
 * Qui quei modelli si producono compilati. Restano in bianco soltanto i dati
 * che il sito non può conoscere: il nome del destinatario, il luogo e la data,
 * la firma.
 */
final class ModuliPrecompilati {

  /**
   * Modelli disponibili, raccolti sotto il documento che li descrive.
   *
   * Un documento può contenerne più d'uno: la guida sul fornitore
   * dell'infrastruttura porta con sé la richiesta di documentazione e l'atto
   * di nomina che ne consegue, e sono due atti distinti con due destini
   * distinti — il primo si spedisce, il secondo si sottoscrive.
   */
  private const MODELLI = [
    'richieste-al-fornitore-hosting' => [
      'richiesta' => [
        'tema' => 'psiphos_modello_hosting',
        'titolo' => 'Richiesta al fornitore dell\'infrastruttura',
        'file' => 'richiesta-fornitore-hosting',
      ],
      'nomina' => [
        'tema' => 'psiphos_modello_nomina_hosting',
        'titolo' => 'Atto di nomina del fornitore dell\'infrastruttura',
        'file' => 'nomina-responsabile-hosting',
      ],
    ],
    'nomina-del-manutentore' => [
      'nomina' => [
        'tema' => 'psiphos_modello_nomina',
        'titolo' => 'Atto di nomina a responsabile del trattamento',
        'file' => 'nomina-responsabile-manutentore',
      ],
    ],
    'regolamento-articolo' => [
      'articolo' => [
        'tema' => 'psiphos_modello_regolamento',
        'titolo' => 'Articolo per il Regolamento d\'istituto',
        'file' => 'articolo-regolamento-istituto',
      ],
    ],
    'dpia-elementi' => [
      'descrizione' => [
        'tema' => 'psiphos_modello_dpia',
        'titolo' => 'Descrizione tecnica del trattamento',
        'file' => 'descrizione-tecnica-trattamento',
      ],
    ],
    'registro-art-30' => [
      'scheda' => [
        'tema' => 'psiphos_modello_registro',
        'titolo' => 'Scheda per il registro delle attività di trattamento',
        'file' => 'registro-attivita-trattamento',
      ],
    ],
    'conservazione-a-norma' => [
      'richiesta' => [
        'tema' => 'psiphos_modello_conservazione',
        'titolo' => 'Richiesta al fornitore del protocollo informatico',
        'file' => 'richiesta-fornitore-protocollo',
      ],
    ],
  ];

  public function __construct(
    private readonly IntestazioneIstituto $intestazione,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly ConservazioneDocumento $conservazione,
    private readonly AttestazioneConformita $attestazione,
    private readonly RendererInterface $renderizzatore,
    private readonly RequestStack $richieste,
  ) {}

  /**
   * Vero se il documento ha un modello precompilabile.
   */
  public function disponibile(string $documento, ?string $modello = NULL): bool {
    return $this->definizione($documento, $modello) !== NULL;
  }

  /**
   * Identificativi dei modelli del documento, nell'ordine in cui si usano.
   *
   * @return array<int, string>
   */
  public function modelli(string $documento): array {
    return array_keys(self::MODELLI[$documento] ?? []);
  }

  /**
   * Titolo del modello, per il collegamento che lo offre.
   */
  public function titolo(string $documento, ?string $modello = NULL): string {
    return $this->definizione($documento, $modello)['titolo'] ?? '';
  }

  /**
   * Nome del file scaricato.
   */
  public function nomeFile(string $documento, ?string $modello = NULL): string {
    return sprintf('%s.pdf', $this->definizione($documento, $modello)['file'] ?? 'modello');
  }

  /**
   * Il modello in PDF, compilato con quanto il sito conosce.
   *
   * @return array{contenuto: string, formato: string, impronta: string}
   */
  public function produci(string $documento, ?string $modello = NULL): array {
    $definizione = $this->definizione($documento, $modello);
    if ($definizione === NULL) {
      throw new \InvalidArgumentException(sprintf('Nessun modello per %s.', $documento));
    }

    $costruzione = [
      '#theme' => $definizione['tema'],
      '#istituto' => $this->intestazione->dati(),
      '#fornitore' => $this->fornitore(),
      '#hosting' => $this->hosting(),
      '#dominio' => $this->dominio(),
      '#configurazione' => $this->configurazioneInEssere(),
      '#cache' => ['max-age' => 0],
    ];

    return $this->conservazione->produci($this->incornicia(
      $definizione['titolo'],
      (string) $this->renderizzatore->renderInIsolation($costruzione)
    ));
  }

  /**
   * Il modello richiesto, o il primo del documento se non se ne indica alcuno.
   *
   * @return array{tema: string, titolo: string, file: string}|null
   */
  private function definizione(string $documento, ?string $modello): ?array {
    $modelli = self::MODELLI[$documento] ?? [];
    $modello ??= array_key_first($modelli);

    return $modello === NULL ? NULL : ($modelli[$modello] ?? NULL);
  }

  /**
   * Chi ospita il sito, e con quali atti lo si è documentato.
   *
   * Identità e riferimenti agli atti sono cose diverse dalle misure di
   * sicurezza: le prime la scuola le conosce, le seconde le può soltanto
   * riferire. Qui si porta l'una e l'altra cosa, ma tenute distinte, perché
   * un atto che presentasse per verificato ciò che è stato dichiarato
   * varrebbe meno di uno che tace.
   *
   * @return array<string, string>
   */
  private function hosting(): array {
    $impostazioni = $this->configurazione->get('psiphos.settings');

    return [
      'denominazione' => (string) $impostazioni->get('hosting.denominazione'),
      'partita_iva' => (string) $impostazioni->get('hosting.partita_iva'),
      'sede' => (string) $impostazioni->get('hosting.sede'),
      'contatto' => (string) $impostazioni->get('hosting.contatto'),
      'ubicazione_dati' => (string) $impostazioni->get('hosting.ubicazione_dati'),
      'nomina_protocollo' => (string) $impostazioni->get('hosting.nomina_protocollo'),
      'nomina_data' => (string) $impostazioni->get('hosting.nomina_data'),
      'riscontro_protocollo' => (string) $impostazioni->get('hosting.riscontro_protocollo'),
      'riscontro_data' => (string) $impostazioni->get('hosting.riscontro_data'),
    ];
  }

  /**
   * @return array<string, string>
   */
  private function fornitore(): array {
    $impostazioni = $this->configurazione->get('psiphos.settings');

    return [
      'denominazione' => (string) $impostazioni->get('fornitore.denominazione'),
      'partita_iva' => (string) $impostazioni->get('fornitore.partita_iva'),
      'contatto' => (string) $impostazioni->get('fornitore.contatto'),
    ];
  }

  /**
   * Configurazione in essere, nella forma in cui un atto la cita.
   *
   * L'articolo di Regolamento contiene valori che devono corrispondere a quelli
   * configurati: la tolleranza di collegamento, la conservazione delle
   * tracciature, il livello di autenticazione, la sessione esclusiva. Lasciarli
   * trascrivere a mano è il punto in cui il Regolamento comincia a promettere
   * ciò che il sistema non fa — e nessuno se ne accorge finché non lo si
   * contesta.
   *
   * @return array<string, mixed>
   */
  private function configurazioneInEssere(): array {
    $impostazioni = $this->configurazione->get('psiphos.settings');
    $giorni = (int) $impostazioni->get('audit.ritenzione_giorni');

    return [
      'minuti' => (int) round(((int) $impostazioni->get('sessione.timeout_inattivita')) / 60),
      'sessione_esclusiva' => (bool) $impostazioni->get('sessione.sessione_esclusiva'),
      'livello' => (string) $impostazioni->get('autenticazione.livello'),
      'provider_forte' => (string) $impostazioni->get('autenticazione.provider_forte'),
      'ritenzione_giorni' => $giorni,
      // Dieci anni si scrivono «dieci anni», non «3650 giorni»: è un atto, e
      // un atto si legge ad alta voce in Consiglio d'istituto.
      'ritenzione' => $giorni > 0 && $giorni % 365 === 0
        ? intdiv($giorni, 365)
        : 0,
      // La descrizione tecnica per la valutazione d'impatto deve riferire il
      // livello di autenticazione *erogato*, non quello dichiarato: è la
      // differenza su cui il §3.2 dell'attestazione apre un rilievo, e una
      // descrizione che la ignorasse falserebbe la ponderazione del rischio.
      'autenticazione' => $this->attestazione->statoAutenticazione(
        (string) $impostazioni->get('autenticazione.livello'),
        (string) $impostazioni->get('autenticazione.provider_forte')
      ),
      'conservazione_disponibile' => $this->conservazione->conservazioneDisponibile(),
    ];
  }

  private function dominio(): string {
    $richiesta = $this->richieste->getCurrentRequest();

    return $richiesta === NULL ? '' : $richiesta->getHost();
  }

  /**
   * Cornice del documento.
   *
   * Foglio di stile essenziale e in unità assolute: sono atti destinati a
   * essere stampati, sottoscritti e protocollati.
   */
  private function incornicia(string $titolo, string $corpo): string {
    $stile = <<<'CSS'
      @page { size: A4; margin: 13mm 20mm 15mm; }
      body { font-family: DejaVu Serif, serif; font-size: 10.5pt; line-height: 1.45; color: #000; }
      h1 { font-size: 12pt; margin: 0 0 5mm; text-align: center; }
      h2 { font-size: 10.5pt; margin: 6mm 0 2mm; page-break-after: avoid; }
      p { margin: 0 0 3mm; text-align: justify; }
      ol { margin: 0 0 4mm; padding-left: 6mm; }
      li { margin: 0 0 2.5mm; text-align: justify; }
      .carta { font-size: 8.5pt; line-height: 1.35; text-align: center; border-bottom: 1pt solid #000; padding-bottom: 3mm; margin: 0 0 6mm; }
      .carta p { margin: 0; text-align: center; }
      .carta .ente { font-size: 11pt; font-weight: bold; margin: 0 0 1mm; }
      .destinatario { margin: 0 0 6mm; text-align: right; }
      .destinatario p { margin: 0; text-align: right; }
      .oggetto { font-weight: bold; margin: 0 0 5mm; }
      .firma { margin-top: 4mm; }
      .firma p { margin: 0 0 5mm; }
      .nota { font-size: 8.5pt; }
      .vuoto { letter-spacing: 0.6pt; }
      CSS;

    return sprintf(
      '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>%s</title><style>%s</style></head><body>%s</body></html>',
      htmlspecialchars($titolo, ENT_QUOTES, 'UTF-8'),
      $stile,
      $corpo
    );
  }

}
