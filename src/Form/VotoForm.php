<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\psiphos\Exception\VotoNonAmmessoException;
use Drupal\psiphos\Service\Urna;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scheda di voto presentata all'avente diritto.
 *
 * Il modulo non conserva alcuna bozza della scheda: nessun valore intermedio
 * finisce in sessione, in cache o in un archivio temporaneo. La scelta
 * esiste solo nella richiesta che la deposita, e da lì passa direttamente
 * all'urna. Qualunque forma di salvataggio parziale sarebbe, per il §4.3,
 * un archivio che associa un'identità autenticata a un voto.
 */
final class VotoForm extends FormBase {

  private ?DeliberaInterface $delibera = NULL;

  private ?AccountInterface $votante = NULL;

  public function __construct(private readonly Urna $urna) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('psiphos.urna'));
  }

  public function getFormId(): string {
    return 'psiphos_voto';
  }

  /**
   * Il form è costruito per una delibera e un votante specifici.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DeliberaInterface $delibera = NULL, ?AccountInterface $votante = NULL): array {
    $this->delibera = $delibera ?? $this->delibera;
    $this->votante = $votante ?? $this->votante;

    if ($this->delibera === NULL || $this->votante === NULL) {
      return $form;
    }

    $voci = $this->delibera->vociScheda();
    $massime = $this->delibera->preferenzeMassime();
    $segreto = $this->delibera->tipoVoto() === TipoVoto::SEGRETO;

    $form['#attributes']['class'][] = 'psiphos-scheda';

    // La scheda viene ricostruita anche dentro il frammento di aggiornamento
    // dell'aula, e da lì erediterebbe l'indirizzo del frammento: il deposito
    // finirebbe su una risposta che il browser non sa seguire. L'invio va
    // dichiarato esplicitamente verso la pagina dell'aula.
    $form['#action'] = $this->indirizzoAula();

    $form['quesito'] = [
      '#type' => 'item',
      '#title' => $this->t('Quesito posto ai voti'),
      '#markup' => '<p class="psiphos-scheda__quesito">' . $this->delibera->label() . '</p>',
    ];

    $form['modalita'] = [
      '#type' => 'item',
      '#markup' => '<p class="psiphos-scheda__modalita">' . ($segreto
        ? $this->t('Voto a scrutinio segreto. La scheda è conservata separatamente dal registro dei votanti: risulterà che hai votato, non come hai votato.')
        : $this->t('Voto palese. La scelta espressa è registrata insieme al tuo nominativo e comparirà a verbale.')) . '</p>',
    ];

    if ($massime > 1) {
      $form['scelte'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Preferenze'),
        '#description' => $this->t('Puoi indicare fino a @massime preferenze. La scheda bianca esclude ogni altra scelta.', ['@massime' => $massime]),
        '#options' => $voci,
        '#required' => TRUE,
      ];
    }
    else {
      $form['scelte'] = [
        '#type' => 'radios',
        '#title' => $this->t('Il tuo voto'),
        '#options' => $voci,
        '#required' => TRUE,
      ];
    }

    $form['azioni'] = ['#type' => 'actions'];
    $form['azioni']['deposita'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deposita la scheda'),
      '#button_type' => 'primary',
    ];

    // La conferma è deliberata: il voto non è modificabile una volta
    // depositato (§4.1), e l'interfaccia deve dirlo prima e non dopo.
    $form['azioni']['deposita']['#attributes']['data-psiphos-conferma'] = $this->t('Confermi il voto? Una volta depositata, la scheda non è più modificabile.');

    $form['#cache']['max-age'] = 0;

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $scelte = $this->scelteEspresse($form_state);

    if ($scelte === []) {
      $form_state->setErrorByName('scelte', $this->t('Indica una preferenza oppure scegli la scheda bianca.'));
      return;
    }

    $massime = $this->delibera?->preferenzeMassime() ?? 1;
    $bianca = in_array(SchemaScheda::VOCE_SCHEDA_BIANCA, $scelte, TRUE);

    if ($bianca && count($scelte) > 1) {
      $form_state->setErrorByName('scelte', $this->t('La scheda bianca non può essere combinata con altre preferenze.'));
      return;
    }

    if (!$bianca && count($scelte) > $massime) {
      $form_state->setErrorByName('scelte', $this->t('Hai indicato @espresse preferenze, il massimo consentito è @massime.', [
        '@espresse' => count($scelte),
        '@massime' => $massime,
      ]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->delibera === NULL || $this->votante === NULL) {
      return;
    }

    try {
      $this->urna->deposita($this->delibera, $this->votante, $this->scelteEspresse($form_state));
      $this->messenger()->addStatus($this->t('Scheda depositata. Il tuo voto è stato registrato.'));
    }
    catch (VotoNonAmmessoException $rifiuto) {
      // Il messaggio dell'eccezione è già scritto per essere letto dal
      // votante e non rivela nulla dello scrutinio in corso.
      $this->messenger()->addError($rifiuto->getMessage());
    }
  }

  /**
   * Indirizzo della pagina dell'aula a cui inviare la scheda.
   */
  private function indirizzoAula(): string {
    $seduta = $this->delibera?->seduta();

    return $seduta === NULL
      ? ''
      : Url::fromRoute('psiphos.aula', ['psiphos_seduta' => $seduta->id()])->toString();
  }

  /**
   * Voci selezionate, in forma di elenco di chiavi.
   *
   * @return array<int, string>
   */
  private function scelteEspresse(FormStateInterface $form_state): array {
    $valore = $form_state->getValue('scelte');

    if (is_array($valore)) {
      return array_values(array_filter($valore, static fn ($v): bool => $v !== 0 && $v !== NULL && $v !== ''));
    }

    return $valore === NULL || $valore === '' ? [] : [(string) $valore];
  }

}
