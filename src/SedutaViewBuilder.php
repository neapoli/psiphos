<?php

declare(strict_types=1);

namespace Drupal\psiphos;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoSeduta;

/**
 * Pagina di una seduta: convocazione, ordine del giorno, esiti.
 *
 * Il rendering per campi delle entità mostrerebbe la convocazione ma non
 * l'ordine del giorno né le delibere, che vivono su entità separate. Per chi
 * consulta una seduta, però, è proprio quella la sostanza.
 */
final class SedutaViewBuilder extends EntityViewBuilder {

  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    assert($entity instanceof SedutaInterface);

    return [
      '#theme' => 'psiphos_seduta',
      '#seduta' => $entity,
      '#dati' => \Drupal::service('psiphos.costruttore_verbale')->struttura($entity),
      '#odg' => $this->ordineDelGiorno($entity),
      '#azioni' => $this->azioni($entity),
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function viewMultiple(array $entities = [], $view_mode = 'full', $langcode = NULL): array {
    $costruzione = [];
    foreach ($entities as $chiave => $entita) {
      $costruzione[$chiave] = $this->view($entita, $view_mode, $langcode);
    }

    return $costruzione;
  }

  /**
   * Ordine del giorno con i comandi di redazione disponibili.
   *
   * I comandi sono filtrati sull'accesso all'entità e non sullo stato della
   * seduta: un punto su cui si è già votato non è più correggibile anche se
   * la seduta è ancora aperta, e la regola sta in un posto solo.
   *
   * @return array<int, array<string, mixed>>
   */
  private function ordineDelGiorno(SedutaInterface $seduta): array {
    if ($seduta->isNew()) {
      return [];
    }

    $gestore = \Drupal::entityTypeManager();
    $archivioPunti = $gestore->getStorage('psiphos_punto_odg');
    $archivioDelibere = $gestore->getStorage('psiphos_delibera');

    $identificativi = $archivioPunti->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->sort('numero')
      ->sort('id')
      ->execute();

    $odg = [];

    foreach ($archivioPunti->loadMultiple($identificativi) as $punto) {
      assert($punto instanceof PuntoOdg);

      $delibere = [];
      $identificativiDelibere = $archivioDelibere->getQuery()
        ->accessCheck(FALSE)
        ->condition('punto_odg', $punto->id())
        ->sort('id')
        ->execute();

      foreach ($archivioDelibere->loadMultiple($identificativiDelibere) as $delibera) {
        assert($delibera instanceof DeliberaInterface);
        $delibere[] = [
          'quesito' => (string) $delibera->label(),
          'numero' => trim((string) $delibera->get('numero_delibera')->value),
          'modalita' => sprintf(
            '%s — %s',
            $delibera->tipoVoto()->etichetta(),
            mb_strtolower($delibera->regolaMaggioranza()->etichetta())
          ),
          'stato' => $delibera->stato()->etichetta(),
          'esito' => $delibera->esito()?->etichettaPer($delibera->schemaScheda()) ?? '',
          'votanti' => (int) $delibera->get('votanti')->value,
          'presenti_al_voto' => (int) $delibera->get('presenti_al_voto')->value,
          // Il conteggio compare solo a urna chiusa. Fino a quel momento è
          // il dato che deve restare invisibile a chiunque; da quel momento
          // è ciò che il verbale riporta e che il collegio ha diritto di
          // conoscere, perché è l'unica motivazione dell'esito proclamato.
          'conteggio' => $this->conteggioLeggibile($delibera),
          'criterio' => \Drupal::service('psiphos.scrutinio')->motivazioneEsito($delibera),
          'comandi' => $this->comandi($delibera),
          'atto' => $this->comandiAtto($delibera),
          // Da una votazione annullata si predispone la ripetizione con il
          // legame già impostato: il §8 vuole che la ripetizione sia
          // riconoscibile come tale, e chiederlo a memoria a chi conduce una
          // seduta significa non ottenerlo quasi mai.
          'ripeti' => $delibera->stato() === StatoDelibera::ANNULLATA && $seduta->access('update')
            ? Link::createFromRoute(
              $this->t('Predisponi la ripetizione'),
              'entity.psiphos_delibera.add_form',
              [],
              [
                'query' => [
                  'punto_odg' => $punto->id(),
                  'ripetizione_di' => $delibera->id(),
                  'destination' => $seduta->toUrl()->toString(),
                ],
                'attributes' => ['class' => ['button']],
              ]
            )
            : NULL,
        ];
      }

      $odg[] = [
        'numero' => (int) $punto->get('numero')->value,
        'oggetto' => (string) $punto->label(),
        // Le scuole citano il punto insieme al numero della delibera che ne
        // è uscita — «3. Approvazione PAI 2025 (Delibera n. 35)» — perché è
        // così che il punto viene poi richiamato negli atti successivi.
        'numeri_delibera' => array_values(array_filter(array_column($delibere, 'numero'))),
        'illustrazione' => trim((string) $punto->get('descrizione')->value),
        'illustrazione_formato' => (string) ($punto->get('descrizione')->first()?->format ?? ''),
        'deliberativo' => $punto->deliberativo(),
        'comandi' => $this->comandi($punto),
        'aggiungi_delibera' => $punto->deliberativo() && $seduta->access('update')
          ? Link::createFromRoute(
            $this->t('Predisponi una delibera'),
            'entity.psiphos_delibera.add_form',
            [],
            [
              'query' => ['punto_odg' => $punto->id(), 'destination' => $seduta->toUrl()->toString()],
              'attributes' => ['class' => ['button']],
            ]
          )
          : NULL,
        'delibere' => $delibere,
      ];
    }

    return $odg;
  }

  /**
   * Comandi relativi all'atto di una delibera.
   *
   * Redazione e scarico si escludono nel tempo: finché l'atto è redigibile
   * non esiste ancora un estratto, e quando l'estratto esiste l'atto non è
   * più redigibile. Il collegamento presente dice perciò da solo a che punto
   * è la delibera.
   *
   * @return array<int, \Drupal\Core\Link>
   */
  private function comandiAtto(DeliberaInterface $delibera): array {
    $comandi = [];

    if (!$delibera->daFormalizzare()) {
      return $comandi;
    }

    $redazione = Url::fromRoute('psiphos.delibera.atto', ['psiphos_delibera' => $delibera->id()], [
      'query' => ['destination' => \Drupal::request()->getRequestUri()],
    ]);

    if ($redazione->access()) {
      $comandi[] = Link::fromTextAndUrl(
        $delibera->lacuneAtto() === []
          ? $this->t("Modifica l'atto")
          // Il seguito dice che cosa manca, e deve dirlo per esteso: un
          // elenco fra parentesi si legge come un'apposizione — «redigi
          // l'atto, cioè il dispositivo» — che è il contrario del significato.
          : $this->t("Redigi l'atto: @lacune", ['@lacune' => $delibera->descrizioneLacune()]),
        $redazione
      );
    }

    if ($delibera->attoSigillato() && $delibera->get('documento')->target_id !== NULL) {
      $comandi[] = Link::fromTextAndUrl(
        $this->t("Scarica l'estratto di delibera"),
        Url::fromRoute('psiphos.delibera.documento', ['psiphos_delibera' => $delibera->id()])
      );
    }

    return $comandi;
  }

  /**
   * Conteggio dello scrutinio con le voci in chiaro.
   *
   * @return array<int, array{voce: string, voti: int, prevalente: bool}>
   */
  private function conteggioLeggibile(DeliberaInterface $delibera): array {
    if ($delibera->esito() === NULL) {
      return [];
    }

    $voci = $delibera->vociScheda();
    $prevalenti = [];
    foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
      $prevalenti[] = (string) $elemento->value;
    }

    $righe = [];
    foreach ($delibera->conteggio() as $chiave => $voti) {
      $righe[] = [
        'voce' => $voci[$chiave] ?? $chiave,
        'voti' => $voti,
        'prevalente' => in_array($chiave, $prevalenti, TRUE),
      ];
    }

    return $righe;
  }

