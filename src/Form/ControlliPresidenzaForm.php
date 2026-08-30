<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Service\Aula;
use Drupal\psiphos\Service\Scrutinio;
use Drupal\psiphos\Service\Urna;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Banco di presidenza: conduzione della seduta e delle operazioni di voto.
 *
 * Le azioni disponibili derivano dalla macchina a stati e non da condizioni
 * ricostruite qui: un pulsante compare se e solo se la transizione
 * corrispondente è ammessa. Interfaccia e regole restano così una cosa sola,
 * e non è possibile che la prima offra ciò che le seconde vietano.
 */
final class ControlliPresidenzaForm extends FormBase {

  private ?SedutaInterface $seduta = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly Aula $aula,
    private readonly Scrutinio $scrutinio,
    private readonly Urna $urna,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('psiphos.aula'),
      $container->get('psiphos.scrutinio'),
      $container->get('psiphos.urna'),
    );
  }

  public function getFormId(): string {
    return 'psiphos_controlli_presidenza';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?SedutaInterface $seduta = NULL): array {
    $this->seduta = $seduta ?? $this->seduta;
    if ($this->seduta === NULL) {
      return $form;
    }

    $stato = $this->seduta->stato();
    $costituita = $this->seduta->validamenteCostituita();

    $form['#attributes']['class'][] = 'psiphos-presidenza';
    $form['#cache']['max-age'] = 0;

    // Come per la scheda di voto: il banco di presidenza è ricostruito anche
    // dentro il frammento di aggiornamento, e l'invio va indirizzato alla
    // pagina dell'aula e non al frammento.
    $form['#action'] = Url::fromRoute('psiphos.aula', ['psiphos_seduta' => $this->seduta->id()])->toString();

    $form['seduta'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Conduzione della seduta'),
    ];

    if ($stato->ammetteTransizioneA(StatoSeduta::APERTA)) {
      $aventiDiritto = $this->seduta->numeroAventiDiritto();

      $form['seduta']['nota_apertura'] = [
        '#type' => 'item',
        '#markup' => $aventiDiritto === 0
          ? $this->t("L'elenco degli aventi diritto è vuoto. Componilo prima di aprire la seduta: in aula si entra solo se vi si figura, e il denominatore dei quorum si congela all'apertura.")
          : $this->t("All'apertura si abilita l'ingresso in aula dei @numero aventi diritto in elenco. Il quorum costitutivo si verifica dopo l'appello, prima di mettere ai voti il primo punto.", [
            '@numero' => $aventiDiritto,
          ]),
      ];

      if ($aventiDiritto > 0) {
        $form['seduta']['apri'] = [
          '#type' => 'submit',
          '#name' => 'apri_seduta',
          '#value' => $this->t('Dichiara aperta la seduta'),
          '#button_type' => 'primary',
        ];
      }
    }

    if ($stato === StatoSeduta::APERTA) {
      $form['seduta']['quorum'] = [
        '#type' => 'item',
        '#markup' => $this->t(
          'Quorum costitutivo <span data-psiphos="quorum">@stato</span>: <span data-psiphos="presenti">@presenti</span> presenti su <span data-psiphos="aventi-diritto">@aventi</span> aventi diritto.',
          [
            '@stato' => $costituita ? $this->t('raggiunto') : $this->t('non raggiunto'),
            '@presenti' => $this->seduta->numeroPresenti(),
            '@aventi' => $this->seduta->aventiDirittoAllApertura() ?? $this->seduta->numeroAventiDiritto(),
          ]
        ),
      ];

      if ($this->votazioniInCorso() === []) {
        $form['seduta']['chiudi'] = [
          '#type' => 'submit',
          '#name' => 'chiudi_seduta',
          '#value' => $this->t('Dichiara chiusa la seduta'),
        ];
      }
      else {
        $form['seduta']['nota_chiusura'] = [
          '#type' => 'item',
          '#markup' => $this->t('La seduta non può essere chiusa mentre una votazione è ancora aperta o sospesa.'),
        ];
      }
    }

    foreach ($this->delibere() as $delibera) {
      $form['delibera_' . $delibera->id()] = $this->controlliDelibera($delibera, $costituita);
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comando = $form_state->getTriggeringElement()['#name'] ?? '';

    if ($comando === 'apri_seduta') {
      // Aprire una seduta con l'elenco vuoto produce un vicolo cieco: in aula
      // si entra solo se si è fra gli aventi diritto, e il denominatore dei
      // quorum si congela proprio all'apertura. Nessuno potrebbe più entrare
      // né votare, e l'unica uscita sarebbe chiudere una seduta mai svolta.
      if ($this->seduta !== NULL && $this->seduta->numeroAventiDiritto() === 0) {
        $this->messenger()->addError($this->t("L'elenco degli aventi diritto è vuoto: componilo prima di aprire la seduta, altrimenti nessuno potrà entrare in aula."));
        return;
      }

      $this->seduta?->transitaA(StatoSeduta::APERTA)->save();
      $this->messenger()->addStatus($this->t('Seduta dichiarata aperta.'));
      return;
    }

    if ($comando === 'chiudi_seduta') {
      // La condizione non può vivere solo nel rendering del pulsante: un
      // controllo che esiste soltanto nell'interfaccia è un controllo che
      // non esiste. Una seduta chiusa con una votazione ancora aperta
      // lascerebbe schede nell'urna senza uno scrutinio che le legga.
      if ($this->votazioniInCorso() !== []) {
        $this->messenger()->addError($this->t('La seduta non può essere chiusa mentre una votazione è aperta o sospesa.'));
        return;
      }

      $this->aula->decadiPresenzeScadute($this->seduta);
      $this->seduta?->transitaA(StatoSeduta::CHIUSA)->save();
      $this->messenger()->addStatus($this->t('Seduta dichiarata chiusa. Il verbale è da redigere.'));
      return;
    }

    [$azione, $identificativo] = array_pad(explode(':', $comando, 2), 2, '');
    $delibera = $identificativo === '' ? NULL : $this->gestoreEntita->getStorage('psiphos_delibera')->load($identificativo);

    if (!$delibera instanceof DeliberaInterface) {
      return;
    }

    $motivazione = trim((string) $form_state->getValue('motivazione_' . $delibera->id()));

    try {
      match ($azione) {
        'apri_votazione' => $this->apriVotazione($delibera),
        'sospendi' => $this->sospendi($delibera, $motivazione),
        'riprendi' => $this->riprendi($delibera),
        'chiudi_votazione' => $this->chiudiVotazione($delibera),
        'annulla' => $this->annulla($delibera, $motivazione),
        default => NULL,
      };
    }
    catch (\Throwable $errore) {
      $this->messenger()->addError($errore->getMessage());
    }
  }

  private function apriVotazione(DeliberaInterface $delibera): void {
    // Le presenze scadute decadono prima di fotografare il denominatore:
    // il quorum di una votazione non deve poggiare su sessioni abbandonate.
    $this->aula->decadiPresenzeScadute($delibera->seduta());

    $seduta = $this->gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($delibera->seduta()->id());
    if (!$seduta->validamenteCostituita()) {
      $this->messenger()->addError($this->t('Quorum costitutivo non raggiunto: @presenti presenti su @aventi aventi diritto. Non è possibile mettere ai voti.', [
        '@presenti' => $seduta->numeroPresenti(),
        '@aventi' => $seduta->aventiDirittoAllApertura() ?? $seduta->numeroAventiDiritto(),
      ]));
      return;
    }

    $delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
    $this->messenger()->addStatus($this->t('Votazione aperta su: @quesito', ['@quesito' => $delibera->label()]));
  }

  private function sospendi(DeliberaInterface $delibera, string $motivazione): void {
    if ($motivazione === '') {
      $this->messenger()->addError($this->t('Indica la motivazione della sospensione: il §8 dell\'allegato tecnico richiede che i malfunzionamenti siano documentati.'));
      return;
    }

    $delibera->transitaA(StatoDelibera::SOSPESA, $motivazione)->save();
    $this->messenger()->addWarning($this->t('Votazione sospesa. Le schede già depositate restano nell\'urna.'));
  }

  private function riprendi(DeliberaInterface $delibera): void {
    $delibera->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
    $this->messenger()->addStatus($this->t('Votazione ripresa. Chi ha già votato non può votare una seconda volta.'));
  }

  private function chiudiVotazione(DeliberaInterface $delibera): void {
    $this->scrutinio->chiudiEScrutina($delibera);
    $ricaricata = $this->gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
    $this->messenger()->addStatus($this->t('Votazione chiusa. Esito: @esito.', [
      '@esito' => $ricaricata->esito()?->etichettaPer($ricaricata->schemaScheda()) ?? '',
    ]));
  }

  private function annulla(DeliberaInterface $delibera, string $motivazione): void {
    if ($motivazione === '') {
      $this->messenger()->addError($this->t('Indica la motivazione dell\'annullamento.'));
      return;
    }

    $delibera->transitaA(StatoDelibera::ANNULLATA, $motivazione)->save();
    $this->messenger()->addWarning($this->t('Votazione annullata. Per ripeterla occorre predisporre una nuova delibera sullo stesso punto.'));
  }

  /**
   * Controlli disponibili su una singola votazione.
   */
  private function controlliDelibera(DeliberaInterface $delibera, bool $sedutaCostituita): array {
    $stato = $delibera->stato();

    $riquadro = [
      '#type' => 'fieldset',
      '#title' => $delibera->label(),
      'stato' => [
        '#type' => 'item',
        '#markup' => $stato->etichetta(),
      ],
    ];

    // A urna chiusa il conteggio smette di essere un segreto e diventa la
    // motivazione dell'esito: chi presiede deve poterlo leggere per
    // proclamarlo e per spiegarlo al collegio.
    if ($delibera->esito() !== NULL) {
      $voci = $delibera->vociScheda();
      $dettaglio = [];
      foreach ($delibera->conteggio() as $chiave => $voti) {
        $dettaglio[] = sprintf('%s: %d', $voci[$chiave] ?? $chiave, $voti);
      }

      $riquadro['scrutinio'] = [
        '#type' => 'item',
        '#markup' => $this->t('Scrutinio — @dettaglio. @criterio', [
          '@dettaglio' => implode('; ', $dettaglio),
          '@criterio' => $this->scrutinio->motivazioneEsito($delibera),
        ]),
      ];
    }

    if ($stato->ammetteTransizioneA(StatoDelibera::IN_VOTAZIONE)) {
      if ($sedutaCostituita) {
        $riquadro[$stato === StatoDelibera::SOSPESA ? 'riprendi' : 'apri'] = [
          '#type' => 'submit',
          '#name' => ($stato === StatoDelibera::SOSPESA ? 'riprendi:' : 'apri_votazione:') . $delibera->id(),
          '#value' => $stato === StatoDelibera::SOSPESA ? $this->t('Riprendi la votazione') : $this->t('Metti ai voti'),
          '#button_type' => 'primary',
        ];
      }
      else {
        $riquadro['impedimento'] = [
          '#type' => 'item',
          '#markup' => $this->t('Non è possibile mettere ai voti finché il quorum costitutivo non è raggiunto.'),
        ];
      }
    }

    // Quante schede sono state depositate. È l'informazione su cui il
    // presidente decide quando chiudere: senza, non resterebbe che attendere
    // un tempo convenzionale e chiudere alla cieca. Il numero dei votanti non
    // dice nulla su come si sta votando, che resta invisibile a tutti fino
    // allo scrutinio.
    if (in_array($stato, [StatoDelibera::IN_VOTAZIONE, StatoDelibera::SOSPESA], TRUE)) {
      $presentiAlVoto = (int) $delibera->get('presenti_al_voto')->value;
      $votanti = $this->urna->numeroVotanti($delibera);

      $riquadro['partecipazione'] = [
        '#type' => 'item',
        // Ogni numero che può cambiare mentre si vota porta il proprio
        // contrassegno: un valore aggiornato accanto a uno statico dice due
        // cose diverse sulla stessa realtà, ed è peggio di nessuna delle due.
        '#markup' => $this->t(
          'Schede depositate: <span data-psiphos="votanti">@votanti</span> su <span data-psiphos="presenti-al-voto">@presenti</span> aventi diritto presenti all\'apertura dell\'urna. Mancano <span data-psiphos="mancanti">@mancanti</span>.',
          [
            '@votanti' => $votanti,
            '@presenti' => $presentiAlVoto,
            '@mancanti' => max(0, $presentiAlVoto - $votanti),
          ]
        ),
      ];
    }

    if ($stato === StatoDelibera::IN_VOTAZIONE) {
      $riquadro['chiudi'] = [
        '#type' => 'submit',
        '#name' => 'chiudi_votazione:' . $delibera->id(),
        '#value' => $this->t('Chiudi la votazione e scrutina'),
      ];
      $riquadro['sospendi'] = [
        '#type' => 'submit',
        '#name' => 'sospendi:' . $delibera->id(),
        '#value' => $this->t('Sospendi'),
      ];
    }

    $puoAnnullare = $stato->ammetteTransizioneA(StatoDelibera::ANNULLATA);
    $puoSospendere = $stato->ammetteTransizioneA(StatoDelibera::SOSPESA);

    if ($puoAnnullare || $puoSospendere) {
      $riquadro['motivazione_' . $delibera->id()] = [
        '#type' => 'textarea',
        '#title' => $this->t('Motivazione di sospensione o annullamento'),
        '#rows' => 2,
        '#description' => $this->t('Obbligatoria per sospendere o annullare la votazione.'),
      ];
    }

    if ($puoAnnullare) {
      $riquadro['annulla'] = [
        '#type' => 'submit',
        '#name' => 'annulla:' . $delibera->id(),
        '#value' => $this->t('Annulla la votazione'),
      ];
    }

    return $riquadro;
  }

  /**
   * Delibere della seduta, nell'ordine dei punti all'ordine del giorno.
   *
   * @return array<int, \Drupal\psiphos\Entity\DeliberaInterface>
   */
  private function delibere(): array {
    if ($this->seduta === NULL || $this->seduta->isNew()) {
      return [];
    }

    $archivio = $this->gestoreEntita->getStorage('psiphos_delibera');
    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $this->seduta->id())
      ->sort('id')
      ->execute();

    return array_values($archivio->loadMultiple($identificativi));
  }

  /**
   * Votazioni non ancora concluse.
   *
   * @return array<int, \Drupal\psiphos\Entity\DeliberaInterface>
   */
  private function votazioniInCorso(): array {
    return array_filter(
      $this->delibere(),
      static fn (DeliberaInterface $d): bool => in_array($d->stato(), [StatoDelibera::IN_VOTAZIONE, StatoDelibera::SOSPESA], TRUE)
    );
  }

}
