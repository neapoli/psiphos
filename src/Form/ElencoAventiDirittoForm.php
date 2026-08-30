<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\EventoAudit;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Nominativo;
use Drupal\psiphos\Service\RegistroAudit;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Composizione dell'elenco degli aventi diritto di una seduta.
 *
 * L'elenco è il denominatore di ogni quorum, e all'apertura della seduta
 * viene cristallizzato. Comporlo è quindi parte dell'atto di convocazione,
 * non un'operazione accessoria: da qui discendono la validità della
 * costituzione e le maggioranze con cui si delibera.
 */
final class ElencoAventiDirittoForm extends FormBase {

  private ?SedutaInterface $seduta = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly RegistroAudit $registro,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('psiphos.registro_audit'),
    );
  }

  public function getFormId(): string {
    return 'psiphos_elenco_aventi_diritto';
  }

  /**
   * Presidente e segretario designati che non figurano fra gli aventi diritto.
   *
   * @param array<int, \Drupal\psiphos\Entity\Presenza> $presenze
   *   L'elenco in essere.
   *
   * @return array<int, string>
   *   Le qualifiche mancanti, già formulate per l'avviso.
   */
  private function designatiFuoriElenco(array $presenze): array {
    if ($this->seduta === NULL) {
      return [];
    }

    $inElenco = [];
    foreach ($presenze as $presenza) {
      $inElenco[(int) ($presenza->get('utente')->target_id ?? 0)] = TRUE;
    }

    $mancanti = [];
    foreach ([
      'presidente' => (string) $this->t('Il Presidente designato'),
      'segretario' => (string) $this->t('il segretario verbalizzante designato'),
    ] as $campo => $qualifica) {
      $designato = (int) ($this->seduta->get($campo)->target_id ?? 0);
      if ($designato > 0 && !isset($inElenco[$designato])) {
        $mancanti[] = $qualifica;
      }
    }

    // Con il solo segretario mancante la frase comincerebbe in minuscolo.
    if ($mancanti !== [] && !isset($mancanti[1]) && str_starts_with($mancanti[0], 'il ')) {
      $mancanti[0] = 'Il ' . substr($mancanti[0], 3);
    }

    return $mancanti;
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?SedutaInterface $psiphos_seduta = NULL): array {
    $this->seduta = $psiphos_seduta ?? $this->seduta;
    if ($this->seduta === NULL) {
      return $form;
    }

    $modificabile = !$this->seduta->stato()->definitivo();

    $form['#cache']['max-age'] = 0;

    if ($this->seduta->stato() === StatoSeduta::APERTA) {
      $form['avvertenza'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [
          $this->t("La seduta è già aperta: il denominatore dei quorum è stato cristallizzato a @numero aventi diritto e non cambierà. Chi viene aggiunto ora potrà votare ma non sposterà le soglie di maggioranza.", [
            '@numero' => $this->seduta->aventiDirittoAllApertura() ?? 0,
          ]),
        ]],
      ];
    }

    $presenze = $this->presenze();

    // L'elenco è la fonte del diritto di voto, non un elenco di cortesia: chi
    // non vi figura non vota, presidente o segretario che sia. La separazione
    // è voluta — il segretario verbalizzante può essere un amministrativo che
    // non compone l'organo — ma nel Consiglio di classe il coordinatore che
    // presiede è quasi sempre anche docente della classe, e la dimenticanza
    // si scopre solo quando prova a votare e non può.
    $fuoriElenco = $this->designatiFuoriElenco($presenze);
    if ($fuoriElenco !== []) {
      $form['designati'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [
          $this->formatPlural(
            count($fuoriElenco),
            '@ruoli non figura fra gli aventi diritto: se ha diritto di voto in questo organo va aggiunto, altrimenti non potrà votare e non concorrerà ai quorum.',
            '@ruoli non figurano fra gli aventi diritto: se hanno diritto di voto in questo organo vanno aggiunti, altrimenti non potranno votare e non concorreranno ai quorum.',
            ['@ruoli' => implode(' e ', $fuoriElenco)]
          ),
        ]],
        '#weight' => -10,
      ];
    }

    $form['elenco'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Avente diritto'),
        $this->t('Posizione'),
        $this->t('Rimuovi'),
      ],
      '#empty' => $this->t("L'elenco è vuoto. Finché resta vuoto la seduta non può deliberare."),
    ];

    foreach ($presenze as $presenza) {
      $utente = $presenza->get('utente')->entity;
      $rimovibile = $modificabile && $presenza->stato() === StatoPresenza::ATTESO;

      $form['elenco'][$presenza->id()]['nome'] = [
        '#plain_text' => \Drupal\psiphos\Nominativo::perUtente($utente),
      ];
      // Un'utenza bloccata dopo essere stata inserita resta in elenco e
      // continua a pesare sul denominatore dei quorum, pur non potendo mai
      // essere presente: va segnalata perché sia tolta.
      $bloccata = $utente !== NULL && !$utente->isActive();
      $form['elenco'][$presenza->id()]['stato'] = [
        '#plain_text' => $bloccata
          ? $this->t('@stato — utenza bloccata', ['@stato' => $presenza->stato()->etichetta()])
          : $presenza->stato()->etichetta(),
      ];
      $form['elenco'][$presenza->id()]['rimuovi'] = $rimovibile
        ? [
          '#type' => 'checkbox',
          // L'etichetta resta visibile: il tema disegna la casella
          // attraverso di essa, e nasconderla lascia la cella vuota. Il
          // nominativo va invece nel nome accessibile, perché a chi naviga
          // con uno screen reader trenta caselle chiamate tutte «Rimuovi»
          // non direbbero chi si sta togliendo dall'elenco.
          '#title' => $this->t('Rimuovi'),
          '#attributes' => [
            'aria-label' => $this->t("Rimuovi @nome dall'elenco degli aventi diritto", [
              '@nome' => \Drupal\psiphos\Nominativo::perUtente($utente),
            ]),
          ],
        ]
        : [
          // Chi è già entrato in aula ha lasciato traccia nel registro
          // della seduta: toglierlo dall'elenco falserebbe il verbale.
          '#plain_text' => $modificabile ? $this->t('già in aula') : '',
        ];
    }

    if (!$modificabile) {
      return $form;
    }

    $form['aggiunta'] = [
      '#type' => 'details',
      '#title' => $this->t('Aggiungi aventi diritto'),
      '#open' => $presenze === [],
    ];

    $form['aggiunta']['utenti'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Singoli utenti'),
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#description' => $this->t("Separa i nominativi con una virgola. Compaiono le sole utenze attive: un'utenza bloccata non potrebbe accedere né entrare in aula."),
      '#selection_handler' => 'psiphos_utente_attivo',
      '#selection_settings' => ['include_anonymous' => FALSE],
    ];

    $form['aggiunta']['ruolo'] = [
      '#type' => 'select',
      '#title' => $this->t('Oppure tutti gli utenti con un ruolo'),
      '#options' => $this->ruoliDisponibili(),
      '#empty_option' => $this->t('- Nessuno -'),
      '#description' => $this->t('Gli utenti già in elenco non vengono duplicati e quelli bloccati sono esclusi.'),
    ];

    $form['azioni'] = ['#type' => 'actions'];
    $form['azioni']['salva'] = [
      '#type' => 'submit',
      '#value' => $this->t("Aggiorna l'elenco"),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->seduta === NULL) {
      return;
    }

    $rimossi = $this->rimuovi($form_state);
    $aggiunti = $this->aggiungi($form_state);

    if ($aggiunti > 0) {
      $this->messenger()->addStatus($this->formatPlural($aggiunti, 'Aggiunto 1 avente diritto.', 'Aggiunti @count aventi diritto.'));
    }
    if ($rimossi > 0) {
      $this->messenger()->addStatus($this->formatPlural($rimossi, 'Rimosso 1 avente diritto.', 'Rimossi @count aventi diritto.'));
    }
    if ($aggiunti === 0 && $rimossi === 0) {
      $this->messenger()->addWarning($this->t("Nessuna modifica all'elenco."));
      return;
    }

    $this->registro->annota(EventoAudit::ELENCO_MODIFICATO, (int) $this->seduta->id(), 0, [
      'aggiunti' => $aggiunti,
      'rimossi' => $rimossi,
      'totale' => $this->seduta->numeroAventiDiritto(),
    ]);

    $totale = $this->seduta->numeroAventiDiritto();
    $this->messenger()->addStatus($this->t("L'elenco conta @totale aventi diritto. Quorum costitutivo richiesto: @quorum presenti.", [
      '@totale' => $totale,
      '@quorum' => $this->seduta->quorumCostitutivo()->minimoPresenti($totale),
    ]));
  }

  /**
   * Rimuove dall'elenco le posizioni spuntate.
   */
  private function rimuovi(FormStateInterface $form_state): int {
    // Il valore di un elemento «table» non è necessariamente un array: privo
    // di un valore predefinito, il costruttore di form gli assegna la stringa
    // vuota, ed è quello che arriva qui quando l'elenco non ha righe.
    $righe = $form_state->getValue('elenco');

    if (!is_array($righe)) {
      return 0;
    }

    $daRimuovere = array_keys(array_filter(
      $righe,
      static fn (mixed $riga): bool => is_array($riga) && !empty($riga['rimuovi'])
    ));

    if ($daRimuovere === []) {
      return 0;
    }

    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');
    $presenze = array_filter(
      $archivio->loadMultiple($daRimuovere),
      static fn (Presenza $p): bool => $p->stato() === StatoPresenza::ATTESO
    );
    $archivio->delete($presenze);

    return count($presenze);
  }

  /**
   * Aggiunge all'elenco gli utenti indicati, senza duplicare i presenti.
   */
  private function aggiungi(FormStateInterface $form_state): int {
    $identificativi = [];
    $riferimenti = $form_state->getValue('utenti');

    foreach (is_array($riferimenti) ? $riferimenti : [] as $riferimento) {
      if (is_array($riferimento) && isset($riferimento['target_id'])) {
        $identificativi[] = (int) $riferimento['target_id'];
      }
    }

    $ruolo = $form_state->getValue('ruolo');
    if ($ruolo) {
      $archivioUtenti = $this->gestoreEntita->getStorage('user');
      $identificativi = array_merge($identificativi, array_map('intval', $archivioUtenti->getQuery()
        ->accessCheck(FALSE)
        ->condition('roles', $ruolo)
        ->condition('status', 1)
        ->execute()));
    }

    $identificativi = array_unique(array_filter($identificativi));
    $giaInElenco = array_map(
      static fn (Presenza $p): int => (int) $p->get('utente')->target_id,
      $this->presenze()
    );

    $daAggiungere = array_diff($identificativi, $giaInElenco);

    // Il filtro sullo stato si applica anche qui e non solo al selettore:
    // l'autocompletamento accetta un identificativo digitato a mano, e un
    // controllo che vive nella sola interfaccia non è un controllo.
    $attivi = $daAggiungere === []
      ? []
      : $this->gestoreEntita->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $daAggiungere, 'IN')
        ->condition('status', 1)
        ->execute();

    $scartati = count($daAggiungere) - count($attivi);
    if ($scartati > 0) {
      $this->messenger()->addWarning($this->formatPlural(
        $scartati,
        "Un'utenza bloccata non è stata aggiunta: non potrebbe accedere né entrare in aula.",
        '@count utenze bloccate non sono state aggiunte: non potrebbero accedere né entrare in aula.'
      ));
    }

    $aggiunti = 0;
    foreach ($attivi as $identificativo) {
      Presenza::create([
        'seduta' => $this->seduta->id(),
        'utente' => $identificativo,
      ])->save();
      $aggiunti++;
    }

    return $aggiunti;
  }

  /**
   * Elenco corrente, ordinato per identificativo.
   *
   * @return array<int, \Drupal\psiphos\Entity\Presenza>
   */
  private function presenze(): array {
    if ($this->seduta === NULL || $this->seduta->isNew()) {
      return [];
    }

    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $this->seduta->id())
      ->execute();

    $presenze = $archivio->loadMultiple($identificativi);

    // L'ordinamento è per cognome e nome, non per ordine di inserimento: su
    // un collegio di ottanta docenti l'elenco si consulta cercando una
    // persona, e la si cerca dal cognome.
    uasort($presenze, static fn (Presenza $a, Presenza $b): int => strcoll(
      Nominativo::perUtente($a->get('utente')->entity),
      Nominativo::perUtente($b->get('utente')->entity)
    ));

    return $presenze;
  }

  /**
   * @return array<string, string>
   */
  private function ruoliDisponibili(): array {
    $opzioni = [];
    foreach ($this->gestoreEntita->getStorage('user_role')->loadMultiple() as $ruolo) {
      if (!in_array($ruolo->id(), ['anonymous'], TRUE)) {
        $opzioni[$ruolo->id()] = $ruolo->label();
      }
    }
    return $opzioni;
  }

}