  /**
   * Comandi di modifica e cancellazione ammessi su un'entità.
   *
   * Restano collegamenti e non pulsanti: sono operazioni su una riga, come
   * nelle tabelle amministrative di Drupal, mentre i pulsanti segnalano le
   * azioni che creano qualcosa o portano altrove. La distinzione dice al
   * lettore che cosa aspettarsi prima che clicchi.
   */
  private function comandi(\Drupal\Core\Entity\EntityInterface $entita): array {
    $comandi = [];
    $ritorno = ['query' => ['destination' => \Drupal::request()->getRequestUri()]];

    if ($entita->access('update') && $entita->hasLinkTemplate('edit-form')) {
      $comandi[] = Link::fromTextAndUrl($this->t('Modifica'), $entita->toUrl('edit-form', $ritorno));
    }

    if ($entita->access('delete') && $entita->hasLinkTemplate('delete-form')) {
      $comandi[] = Link::fromTextAndUrl($this->t('Elimina'), $entita->toUrl('delete-form', $ritorno));
    }

    return $comandi;
  }

  /**
   * Collegamenti operativi disponibili sulla seduta.
   *
   * @return array<int, \Drupal\Core\Link>
   */
  private function azioni(SedutaInterface $seduta): array {
    $azioni = [];

    if ($seduta->access('update')) {
      $azioni[] = Link::createFromRoute(
        $this->t("Aggiungi un punto all'ordine del giorno"),
        'entity.psiphos_punto_odg.add_form',
        [],
        [
          'query' => ['seduta' => $seduta->id(), 'destination' => $seduta->toUrl()->toString()],
          'attributes' => ['class' => ['button']],
        ]
      );

    }

    if ($seduta->stato() !== StatoSeduta::CONVOCATA || $seduta->access('update')) {
      // L'ingresso in aula è l'azione principale della pagina durante una
      // seduta: si distingue dalle altre, che sono di redazione.
      $azioni[] = Link::fromTextAndUrl(
        $this->t('Entra in aula'),
        Url::fromRoute('psiphos.aula', ['psiphos_seduta' => $seduta->id()], [
          'attributes' => ['class' => ['button', 'button--primary']],
        ])
      );
    }

    return $azioni;
  }

}
