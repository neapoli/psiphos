<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Service\Scrutinio;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Redazione dell'atto di una delibera già votata.
 *
 * È un modulo distinto da quello della delibera perché redige un'altra cosa.
 * La delibera si predispone prima della votazione e da quel momento è
 * congelata: quesito, scheda e maggioranza non possono più cambiare, o il
 * conteggio non sarebbe più confrontabile con quanto è stato messo ai voti.
 * L'atto invece si scrive dopo, ed è normale che sia così: il numero di
 * protocollo, i «visto» e il dispositivo sono lavoro del segretario a seduta
 * conclusa. Tenere i due momenti su due moduli separati consente di
 * consentire il secondo senza riaprire il primo.
 */
final class AttoDeliberaForm extends ContentEntityForm {

  /**
   * Campi che compongono l'atto: gli unici redigibili da questo modulo.
   */
  private const CAMPI_ATTO = ['numero_delibera', 'oggetto', 'premesse', 'dispositivo'];

  private Scrutinio $scrutinio;

  public static function create(ContainerInterface $container): static {
    $istanza = parent::create($container);
    $istanza->scrutinio = $container->get('psiphos.scrutinio');

    return $istanza;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $delibera = $this->entity;
    assert($delibera instanceof Delibera);

    $form['#cache']['max-age'] = 0;

    // Tutto ciò che riguarda la votazione resta visibile altrove ma non è
    // toccabile qui: mostrarlo disabilitato sarebbe rumore, mostrarlo
    // modificabile sarebbe una porta aperta su dati già votati.
    foreach (array_keys($form) as $campo) {
      if (is_string($campo) && !str_starts_with($campo, '#') && !in_array($campo, self::CAMPI_ATTO, TRUE)) {
        $form[$campo]['#access'] = FALSE;
      }
    }

    $form['guida'] = [
      '#type' => 'item',
      '#weight' => -100,
      '#markup' => '<p>' . $this->t('Questo è il testo dell\'atto, che circolerà come documento autonomo: sarà l\'estratto di delibera da protocollare, pubblicare e trasmettere. Il quesito votato e il conteggio non vanno ricopiati: compaiono già nell\'estratto, letti dall\'urna.') . '</p>',
    ];

    $righe = [];
    foreach ($this->scrutinio->prospettoVotazione($delibera) as $riga) {
      $righe[] = [
        $riga['qualifica'] === ''
          ? $riga['voce']
          : $this->t('@voce — @qualifica', ['@voce' => $riga['voce'], '@qualifica' => $riga['qualifica']]),
        $riga['valore'],
      ];
    }

    $form['votazione'] = [
      '#type' => 'item',
      '#title' => $this->t('Esito della votazione'),
      '#weight' => -99,
      '#description' => $this->t('Proclamazione e prospetto chiudono l\'estratto e sono composti dal sistema leggendo l\'urna: non vanno riscritti nei campi sottostanti.'),
      'proclamazione' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => Html::escape($this->scrutinio->proclamazione($delibera)),
      ],
      'prospetto' => [
        '#type' => 'table',
        '#header' => [$this->t('Voce'), $this->t('Numero')],
        '#rows' => $righe,
        '#empty' => $this->t('Nessun dato di votazione.'),
      ],
    ];

    $form['numero_delibera']['#weight'] = -10;
    $form['oggetto']['#weight'] = -9;
    $form['premesse']['#weight'] = -8;
    $form['dispositivo']['#weight'] = -7;

    $form['anteprima'] = [
      '#type' => 'details',
      '#title' => $this->t('Anteprima dell\'estratto'),
      '#weight' => 100,
      'contenuto' => $this->entityTypeManager->getViewBuilder('psiphos_delibera')->view($delibera),
    ];

    return $form;
  }

  protected function actions(array $form, FormStateInterface $form_state): array {
    $azioni = parent::actions($form, $form_state);
    $azioni['submit']['#value'] = $this->t('Salva l\'atto');

    return $azioni;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entita = parent::validateForm($form, $form_state);

    // Il numero identifica l'atto ovunque vada: senza, l'estratto non è
    // protocollabile e non è citabile nel verbale. Si chiede qui, dove si
    // sta redigendo, e non al sigillo, dove il rimedio sarebbe tornare
    // indietro su ogni delibera incompleta.
    if (trim((string) $form_state->getValue(['numero_delibera', 0, 'value'])) === '') {
      $form_state->setErrorByName('numero_delibera', $this->t('Indicare il numero di delibera: è l\'identificativo con cui l\'atto sarà protocollato e citato.'));
    }

    if (trim((string) $form_state->getValue(['dispositivo', 0, 'value'])) === '') {
      $form_state->setErrorByName('dispositivo', $this->t('Indicare il dispositivo: è la parte dell\'atto che dice che cosa l\'organo ha deliberato.'));
    }

    return $entita;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $esito = parent::save($form, $form_state);
    $delibera = $this->entity;
    assert($delibera instanceof Delibera);

    $this->messenger()->addStatus($this->t('Atto della delibera n. @numero redatto. Sarà sigillato insieme al verbale della seduta.', [
      '@numero' => $delibera->get('numero_delibera')->value,
    ]));

    $seduta = $delibera->seduta();
    if ($seduta !== NULL) {
      $form_state->setRedirectUrl($seduta->toUrl());
    }

    return $esito;
  }

}
