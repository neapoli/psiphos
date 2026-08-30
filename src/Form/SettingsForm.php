<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\psiphos\Service\ConservazioneDocumento;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configura identificazione, autenticazione e sessioni delle sedute.
 *
 * Attua l'allegato tecnico alla nota MIM prot. 3803 del 30/06/2026, §3
 * "Requisiti di identificazione, autenticazione e accesso".
 */
final class SettingsForm extends ConfigFormBase {

  public const SETTINGS = 'psiphos.settings';

  /**
   * Moduli che, se installati, possono erogare l'autenticazione forte.
   *
   * Chiave: nome macchina del modulo. Valore: etichetta proposta all'utente.
   */
  private const PROVIDER_FORTI = [
    'spid' => 'SPID',
    'cie' => 'CIE — Carta di Identità Elettronica',
    'openid_connect' => 'OpenID Connect',
    'simplesamlphp_auth' => 'SAML',
  ];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConservazioneDocumento $conservazione,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->get('psiphos.conservazione_documento'),
    );
  }

  public function getFormId(): string {
    return 'psiphos_settings';
  }

  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['premessa'] = [
      '#type' => 'item',
      '#markup' => $this->t('Queste impostazioni attuano il §3 dell\'allegato tecnico alla nota MIM prot. 3803 del 30/06/2026. Il livello di autenticazione va scelto in coerenza con quanto previsto dal Regolamento d\'istituto.'),
    ];

    $form['fornitore'] = [
      '#type' => 'details',
      '#title' => $this->t('Fornitore che sottoscrive la dichiarazione'),
      '#open' => TRUE,
      '#description' => $this->t("Il §9 chiede all'istituzione di acquisire una dichiarazione di conformità «da parte del fornitore o partner tecnologico». Una dichiarazione che non identifichi chi la rende non assolve quell'obbligo: questi dati compaiono nel documento da sottoscrivere."),
    ];
    $form['fornitore']['denominazione'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Denominazione'),
      '#default_value' => $config->get('fornitore.denominazione'),
      '#maxlength' => 255,
      '#description' => $this->t('Ragione sociale o nome del professionista che ha realizzato e messo a disposizione il modulo.'),
    ];
    $form['fornitore']['partita_iva'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Partita IVA o codice fiscale'),
      '#default_value' => $config->get('fornitore.partita_iva'),
      '#maxlength' => 32,
    ];
    $form['fornitore']['contatto'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recapito'),
      '#default_value' => $config->get('fornitore.contatto'),
      '#maxlength' => 255,
      '#description' => $this->t('Indirizzo di posta elettronica o PEC a cui la scuola può rivolgersi.'),
    ];

    $form['hosting'] = [
      '#type' => 'details',
      '#title' => $this->t("Fornitore dell'infrastruttura"),
      '#description' => $this->t("Chi ospita il sito tratta dati personali per conto dell'istituzione e va nominato responsabile ai sensi dell'art. 28. Questi dati compaiono nella richiesta di documentazione, nell'atto di nomina, nel registro delle attività di trattamento e nella descrizione tecnica per la valutazione d'impatto."),
    ];
    // Distinzione da tenere ferma: sopra c'è chi ha realizzato il modulo e ne
    // sottoscrive la dichiarazione; qui chi ospita il sito. Sono due
    // responsabili distinti, con obblighi distinti, e confonderli significa
    // attribuire all'uno le misure infrastrutturali dell'altro.
    $form['hosting']['hosting_denominazione'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Denominazione'),
      '#default_value' => $config->get('hosting.denominazione'),
      '#maxlength' => 255,
      '#description' => $this->t('Ragione sociale del fornitore del servizio di hosting, come risulta dal contratto.'),
    ];
    $form['hosting']['hosting_partita_iva'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Partita IVA o codice fiscale'),
      '#default_value' => $config->get('hosting.partita_iva'),
      '#maxlength' => 32,
    ];
    $form['hosting']['hosting_sede'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sede legale'),
      '#default_value' => $config->get('hosting.sede'),
      '#maxlength' => 255,
    ];
    $form['hosting']['hosting_contatto'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recapito o PEC'),
      '#default_value' => $config->get('hosting.contatto'),
      '#maxlength' => 255,
      '#description' => $this->t("Indirizzo al quale è stata inviata la richiesta di documentazione e al quale il fornitore notifica gli incidenti."),
    ];

    $form['hosting']['dichiarato'] = [
      '#type' => 'item',
      '#markup' => $this->t("<strong>Quanto segue è riferito, non verificato.</strong> Il sistema non può osservare dove risiedano i dati né se una nomina sia stata sottoscritta: registra che cosa il fornitore ha dichiarato e con quale atto, e in questa forma lo riporta. Nell'attestazione di conformità i requisiti del §5 restano a carico dell'istituzione anche quando questi campi sono compilati."),
    ];
    $form['hosting']['hosting_ubicazione_dati'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Paese di ubicazione dei dati, come dichiarato'),
      '#default_value' => $config->get('hosting.ubicazione_dati'),
      '#maxlength' => 255,
      '#description' => $this->t("Serve al registro delle attività di trattamento: l'art. 30, paragrafo 1, lettera e), impone di indicare gli eventuali trasferimenti verso Paesi terzi."),
    ];
    $form['hosting']['hosting_nomina_protocollo'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Protocollo dell'atto di nomina ex art. 28"),
      '#default_value' => $config->get('hosting.nomina_protocollo'),
      '#maxlength' => 64,
    ];
    $form['hosting']['hosting_nomina_data'] = [
      '#type' => 'date',
      '#title' => $this->t("Data dell'atto di nomina"),
      '#default_value' => $config->get('hosting.nomina_data'),
    ];
    $form['hosting']['hosting_riscontro_protocollo'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Protocollo del riscontro alla richiesta di documentazione'),
      '#default_value' => $config->get('hosting.riscontro_protocollo'),
      '#maxlength' => 64,
    ];
    $form['hosting']['hosting_riscontro_data'] = [
      '#type' => 'date',
      '#title' => $this->t('Data del riscontro'),
      '#default_value' => $config->get('hosting.riscontro_data'),
    ];

    $form['autenticazione'] = [
      '#type' => 'details',
      '#title' => $this->t('Identificazione e autenticazione'),
      '#open' => TRUE,
    ];
    $form['autenticazione']['livello'] = [
      '#type' => 'radios',
      '#title' => $this->t('Livello richiesto per l\'espressione del voto'),
      '#default_value' => $config->get('autenticazione.livello'),
      '#required' => TRUE,
      '#options' => [
        'account' => $this->t('Account personale del sito'),
        'mfa' => $this->t('Account personale con secondo fattore'),
        'forte' => $this->t('Autenticazione forte (SPID, CIE o equivalente)'),
      ],
      'account' => [
        '#description' => $this->t('Credenziali personali non condivise. Soddisfa il §3.1 ma è il livello minimo: adottarlo solo se il Regolamento d\'istituto lo motiva rispetto al livello di rischio.'),
      ],
      'mfa' => [
        '#description' => $this->t('Livello consigliato. Il §3.2 chiede di privilegiare, ove possibile, modalità di autenticazione forte: il secondo fattore è la misura minima adeguata a prevenire impersonificazione e uso condiviso delle credenziali.'),
      ],
      'forte' => [
        '#description' => $this->t('Massima aderenza al CAD. Richiede un modulo di autenticazione configurato e attivo.'),
      ],
    ];

    $opzioni_provider = $this->providerDisponibili();
    $form['autenticazione']['provider_forte'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider di autenticazione forte'),
      '#default_value' => $config->get('autenticazione.provider_forte'),
      '#options' => $opzioni_provider,
      '#empty_option' => $this->t('- Nessuno disponibile -'),
      '#description' => $opzioni_provider
        ? $this->t('Modulo che eroga l\'autenticazione forte per le sedute deliberative.')
        : $this->t('Nessun modulo di autenticazione forte risulta installato. Installarne uno prima di selezionare il livello «Autenticazione forte».'),
      '#states' => [
        'visible' => [':input[name="livello"]' => ['value' => 'forte']],
        'required' => [':input[name="livello"]' => ['value' => 'forte']],
      ],
    ];
    $form['autenticazione']['etichetta_provider_forte'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Etichetta mostrata agli aventi diritto'),
      '#default_value' => $config->get('autenticazione.etichetta_provider_forte'),
      '#maxlength' => 128,
      '#description' => $this->t('Testo del pulsante di accesso all\'aula, ad esempio «Entra con SPID».'),
      '#states' => [
        'visible' => [':input[name="livello"]' => ['value' => 'forte']],
      ],
    ];

    $form['sessione'] = [
      '#type' => 'details',
      '#title' => $this->t('Gestione delle sessioni'),
      '#open' => TRUE,
      '#description' => $this->t('Il §3.4 richiede il tracciamento delle sessioni attive, l\'interruzione automatica per inattività e la prevenzione di accessi simultanei non autorizzati.'),
    ];
    $form['sessione']['timeout_inattivita'] = [
      '#type' => 'number',
      '#title' => $this->t('Interruzione automatica per assenza di contatto'),
      '#field_suffix' => $this->t('secondi'),
      '#default_value' => $config->get('sessione.timeout_inattivita'),
      '#required' => TRUE,
      '#min' => 60,
      '#max' => 7200,
      '#step' => 60,
      '#description' => $this->t("Finché la pagina dell'aula resta aperta, il dispositivo si fa vivo da solo e la presenza si mantiene: chi partecipa non deve toccare nulla mentre ascolta. Trascorso questo intervallo senza alcun segnale — pagina chiusa, dispositivo spento, rete caduta — la presenza decade e l'avente diritto deve rientrare. Incide sul computo del quorum, perciò non va allungato oltre il necessario."),
    ];
    $form['sessione']['sessione_esclusiva'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Consentire una sola sessione di voto per avente diritto'),
      '#default_value' => $config->get('sessione.sessione_esclusiva'),
      '#description' => $this->t('L\'ingresso in aula da un nuovo dispositivo chiude la sessione precedente. Disattivare solo con motivazione documentata.'),
    ];

    $form['conservazione'] = [
      '#type' => 'details',
      '#title' => $this->t('Conservazione documentale'),
      '#open' => TRUE,
      '#description' => $this->t("Il §7 dell'allegato tecnico richiede che i verbali siano conservati nel rispetto delle Linee guida AgID. Il formato prescritto è il PDF/A, che il generatore di PDF non produce da solo: la conversione è affidata a Ghostscript."),
    ];
    $form['conservazione']['pdfa_attivo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Convertire i verbali in PDF/A-2B'),
      '#default_value' => $config->get('conservazione.pdfa_attivo'),
      '#description' => $this->t('Se la conversione non riesce il verbale viene sigillato ugualmente in PDF ordinario, e il formato effettivo resta registrato sul verbale.'),
    ];
    $form['conservazione']['ghostscript'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Percorso di Ghostscript'),
      '#default_value' => $config->get('conservazione.ghostscript'),
      '#maxlength' => 255,
      '#description' => $this->statoConservazione(),
      '#states' => [
        'visible' => [':input[name="pdfa_attivo"]' => ['checked' => TRUE]],
      ],
    ];

    $form['audit'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracciature tecniche'),
      '#open' => TRUE,
      '#description' => $this->t("Il §2 dell'allegato tecnico richiede che lo svolgimento del procedimento deliberativo sia ricostruibile ex post; il §6 impone però di non conservare i dati oltre il necessario. La rimozione interviene solo su sedute già verbalizzate, dove il verbale sigillato resta come evidenza documentale."),
    ];
    $form['audit']['ritenzione_giorni'] = [
      '#type' => 'number',
      '#title' => $this->t('Conservazione delle tracciature'),
      '#field_suffix' => $this->t('giorni dalla chiusura della seduta'),
      '#default_value' => $config->get('audit.ritenzione_giorni'),
      '#required' => TRUE,
      '#min' => 0,
      '#max' => 36500,
      '#description' => $this->t('Zero disattiva la rimozione automatica. Il termine va allineato a quello adottato dall\'istituto per la conservazione degli atti degli organi collegiali.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Riferisce se il formato di conservazione è producibile, e altrimenti perché.
   */
  private function statoConservazione(): string {
    $impedimento = $this->conservazione->impedimento();

    if ($impedimento === NULL) {
      return (string) $this->t('Ghostscript trovato: il formato di conservazione è producibile.');
    }

    $stato = $impedimento . ' ' . (string) $this->t('I verbali saranno sigillati in PDF ordinario.');
    $altrove = $this->conservazione->cercaGhostscript();

    if ($altrove !== NULL) {
      return $stato . ' ' . (string) $this->t('Risulta però presente in @percorso: indicare questo percorso e salvare.', [
        '@percorso' => $altrove,
      ]);
    }

    return $stato;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($form_state->getValue('livello') === 'forte' && !$form_state->getValue('provider_forte')) {
      $form_state->setErrorByName('provider_forte', $this->t('Selezionare il modulo che eroga l\'autenticazione forte, oppure scegliere un livello di autenticazione diverso.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $livello = $form_state->getValue('livello');

    $this->config(self::SETTINGS)
      ->set('fornitore.denominazione', trim((string) $form_state->getValue('denominazione')))
      ->set('fornitore.partita_iva', trim((string) $form_state->getValue('partita_iva')))
      ->set('fornitore.contatto', trim((string) $form_state->getValue('contatto')))
      ->set('hosting.denominazione', trim((string) $form_state->getValue('hosting_denominazione')))
      ->set('hosting.partita_iva', trim((string) $form_state->getValue('hosting_partita_iva')))
      ->set('hosting.sede', trim((string) $form_state->getValue('hosting_sede')))
      ->set('hosting.contatto', trim((string) $form_state->getValue('hosting_contatto')))
      ->set('hosting.ubicazione_dati', trim((string) $form_state->getValue('hosting_ubicazione_dati')))
      ->set('hosting.nomina_protocollo', trim((string) $form_state->getValue('hosting_nomina_protocollo')))
      ->set('hosting.nomina_data', trim((string) $form_state->getValue('hosting_nomina_data')))
      ->set('hosting.riscontro_protocollo', trim((string) $form_state->getValue('hosting_riscontro_protocollo')))
      ->set('hosting.riscontro_data', trim((string) $form_state->getValue('hosting_riscontro_data')))
      ->set('autenticazione.livello', $livello)
      ->set('autenticazione.provider_forte', $livello === 'forte' ? $form_state->getValue('provider_forte') : '')
      ->set('autenticazione.etichetta_provider_forte', $livello === 'forte' ? trim((string) $form_state->getValue('etichetta_provider_forte')) : '')
      ->set('sessione.timeout_inattivita', (int) $form_state->getValue('timeout_inattivita'))
      ->set('sessione.sessione_esclusiva', (bool) $form_state->getValue('sessione_esclusiva'))
      ->set('conservazione.pdfa_attivo', (bool) $form_state->getValue('pdfa_attivo'))
      ->set('conservazione.ghostscript', trim((string) $form_state->getValue('ghostscript')))
      ->set('audit.ritenzione_giorni', (int) $form_state->getValue('ritenzione_giorni'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Restituisce i provider di autenticazione forte effettivamente installati.
   */
  private function providerDisponibili(): array {
    $disponibili = [];
    foreach (self::PROVIDER_FORTI as $modulo => $etichetta) {
      if ($this->moduleHandler->moduleExists($modulo)) {
        $disponibili[$modulo] = $etichetta;
      }
    }
    return $disponibili;
  }

}
