<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\psiphos\Entity\Verbale;
use Drupal\psiphos\Service\ConservazioneDocumento;
use Drupal\psiphos\Service\Verbalizzazione;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Redazione del verbale e apposizione del sigillo.
 */
final class VerbaleForm extends ContentEntityForm {

  private Verbalizzazione $verbalizzazione;

  private ConservazioneDocumento $conservazione;

  public static function create(ContainerInterface $container): static {
    $istanza = parent::create($container);
    $istanza->verbalizzazione = $container->get('psiphos.verbalizzazione');
    $istanza->conservazione = $container->get('psiphos.conservazione_documento');

    return $istanza;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $verbale = $this->entity;
    assert($verbale instanceof Verbale);

    $form['#cache']['max-age'] = 0;

    if ($verbale->sigillato()) {
      $form['sigillato'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['status' => [
          $this->t('Il verbale è sigillato e non è più modificabile. Per correggerlo occorre un verbale di rettifica, che lasci traccia di entrambi.'),
        ]],
        '#weight' => -100,
      ];
      $form['testo']['#disabled'] = TRUE;

      return $form;
    }

    $ammissibilita = $this->verbalizzazione->sigillabile($verbale);

    $form['guida'] = [
      '#type' => 'item',
      '#weight' => -50,
      '#markup' => '<p>' . $this->t('Presenze, votazioni ed esiti sono già documentati automaticamente e comparirranno nel verbale: qui va riportato quanto non è deducibile dai dati, cioè la discussione e gli interventi.') . '</p>',
    ];

    if (!$this->conservazione->conservazioneDisponibile()) {
      $form['avvertenza_formato'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [
          $this->t('Il formato di conservazione PDF/A non è producibile su questo server: il verbale sarà sigillato in PDF ordinario. Il formato effettivo resta registrato sul verbale, ma va segnalato al responsabile della conservazione.'),
        ]],
        '#weight' => -49,
      ];
    }

    if (!$ammissibilita['ammesso']) {
      $form['impedimento'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['warning' => [$ammissibilita['motivo']]],
        '#weight' => -48,
      ];
    }

    $form['anteprima'] = [
      '#type' => 'details',
      '#title' => $this->t('Anteprima del verbale'),
      '#weight' => 100,
      'contenuto' => $this->entityTypeManager->getViewBuilder('psiphos_verbale')->view($verbale),
    ];

    return $form;
  }

  protected function actions(array $form, FormStateInterface $form_state): array {
    $azioni = parent::actions($form, $form_state);
    $verbale = $this->entity;
    assert($verbale instanceof Verbale);

    if ($verbale->sigillato()) {
      return ['#type' => 'actions'];
    }

    $azioni['submit']['#value'] = $this->t('Salva la bozza');

    if ($this->verbalizzazione->sigillabile($verbale)['ammesso']) {
      $azioni['sigilla'] = [
        '#type' => 'submit',
        '#value' => $this->t('Sigilla il verbale'),
        '#button_type' => 'primary',
        '#submit' => ['::salvaESigilla'],
        '#attributes' => [
          'data-psiphos-conferma' => $this->t('Sigillare il verbale? Da questo momento non sarà più modificabile in alcuna parte.'),
        ],
      ];
    }

    return $azioni;
  }

  /**
   * Salva la bozza e appone il sigillo.
   */
  public function salvaESigilla(array $form, FormStateInterface $form_state): void {
    $verbale = $this->entity;
    assert($verbale instanceof Verbale);

    $verbale->save();

    try {
      $sigillato = $this->verbalizzazione->sigilla($verbale, $this->currentUser());
      $this->messenger()->addStatus($this->t('Verbale sigillato in formato @formato. La seduta è verbalizzata.', [
        '@formato' => $sigillato->get('formato')->value,
      ]));
      $form_state->setRedirectUrl($sigillato->toUrl());
    }
    catch (\Throwable $errore) {
      $this->messenger()->addError($this->t('Il verbale non è stato sigillato: @messaggio', [
        '@messaggio' => $errore->getMessage(),
      ]));
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $esito = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Bozza del verbale salvata.'));
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));

    return $esito;
  }

}
