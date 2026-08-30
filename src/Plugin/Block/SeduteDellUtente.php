<?php

declare(strict_types=1);

namespace Drupal\psiphos\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Le sedute collegiali dell'utente, per la scrivania del personale.
 *
 * Finché l'ingresso in aula passava da un collegamento che il Presidente
 * doveva far avere a ciascuno, «non ho ricevuto il collegamento» restava
 * un'obiezione sulla regolarità della seduta, difficile da smentire. Qui
 * l'aula si raggiunge dal sito istituzionale, autenticati, e l'accesso
 * risulta disponibile a chiunque sia negli aventi diritto.
 *
 * Il blocco non sostituisce la convocazione, che resta l'atto formale con i
 * propri termini: è una scorciatoia per entrare, non il mezzo con cui si
 * convoca.
 *
 * @Block(
 *   id = "psiphos_sedute",
 *   admin_label = @Translation("Psíphos — le mie sedute collegiali"),
 *   category = @Translation("Psíphos")
 * )
 */
final class SeduteDellUtente extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly AccountInterface $utente,
    private readonly DateFormatterInterface $formattatoreData,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      // Le sedute concluse restano in vista finché il verbale è recente: chi
      // vi ha partecipato lo cerca nei giorni successivi, poi non più.
      'giorni_concluse' => 30,
      // Una scrivania non è un archivio. Con trenta sedute in elenco il
      // blocco smette di dire che cosa fare adesso, che è l'unica ragione
      // per cui sta su una scrivania.
      'voci_massime' => 5,
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, FormStateInterface $form_state): array {
    $form['giorni_concluse'] = [
      '#type' => 'number',
      '#title' => $this->t('Mostrare le sedute concluse per'),
      '#field_suffix' => $this->t('giorni'),
      '#default_value' => $this->configuration['giorni_concluse'],
      '#min' => 0,
      '#max' => 365,
      '#description' => $this->t('Trascorso questo periodo la seduta conclusa esce dal blocco. Il verbale resta consultabile dalla seduta. Zero per non mostrare affatto le sedute concluse.'),
    ];

    $form['voci_massime'] = [
      '#type' => 'number',
      '#title' => $this->t('Numero massimo di sedute mostrate'),
      '#default_value' => $this->configuration['voci_massime'],
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('Le sedute aperte e quelle convocate hanno la precedenza sulle concluse. Oltre questo numero il blocco avverte quante ne restano.'),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['giorni_concluse'] = (int) $form_state->getValue('giorni_concluse');
    $this->configuration['voci_massime'] = max(1, (int) $form_state->getValue('voci_massime'));
  }

  /**
   * Il blocco si mostra a chi partecipa alle sedute, non a chiunque.
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'psiphos partecipare seduta')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'psiphos presiedere seduta'))
      ->orIf(AccessResult::allowedIfHasPermission($account, 'psiphos verbalizzare'));
  }

  public function build(): array {
    $sedute = $this->sedute();
    $creazione = $this->creazione();

    // Una scrivania piena di riquadri vuoti smette di essere letta: senza
    // sedute il blocco non si stampa. Fa eccezione chi può convocarne una:
    // per lui il riquadro vuoto non è un ingombro ma il punto da cui si
    // comincia, ed è dalla dashboard che il personale parte.
    if ($sedute === [] && $creazione === NULL) {
      return [];
    }

    $massime = max(1, (int) $this->configuration['voci_massime']);
    $eccedenti = max(0, count($sedute) - $massime);

    return [
      '#theme' => 'psiphos_blocco_sedute',
      '#sedute' => array_slice($sedute, 0, $massime),
      '#eccedenti' => $eccedenti,
      '#creazione' => $creazione,
      // L'avviso delle eccedenti sarebbe un vicolo cieco senza il proprio
      // archivio: l'elenco amministrativo è di chi convoca, e un docente non
      // vi accede.
      '#archivio' => $this->archivio(),
      '#attached' => ['library' => ['psiphos/verbale']],
      // Lo stato cambia di minuto in minuto — una seduta si apre, una
      // votazione parte — e un blocco memorizzato mostrerebbe una situazione
      // di dieci minuti prima proprio quando conta esserci.
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
    ];
  }

  /**
   * La convocazione di una seduta nuova, se chi guarda può farla.
   */
  private function creazione(): ?Url {
    if (!$this->gestoreEntita->getAccessControlHandler('psiphos_seduta')
      ->createAccess('psiphos_seduta', $this->utente)) {
      return NULL;
    }

    $indirizzo = Url::fromRoute('entity.psiphos_seduta.add_form');

    return $indirizzo->access($this->utente) ? $indirizzo : NULL;
  }

  /**
   * L'archivio personale, se chi guarda può raggiungerlo.
   */
  private function archivio(): ?Url {
    $indirizzo = Url::fromRoute('psiphos.archivio_sedute');

    return $indirizzo->access($this->utente) ? $indirizzo : NULL;
  }

  /**
   * Le sedute in cui l'utente è iscritto fra gli aventi diritto.
   *
   * @return array<int, array<string, mixed>>
   */
  private function sedute(): array {
    $mio = (int) $this->utente->id();
    $deposito = $this->gestoreEntita->getStorage('psiphos_presenza');
    $identificativi = $deposito->getQuery()
      ->accessCheck(FALSE)
      ->condition('utente', $mio)
      ->execute();

    // Si raccolgono gli identificativi e si caricano le sedute dal loro
    // deposito, invece di seguire il riferimento della presenza: quel
    // riferimento porta con sé l'entità come era quando la presenza è stata
    // caricata, e una seduta aperta nel frattempo continuerebbe a risultare
    // convocata — proprio nel momento in cui il bottone serve.
    $riferimenti = [];
    foreach ($deposito->loadMultiple($identificativi) as $presenza) {
      $riferimento = (int) ($presenza->get('seduta')->target_id ?? 0);
      if ($riferimento > 0) {
        $riferimenti[$riferimento] = $riferimento;
      }
    }

    // Non solo l'elenco degli aventi diritto: anche ciò che si presiede, si
    // verbalizza o si è convocato. Il presidente che non figura in elenco —
    // perché in quell'organo non ha diritto di voto — deve comunque poter
    // aprire la seduta dalla propria scrivania, e chi la convoca deve
    // ritrovarla dove ritrova le altre.
    $archivio = $this->gestoreEntita->getStorage('psiphos_seduta');
    $perRuolo = $archivio->getQuery()->accessCheck(FALSE);
    $perRuolo->condition($perRuolo->orConditionGroup()
      ->condition('presidente', $mio)
      ->condition('segretario', $mio)
      ->condition('uid', $mio));
    foreach ($perRuolo->execute() as $identificativo) {
      $riferimenti[(int) $identificativo] = (int) $identificativo;
    }

    if ($riferimenti === []) {
      return [];
    }

    $sedute = [];
    foreach ($archivio->loadMultiple($riferimenti) as $seduta) {
      if ($seduta instanceof SedutaInterface && $this->daMostrare($seduta)) {
        $sedute[(int) $seduta->id()] = $seduta;
      }
    }

    // La più imminente per prima: chi guarda la scrivania cerca che cosa deve
    // fare adesso, non l'archivio.
    uasort($sedute, static fn (SedutaInterface $a, SedutaInterface $b): int =>
      ((int) $b->get('data_seduta')->value) <=> ((int) $a->get('data_seduta')->value));

    // Tre scaglioni, nell'ordine in cui interessano: ciò che è in corso, ciò
    // che sta per accadere, ciò che è concluso. Dentro ciascuno vale la data.
    $aperte = $convocate = $concluse = [];
    foreach ($sedute as $seduta) {
      $voce = $this->voce($seduta);
      match ($seduta->stato()) {
        StatoSeduta::APERTA => $aperte[] = $voce,
        StatoSeduta::CONVOCATA => $convocate[] = $voce,
        default => $concluse[] = $voce,
      };
    }

    // Le convocate si leggono dalla più vicina alla più lontana: la prossima
    // riunione viene prima di quella fra tre settimane.
    $convocate = array_reverse($convocate);

    // Ogni voce dichiara che cos'è. Una data, un titolo e la parola
    // «convocata» accostate non dicono a che cosa si riferiscano: senza
    // un'intestazione e senza etichette il blocco va interpretato, e nessuno
    // interpreta una scrivania.
    foreach ($aperte as $posizione => $voce) {
      $aperte[$posizione]['intestazione'] = $this->t('Seduta in corso');
    }
    foreach ($convocate as $posizione => $voce) {
      $convocate[$posizione]['intestazione'] = $posizione === 0
        ? $this->t('Prossima seduta')
        : $this->t('Seduta convocata');
    }
    foreach ($concluse as $posizione => $voce) {
      $concluse[$posizione]['intestazione'] = $this->t('Seduta conclusa');
    }

    return array_merge($aperte, $convocate, $concluse);
  }

  /**
   * Vero se la seduta merita un posto sulla scrivania.
   */
  private function daMostrare(SedutaInterface $seduta): bool {
    $stato = $seduta->stato();

    if ($stato === StatoSeduta::ANNULLATA) {
      return FALSE;
    }

    if (in_array($stato, [StatoSeduta::CONVOCATA, StatoSeduta::APERTA], TRUE)) {
      return TRUE;
    }

    $giorni = (int) $this->configuration['giorni_concluse'];
    if ($giorni <= 0) {
      return FALSE;
    }

    $riferimento = (int) ($seduta->get('chiusa_il')->value ?: $seduta->get('data_seduta')->value);

    return $riferimento > 0 && (\Drupal::time()->getRequestTime() - $riferimento) <= $giorni * 86400;
  }

  /**
   * Una seduta, nella forma in cui la scrivania la mostra.
   *
   * @return array<string, mixed>
   */
  private function voce(SedutaInterface $seduta): array {
    $stato = $seduta->stato();
    $identificativo = (int) $this->utente->id();

    return [
      'seduta' => $seduta,
      'titolo' => $seduta->label(),
      'organo' => $seduta->organo()->etichetta(),
      'stato' => $stato->value,
      'stato_etichetta' => $stato->etichetta(),
      'quando' => $this->quando($seduta, 'l j F Y, H:i'),
      'ruolo' => match (TRUE) {
        (int) ($seduta->get('presidente')->target_id ?? 0) === $identificativo => $this->t('Presiedi questa seduta'),
        (int) ($seduta->get('segretario')->target_id ?? 0) === $identificativo => $this->t('Verbalizzi questa seduta'),
        (int) ($seduta->get('uid')->target_id ?? 0) === $identificativo => $this->t('Hai convocato questa seduta'),
        default => '',
      },
      // La seduta in corso è l'unica su cui si debba agire adesso, e si
      // distingue per questo; le concluse stanno più basse di tono. La
      // struttura però è la stessa per tutte, perché è la struttura a rendere
      // leggibile ciò che si legge.
      'in_rilievo' => $stato === StatoSeduta::APERTA,
      'conclusa' => in_array($stato, [StatoSeduta::CHIUSA, StatoSeduta::VERBALIZZATA], TRUE),
      'in_votazione' => $stato === StatoSeduta::APERTA && $this->votazioneInCorso($seduta),
      'aula' => $stato === StatoSeduta::APERTA
        ? Url::fromRoute('psiphos.aula', ['psiphos_seduta' => $seduta->id()])
        : NULL,
      'convocazione' => $seduta->toUrl(),
      // Il modulo non eroga l'audio-video e non intende sostituirlo: senza
      // questo collegamento accanto, chi entra in aula non sente nessuno e
      // telefona in segreteria.
      'video' => $this->collegamentoVideo($seduta),
      'verbale' => $this->collegamentoVerbale($seduta),
    ];
  }

  private function quando(SedutaInterface $seduta, string $formato): string {
    $momento = (int) $seduta->get('data_seduta')->value;

    return $momento > 0
      ? $this->formattatoreData->format($momento, 'custom', $formato)
      : '';
  }

  private function votazioneInCorso(SedutaInterface $seduta): bool {
    $trovate = $this->gestoreEntita->getStorage('psiphos_delibera')->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('stato', StatoDelibera::IN_VOTAZIONE->value)
      ->range(0, 1)
      ->count()
      ->execute();

    return (int) $trovate > 0;
  }

  private function collegamentoVideo(SedutaInterface $seduta): ?Url {
    // A seduta conclusa la stanza non ospita più nulla: offrirne il
    // collegamento manda qualcuno in una videoconferenza vuota.
    if (!in_array($seduta->stato(), [StatoSeduta::CONVOCATA, StatoSeduta::APERTA], TRUE)) {
      return NULL;
    }

    $indirizzo = trim((string) $seduta->get('url_videoconferenza')->value);

    if ($indirizzo === '') {
      return NULL;
    }

    try {
      return Url::fromUri($indirizzo);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Il verbale, se esiste e se chi guarda può vederlo.
   */
  private function collegamentoVerbale(SedutaInterface $seduta): ?Url {
    if (!in_array($seduta->stato(), [StatoSeduta::CHIUSA, StatoSeduta::VERBALIZZATA], TRUE)) {
      return NULL;
    }

    $indirizzo = Url::fromRoute('psiphos.seduta.verbale', ['psiphos_seduta' => $seduta->id()]);

    return $indirizzo->access($this->utente) ? $indirizzo : NULL;
  }

}
