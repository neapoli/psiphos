<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Form\ControlliPresidenzaForm;
use Drupal\psiphos\Form\VotoForm;
use Drupal\psiphos\Nominativo;
use Drupal\psiphos\Service\Aula;
use Drupal\psiphos\Service\Urna;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Aula virtuale: ingresso, permanenza, scheda di voto, banco di presidenza.
 */
final class AulaController extends ControllerBase {

  public const CONTENITORE = 'psiphos-aula';

  public function __construct(
    private readonly Aula $aula,
    private readonly Urna $urna,
    private readonly FormBuilderInterface $costruttoreForm,
    EntityTypeManagerInterface $gestoreEntita,
  ) {
    $this->entityTypeManager = $gestoreEntita;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psiphos.aula'),
      $container->get('psiphos.urna'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Pagina dell'aula.
   */
  public function aula(SedutaInterface $psiphos_seduta): array {
    $utente = $this->currentUser();
    $this->aula->decadiPresenzeScadute($psiphos_seduta);
    $this->aula->entra($psiphos_seduta, $utente);

    return [
      '#type' => 'container',
      '#attributes' => ['id' => self::CONTENITORE],
      '#attached' => [
        'library' => ['psiphos/aula'],
        'drupalSettings' => ['psiphos' => [
          'stato' => Url::fromRoute('psiphos.aula.stato', ['psiphos_seduta' => $psiphos_seduta->id()])->toString(),
          'contenuto' => Url::fromRoute('psiphos.aula.contenuto', ['psiphos_seduta' => $psiphos_seduta->id()])->toString(),
          'intervallo' => 5000,
        ]],
      ],
      'contenuto' => $this->contenutoAula($psiphos_seduta, $utente),
    ];
  }

  /**
   * Frammento dell'aula, per l'aggiornamento senza ricaricare la pagina.
   */
  public function contenuto(SedutaInterface $psiphos_seduta): AjaxResponse {
    $risposta = new AjaxResponse();
    $risposta->addCommand(new ReplaceCommand(
      '#' . self::CONTENITORE . ' > .psiphos-aula__contenuto',
      $this->contenutoAula($psiphos_seduta, $this->currentUser())
    ));

    return $risposta;
  }

  /**
   * Stato sintetico della seduta, interrogato periodicamente dal browser.
   *
   * L'interrogazione stessa è il segnale di presenza: dichiara che l'aula è
   * ancora aperta su quel dispositivo. Chi chiude la pagina smette di
   * inviarla, e la presenza decade da sé.
   */
  public function stato(SedutaInterface $psiphos_seduta, Request $richiesta): JsonResponse {
    $utente = $this->currentUser();

    $this->aula->rinnova($psiphos_seduta, $utente);
    $this->aula->decadiPresenzeScadute($psiphos_seduta);
    $psiphos_seduta = $this->entityTypeManager()->getStorage('psiphos_seduta')->loadUnchanged($psiphos_seduta->id());

    $inVotazione = $this->deliberaInVotazione($psiphos_seduta);
    $presenza = $this->aula->presenza($psiphos_seduta, $utente);

    return new JsonResponse([
      'seduta' => $psiphos_seduta->stato()->value,
      'presenti' => $psiphos_seduta->numeroPresenti(),
      'aventiDiritto' => $psiphos_seduta->aventiDirittoAllApertura() ?? $psiphos_seduta->numeroAventiDiritto(),
      'costituita' => $psiphos_seduta->validamenteCostituita(),
      'quorumEtichetta' => $this->aula->etichettaQuorum($psiphos_seduta),
      'quorumInDifetto' => $this->aula->quorumInDifetto($psiphos_seduta),
      'presenza' => $presenza?->stato()->value,
      'sessioneValida' => $presenza === NULL || !$this->aula->sessioneSuperata($presenza),
      'delibera' => $inVotazione?->id(),
      'votanti' => $inVotazione === NULL ? 0 : $this->urna->numeroVotanti($inVotazione),
      'presentiAlVoto' => $inVotazione === NULL ? 0 : (int) $inVotazione->get('presenti_al_voto')->value,
      'mancanti' => $inVotazione === NULL
        ? 0
        : max(0, ((int) $inVotazione->get('presenti_al_voto')->value) - $this->urna->numeroVotanti($inVotazione)),
      // La firma cambia quando cambia qualcosa che richiede di ridisegnare
      // l'aula. Le sole variazioni numeriche non la muovono: si aggiornano
      // in pagina senza ricostruire la scheda di voto sotto le mani di chi
      // la sta compilando.
      'firma' => $this->firma($psiphos_seduta, $inVotazione, $utente),
    ]);
  }

  /**
   * Titolo della pagina.
   */
  public function titolo(SedutaInterface $psiphos_seduta): string {
    return (string) $psiphos_seduta->label();
  }

  /**
   * Costruisce il contenuto dell'aula per l'utente corrente.
   */
  private function contenutoAula(SedutaInterface $seduta, AccountInterface $utente): array {
    $presenza = $this->aula->presenza($seduta, $utente);
    $inVotazione = $this->deliberaInVotazione($seduta);

    $contenuto = [
      '#type' => 'container',
      '#attributes' => ['class' => ['psiphos-aula__contenuto']],
      '#cache' => ['max-age' => 0],
    ];

    $contenuto['intestazione'] = [
      '#theme' => 'psiphos_stato_seduta',
      '#seduta' => $seduta,
      '#presenti' => $seduta->numeroPresenti(),
      '#aventi_diritto' => $seduta->aventiDirittoAllApertura() ?? $seduta->numeroAventiDiritto(),
      '#costituita' => $seduta->validamenteCostituita(),
      '#presenza' => $presenza?->stato(),
    ];

    // Il conflitto di sessione si segnala solo quando esiste davvero: chi non
    // è ancora accreditato non è in conflitto con nessuno.
    if ($presenza !== NULL && $this->aula->sessioneSuperata($presenza)) {
      $contenuto['sessione_superata'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [
          $this->t('Risulti collegato da un altro dispositivo. Per votare da qui ricarica la pagina: la sessione precedente verrà chiusa.'),
        ]],
      ];

      return $contenuto;
    }

    if ($this->puoPresiedere($seduta, $utente)) {
      $contenuto['presidenza'] = $this->costruttoreForm->getForm(ControlliPresidenzaForm::class, $seduta);
      $contenuto['appello'] = $this->appello($seduta);
    }

    if ($inVotazione !== NULL) {
      $contenuto['votazione'] = $this->riquadroVotazione($seduta, $inVotazione, $utente);
    }
    elseif ($seduta->stato() === StatoSeduta::APERTA) {
      $contenuto['attesa'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('Nessuna votazione in corso. La pagina si aggiorna da sola quando la presidenza mette ai voti un punto.') . '</p>',
      ];
    }
    elseif ($seduta->stato() === StatoSeduta::CONVOCATA) {
      $contenuto['non_aperta'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('La seduta non è ancora aperta. Resta su questa pagina: entrerai in aula da solo, non appena la presidenza dichiarerà aperti i lavori.') . '</p>',
      ];
    }
    else {
      $contenuto['conclusa'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('I lavori sono conclusi.') . '</p>',
      ];
    }

    return $contenuto;
  }

  /**
   * Registro delle presenze, per chi conduce la seduta.
   *
   * È l'appello: durante l'ingresso in aula chi presiede ha bisogno di sapere
   * chi manca, non solo quanti. L'ordinamento è per cognome, perché si
   * consulta cercando una persona.
   */
  private function appello(SedutaInterface $seduta): array {
    if ($seduta->isNew()) {
      return [];
    }

    $archivio = $this->entityTypeManager()->getStorage('psiphos_presenza');
    $presenze = $archivio->loadMultiple($archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->execute());

    if ($presenze === []) {
      return [];
    }

    $righe = [];
    foreach ($presenze as $presenza) {
      $righe[] = [
        'nominativo' => Nominativo::perUtente($presenza->get('utente')->entity),
        'stato' => (string) $presenza->stato()->etichetta(),
        'presente' => $presenza->concorreAlQuorum(),
      ];
    }

    usort($righe, static fn (array $a, array $b): int => strcoll($a['nominativo'], $b['nominativo']));

    return [
      '#type' => 'details',
      '#title' => $this->t('Appello — @presenti presenti su @totale', [
        '@presenti' => count(array_filter($righe, static fn (array $r): bool => $r['presente'])),
        '@totale' => count($righe),
      ]),
      '#open' => $seduta->stato() === StatoSeduta::APERTA,
      '#attributes' => ['class' => ['psiphos-appello']],
      'elenco' => [
        '#type' => 'table',
        '#header' => [$this->t('Avente diritto'), $this->t('Posizione')],
        '#rows' => array_map(
          static fn (array $r): array => [
            $r['nominativo'],
            ['data' => $r['stato'], 'class' => $r['presente'] ? ['psiphos-appello__presente'] : []],
          ],
          $righe
        ),
      ],
    ];
  }

  /**
   * Riquadro della votazione in corso.
   */
  private function riquadroVotazione(SedutaInterface $seduta, DeliberaInterface $delibera, AccountInterface $utente): array {
    if ($delibera->stato() === StatoDelibera::SOSPESA) {
      return [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('La votazione su «@quesito» è sospesa. Le schede già depositate restano nell\'urna.', [
          '@quesito' => $delibera->label(),
        ]) . '</p>',
      ];
    }

    if (!$this->aula->abilitatoAlVoto($seduta, $utente)) {
      return [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('Votazione in corso su «@quesito». Non risulti fra i presenti abilitati al voto.', [
          '@quesito' => $delibera->label(),
        ]) . '</p>',
      ];
    }

    if ($this->urna->haVotato($delibera, $utente)) {
      return [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('Hai già votato su «@quesito». In attesa che la presidenza chiuda la votazione.', [
          '@quesito' => $delibera->label(),
        ]) . '</p>',
      ];
    }

    return $this->costruttoreForm->getForm(VotoForm::class, $delibera, $utente);
  }

  /**
   * Delibera attualmente in votazione o sospesa, se ce n'è una.
   */
  private function deliberaInVotazione(SedutaInterface $seduta): ?DeliberaInterface {
    if ($seduta->isNew()) {
      return NULL;
    }

    $archivio = $this->entityTypeManager()->getStorage('psiphos_delibera');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('stato', [StatoDelibera::IN_VOTAZIONE->value, StatoDelibera::SOSPESA->value], 'IN')
      ->sort('id')
      ->range(0, 1)
      ->execute();

    $delibera = $identificativi === [] ? NULL : $archivio->load(reset($identificativi));

    return $delibera instanceof DeliberaInterface ? $delibera : NULL;
  }

  private function puoPresiedere(SedutaInterface $seduta, AccountInterface $utente): bool {
    if ($utente->hasPermission('administer psiphos')) {
      return TRUE;
    }

    return (int) ($seduta->get('presidente')->target_id ?? 0) === (int) $utente->id()
      && $utente->hasPermission('psiphos presiedere seduta');
  }

  /**
   * Firma dello stato che richiede di ridisegnare l'aula.
   */
  private function firma(SedutaInterface $seduta, ?DeliberaInterface $delibera, AccountInterface $utente): string {
    $presenza = $this->aula->presenza($seduta, $utente);

    $elementi = [
      $seduta->stato()->value,
      $delibera?->id() ?? '-',
      $delibera?->stato()->value ?? '-',
      $presenza?->stato()->value ?? '-',
      $presenza !== NULL && $this->aula->sessioneSuperata($presenza) ? 'sessione-superata' : 'sessione-valida',
      $delibera !== NULL && $this->urna->haVotato($delibera, $utente) ? 'votato' : 'non-votato',
    ];

    // Il raggiungimento del quorum cambia i comandi disponibili al banco di
    // presidenza: senza il pulsante «metti ai voti» il presidente resterebbe
    // fermo ad aspettare un aggiornamento che non arriva. Per chi non
    // presiede il quorum non cambia invece nulla di ciò che può fare, e
    // ridisegnare l'aula gli azzererebbe la scheda che sta compilando.
    if ($this->puoPresiedere($seduta, $utente)) {
      $elementi[] = $seduta->validamenteCostituita() ? 'costituita' : 'non-costituita';

      // Durante l'appello chi presiede deve vedere l'elenco aggiornarsi da
      // sé. A urna aperta no: ridisegnare l'aula a ogni ingresso o uscita
      // azzererebbe la scheda che il presidente stesse compilando, e in quel
      // momento ciò che gli serve — le schede depositate — si aggiorna già
      // in pagina senza ricostruire nulla.
      if ($delibera === NULL || !$delibera->urnaAperta()) {
        $elementi[] = $this->improntaPresenze($seduta);
      }
    }

    return implode('|', $elementi);
  }

  /**
   * Impronta compatta dello stato delle presenze.
   */
  private function improntaPresenze(SedutaInterface $seduta): string {
    if ($seduta->isNew()) {
      return 'senza-elenco';
    }

    $stati = $this->entityTypeManager()->getStorage('psiphos_presenza')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->groupBy('stato')
      ->aggregate('id', 'COUNT')
      ->execute();

    $compatta = [];
    foreach ($stati as $riga) {
      $compatta[] = $riga['stato'] . ':' . $riga['id_count'];
    }

    sort($compatta);

    return implode(',', $compatta);
  }

}
