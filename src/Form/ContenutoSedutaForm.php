<?php

declare(strict_types=1);

namespace Drupal\psiphos\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;

/**
 * Form comune a seduta, punto all'ordine del giorno e delibera.
 *
 * Le tre entità si compongono una dentro l'altra e si redigono di seguito:
 * dopo averne salvata una si torna sempre alla seduta, che è il luogo da cui
 * si prosegue. Lasciare l'utente sul modulo di inserimento appena compilato
 * lo costringe a chiedersi se il salvataggio sia andato a buon fine.
 */
class ContenutoSedutaForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    if ($this->entity instanceof Delibera) {
      $this->spiegaScelte($form, 'schema_scheda', SchemaScheda::cases());
      $this->spiegaScelte($form, 'regola_maggioranza', RegolaMaggioranza::cases());
      $this->raccogliTestoAtto($form);
    }

    return $form;
  }

  /**
   * Raccoglie in un blocco i campi che compongono il testo dell'atto.
   *
   * Sono d'altra natura rispetto al resto del modulo: sopra si configura come
   * si voterà, qui si scrive che cosa la delibera dirà una volta votata.
   * Tenerli mescolati fa sembrare obbligatorio ora quel che si può scrivere
   * anche dopo, e il blocco chiuso lo dice senza doverlo spiegare.
   */
  private function raccogliTestoAtto(array &$form): void {
    $campi = ['oggetto', 'premesse', 'dispositivo'];

    if (array_diff($campi, array_keys($form)) !== []) {
      return;
    }

    $form['testo_atto'] = [
      '#type' => 'details',
      '#title' => $this->t("Testo dell'atto"),
      '#description' => $this->t("Compare nell'estratto di delibera, il documento che la delibera diventa una volta votata. Si può compilare ora, se la proposta è già istruita, oppure a seduta conclusa: va completato prima di sigillare il verbale. I dati della votazione non vanno scritti qui, li compone il sistema leggendoli dall'urna."),
      '#open' => FALSE,
      '#weight' => 19,
    ];

    // Gli elementi si spostano conservando il proprio #parents, che il widget
    // ha già fissato: il valore continua ad arrivare dove l'entità lo attende,
    // e cambia solo dove l'elemento è disegnato.
    foreach ($campi as $campo) {
      $form['testo_atto'][$campo] = $form[$campo];
      unset($form[$campo]);
    }
  }

  /**
   * Affianca a ciascuna alternativa la spiegazione di che cosa comporta.
   *
   * Struttura della scheda e maggioranza richiesta si scelgono una volta sola
   * e poi si bloccano all'apertura dell'urna: chi decide deve poter leggere
   * come si conterà prima di mettere ai voti, non scoprirlo dallo scrutinio.
   *
   * @param array<int, \BackedEnum> $alternative
   */
  private function spiegaScelte(array &$form, string $campo, array $alternative): void {
    if (!isset($form[$campo]['widget']['#options'])) {
      return;
    }

    foreach ($alternative as $alternativa) {
      if (method_exists($alternativa, 'descrizione')) {
        $form[$campo]['widget'][$alternativa->value]['#description'] = $alternativa->descrizione();
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * La classe padre restituisce l'entità costruita dal form, non l'array del
   * form: va raccolta come tale e restituita a sua volta, perché il contratto
   * di ContentEntityForm è quello.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entita = parent::validateForm($form, $form_state);

    // Nulla si aggiunge a una seduta già conclusa. Il controllo di accesso
    // presidia la modifica di ciò che esiste, ma la creazione non conosce la
    // seduta di destinazione finché il modulo non è compilato: senza questa
    // verifica un punto o una delibera potrebbero essere agganciati a una
    // seduta già verbalizzata, invalidandone l'impronta del verbale.
    $sedutaDestinazione = $this->sedutaDi($entita);
    if ($sedutaDestinazione !== NULL && $sedutaDestinazione->stato()->definitivo()) {
      $form_state->setErrorByName(
        $entita instanceof Delibera ? 'punto_odg' : 'seduta',
        $this->t('La seduta «@seduta» è @stato: non è più possibile modificarne l\'ordine del giorno.', [
          '@seduta' => $sedutaDestinazione->label(),
          '@stato' => mb_strtolower($sedutaDestinazione->stato()->etichetta()),
        ])
      );
    }

    // La coerenza della scheda è imposta dall'entità al salvataggio, dove
    // però diventa un'eccezione. Anticiparla qui la trasforma in un
    // messaggio di validazione leggibile accanto al campo che la causa.
    if ($entita instanceof Delibera) {
      try {
        $entita->validaScheda();
      }
      catch (\InvalidArgumentException $incoerenza) {
        $form_state->setErrorByName('opzioni', $incoerenza->getMessage());
      }
    }

    return $entita;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $nuova = $this->entity->isNew();
    $esito = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->conferma($nuova));

    $seduta = $this->sedutaDiRiferimento();

    if ($seduta !== NULL) {
      $form_state->setRedirectUrl($seduta->toUrl());
    }
    elseif ($this->entity->getEntityType()->hasLinkTemplate('collection')) {
      $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    }

    return $esito;
  }

  /**
   * Messaggio di conferma del salvataggio.
   *
   * Ogni entità ha il proprio verbo: un participio generico concordato sul
   * nome del tipo produce messaggi sgrammaticati appena i generi differiscono.
   */
  protected function conferma(bool $nuova): \Drupal\Core\StringTranslation\TranslatableMarkup {
    $etichetta = (string) $this->entity->label();

    return match ($this->entity->getEntityTypeId()) {
      'psiphos_seduta' => $nuova
        ? $this->t('Seduta «@etichetta» convocata. Componi ora l\'elenco degli aventi diritto.', ['@etichetta' => $etichetta])
        : $this->t('Seduta «@etichetta» aggiornata.', ['@etichetta' => $etichetta]),
      'psiphos_punto_odg' => $nuova
        ? $this->t('Punto «@etichetta» aggiunto all\'ordine del giorno.', ['@etichetta' => $etichetta])
        : $this->t('Punto «@etichetta» aggiornato.', ['@etichetta' => $etichetta]),
      'psiphos_delibera' => $nuova
        ? $this->t('Delibera «@etichetta» predisposta.', ['@etichetta' => $etichetta])
        : $this->t('Delibera «@etichetta» aggiornata.', ['@etichetta' => $etichetta]),
      default => $this->t('«@etichetta» salvato.', ['@etichetta' => $etichetta]),
    };
  }

  /**
   * Seduta a cui l'entità appena salvata appartiene.
   */
  protected function sedutaDiRiferimento(): ?SedutaInterface {
    return $this->sedutaDi($this->entity);
  }

  /**
   * Seduta a cui un'entità appartiene.
   *
   * Una delibera non conosce ancora la propria seduta finché non è salvata,
   * perché il legame è derivato dal punto: va risalito passando di lì.
   *
   * La seduta si carica dall'archivio e non dal campo di riferimento: quello
   * trattiene l'entità risolta al primo accesso, e continuerebbe a mostrare
   * lo stato che la seduta aveva allora anziché quello attuale.
   */
  private function sedutaDi(?EntityInterface $entita): ?SedutaInterface {
    if ($entita instanceof SedutaInterface) {
      return $entita;
    }

    $identificativo = NULL;

    if ($entita instanceof PuntoOdg) {
      $identificativo = $entita->get('seduta')->target_id;
    }
    elseif ($entita instanceof Delibera) {
      $punto = $entita->get('punto_odg')->entity;
      $identificativo = $punto instanceof PuntoOdg ? $punto->get('seduta')->target_id : NULL;
    }

    if ($identificativo === NULL) {
      return NULL;
    }

    $seduta = $this->entityTypeManager->getStorage('psiphos_seduta')->loadUnchanged($identificativo);

    return $seduta instanceof SedutaInterface ? $seduta : NULL;
  }

}
