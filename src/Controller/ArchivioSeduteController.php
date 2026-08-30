<?php

declare(strict_types=1);

namespace Drupal\psiphos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * L'archivio delle sedute di chi guarda.
 *
 * Il blocco sulla scrivania mostra le poche sedute su cui si debba agire, e
 * per questo si ferma alle prime. Ma i verbali restano nel sito per anni, e
 * chi vi ha partecipato deve poterli ritrovare: l'elenco amministrativo è di
 * chi convoca, e un docente non vi accede.
 *
 * Qui l'elenco è per singola persona e vale la stessa regola che governa i
 * verbali: si vedono le sedute in cui si figura fra gli aventi diritto, e
 * nessun'altra.
 */
final class ArchivioSeduteController extends ControllerBase {

  private const PER_PAGINA = 25;

  /**
   * Le opzioni dei filtri, calcolate una volta per richiesta.
   *
   * Proprietà d'istanza e non memoria statica: una statica sopravvive al
   * controllore e quindi all'utente, e in un processo che serve più utenti —
   * le verifiche da riga di comando lo fanno — proporrebbe a ciascuno gli
   * anni scolastici del primo.
   *
   * @var array<string, array<string, string>>|null
   */
  private ?array $opzioniMemorizzate = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly AccountInterface $utente,
    private readonly DateFormatterInterface $formattatoreData,
    private readonly PagerManagerInterface $impaginatore,
    private readonly RequestStack $richieste,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
      $container->get('request_stack'),
    );
  }

  public function elenco(): array {
    $filtri = $this->filtri();
    $identificativi = $this->sedutePartecipate($filtri);
    $totale = count($identificativi);
    $pagina = $this->impaginatore->createPager($totale, self::PER_PAGINA)->getCurrentPage();
    $porzione = array_slice($identificativi, $pagina * self::PER_PAGINA, self::PER_PAGINA);

    $voci = [];
    foreach ($this->gestoreEntita->getStorage('psiphos_seduta')->loadMultiple($porzione) as $seduta) {
      if ($seduta instanceof SedutaInterface) {
        $voci[(int) $seduta->id()] = $this->voce($seduta);
      }
    }

    // loadMultiple non conserva l'ordine degli identificativi richiesti.
    $ordinate = [];
    foreach ($porzione as $identificativo) {
      if (isset($voci[$identificativo])) {
        $ordinate[] = $voci[$identificativo];
      }
    }

    return [
      '#theme' => 'psiphos_archivio_sedute',
      '#sedute' => $ordinate,
      '#totale' => $totale,
      '#filtri_resi' => [
        '#theme' => 'psiphos_filtri_sedute',
        '#filtri' => $filtri,
        '#opzioni' => $this->opzioni(),
        '#filtrato' => array_filter($filtri) !== [],
        '#azione' => Url::fromRoute('psiphos.archivio_sedute')->toString(),
      ],
      '#filtrato' => array_filter($filtri) !== [],
      '#ritorno' => [
        '#theme' => 'psiphos_ritorno_dashboard',
        '#indirizzo' => $this->dashboard(),
      ],
      '#impaginatore' => ['#type' => 'pager'],
      '#attached' => ['library' => ['psiphos/verbale']],
      '#cache' => ['max-age' => 0, 'contexts' => ['user', 'url.query_args']],
    ];
  }

  /**
   * Il ritorno alla dashboard, se chi guarda può raggiungerla.
   *
   * L'archivio si apre dal blocco sulla dashboard, e senza una via di ritorno
   * ci si arriva e si resta: il tasto indietro del browser non è una via di
   * navigazione, è un rimedio.
   */
  private function dashboard(): ?Url {
    try {
      $indirizzo = Url::fromUserInput('/admin/dashboard');
    }
    catch (\Throwable) {
      return NULL;
    }

    if (!$indirizzo->isRouted()) {
      return NULL;
    }

    return $indirizzo->access($this->utente) ? $indirizzo : NULL;
  }

  /**
   * Gli identificativi delle sedute che riguardano chi guarda.
   *
   * Non solo quelle in cui si figura fra gli aventi diritto: anche quelle che
   * si presiede, che si verbalizza e che si è convocate. Un coordinatore che
   * convoca il Consiglio della propria classe senza figurare nell'elenco lo
   * perderebbe di vista il giorno dopo, e l'elenco amministrativo è di chi
   * amministra il sito, non di chi ha convocato quella seduta.
   *
   * Non è un allargamento della visibilità: sono sedute cui si accede già per
   * il ruolo ricoperto. Quel che resta precluso resta precluso.
   *
   * Ordinate dalla più recente: un archivio si consulta a ritroso.
   *
   * @return array<int, int>
   */
  private function sedutePartecipate(array $filtri = []): array {
    $mio = (int) $this->utente->id();
    $deposito = $this->gestoreEntita->getStorage('psiphos_seduta');

    $presenze = $this->gestoreEntita->getStorage('psiphos_presenza');
    $trovate = $presenze->getQuery()
      ->accessCheck(FALSE)
      ->condition('utente', $mio)
      ->execute();

    $riferimenti = [];
    foreach ($presenze->loadMultiple($trovate) as $presenza) {
      $riferimento = (int) ($presenza->get('seduta')->target_id ?? 0);
      if ($riferimento > 0) {
        $riferimenti[$riferimento] = $riferimento;
      }
    }

    $perRuolo = $deposito->getQuery()->accessCheck(FALSE);
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

    $interrogazione = $deposito->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $riferimenti, 'IN')
      ->condition('stato', StatoSeduta::ANNULLATA->value, '<>')
      ->sort('data_seduta', 'DESC')
      ->sort('id', 'DESC');

    if (($filtri['oggetto'] ?? '') !== '') {
      $interrogazione->condition('titolo', $filtri['oggetto'], 'CONTAINS');
    }
    foreach (['organo' => 'organo', 'anno' => 'anno_scolastico', 'stato' => 'stato'] as $chiave => $campo) {
      if (($filtri[$chiave] ?? '') !== '') {
        $interrogazione->condition($campo, $filtri[$chiave]);
      }
    }

    $ordinate = array_map('intval', array_values($interrogazione->execute()));

    // Il ruolo non è un campo: si ricava dal confronto fra la seduta e chi
    // guarda, e va quindi filtrato dopo. Si carica solo quando serve.
    if (($filtri['ruolo'] ?? '') !== '') {
      $atteso = (string) $filtri['ruolo'];
      $trattenute = [];
      foreach ($deposito->loadMultiple($ordinate) as $seduta) {
        if ($seduta instanceof SedutaInterface && $this->titolo($seduta) === $atteso) {
          $trattenute[(int) $seduta->id()] = TRUE;
        }
      }
      $ordinate = array_values(array_filter($ordinate, static fn (int $id): bool => isset($trattenute[$id])));
    }

    return $ordinate;
  }

  /**
   * I filtri richiesti, ripuliti di quanto non riconosciuto.
   *
   * @return array<string, string>
   */
  private function filtri(): array {
    $richiesta = $this->richieste->getCurrentRequest();
    $letto = static fn (string $chiave): string => $richiesta === NULL
      ? ''
      : trim((string) $richiesta->query->get($chiave, ''));

    $opzioni = $this->opzioni();
    $ammesso = static fn (string $chiave, array $valide): string =>
      isset($valide[$chiave]) ? $chiave : '';

    return [
      'oggetto' => mb_substr($letto('oggetto'), 0, 128),
      'organo' => $ammesso($letto('organo'), $opzioni['organi']),
      'anno' => $ammesso($letto('anno'), $opzioni['anni']),
      'stato' => $ammesso($letto('stato'), $opzioni['stati']),
      'ruolo' => $ammesso($letto('ruolo'), $opzioni['ruoli']),
    ];
  }

  /**
   * Le opzioni dei filtri.
   *
   * Gli anni scolastici si ricavano dalle sedute di chi guarda: un elenco
   * costruito a priori proporrebbe anni in cui non è stato convocato nulla,
   * e chi lo usa scoprirebbe di aver filtrato su un insieme vuoto.
   *
   * @return array<string, array<string, string>>
   */
  private function opzioni(): array {
    if ($this->opzioniMemorizzate !== NULL) {
      return $this->opzioniMemorizzate;
    }

    $organi = [];
    foreach (TipoOrgano::cases() as $caso) {
      $organi[$caso->value] = $caso->etichetta();
    }

    $stati = [];
    foreach (StatoSeduta::cases() as $caso) {
      if ($caso !== StatoSeduta::ANNULLATA) {
        $stati[$caso->value] = $caso->etichetta();
      }
    }

    $anni = [];
    foreach ($this->gestoreEntita->getStorage('psiphos_seduta')->loadMultiple($this->sedutePartecipate()) as $seduta) {
      $anno = trim((string) $seduta->get('anno_scolastico')->value);
      if ($anno !== '') {
        $anni[$anno] = $anno;
      }
    }
    krsort($anni);

    return $this->opzioniMemorizzate = [
      'organi' => $organi,
      'anni' => $anni,
      'stati' => $stati,
      'ruoli' => [
        'componente' => (string) $this->t('Componente'),
        'presidente' => (string) $this->t('Presidente'),
        'segretario' => (string) $this->t('Segretario'),
        'convocante' => (string) $this->t('Convocante'),
      ],
    ];
  }

  /**
   * Il titolo a cui la seduta riguarda chi guarda, in forma di chiave.
   */
  private function titolo(SedutaInterface $seduta): string {
    $mio = (int) $this->utente->id();

    return match (TRUE) {
      (int) ($seduta->get('presidente')->target_id ?? 0) === $mio => 'presidente',
      (int) ($seduta->get('segretario')->target_id ?? 0) === $mio => 'segretario',
      $this->fraGliAventiDiritto($seduta) => 'componente',
      (int) ($seduta->get('uid')->target_id ?? 0) === $mio => 'convocante',
      default => '',
    };
  }

  /**
   * A che titolo la seduta riguarda chi guarda.
   *
   * L'ordine conta: chi presiede è anche componente, e la colonna deve
   * riportare il titolo che impegna, non il primo che risulti vero.
   */
  private function ruolo(SedutaInterface $seduta): string {
    return $this->opzioni()['ruoli'][$this->titolo($seduta)] ?? '';
  }

  private function fraGliAventiDiritto(SedutaInterface $seduta): bool {
    $trovate = $this->gestoreEntita->getStorage('psiphos_presenza')->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('utente', (int) $this->utente->id())
      ->range(0, 1)
      ->execute();

    return $trovate !== [];
  }

  /**
   * @return array<string, mixed>
   */
  private function voce(SedutaInterface $seduta): array {
    $stato = $seduta->stato();

    return [
      'quando' => ($momento = (int) $seduta->get('data_seduta')->value) > 0
        ? $this->formattatoreData->format($momento, 'custom', 'j F Y, H:i')
        : '',
      'anno' => (string) $seduta->get('anno_scolastico')->value,
      'titolo' => Link::fromTextAndUrl($seduta->label(), $seduta->toUrl()),
      'organo' => $seduta->organo()->etichetta(),
      'stato' => $stato->value,
      'stato_etichetta' => $stato->etichetta(),
      'ruolo' => $this->ruolo($seduta),
      'verbale' => $this->collegamentoVerbale($seduta),
    ];
  }

  private function collegamentoVerbale(SedutaInterface $seduta): ?Link {
    if (!in_array($seduta->stato(), [StatoSeduta::CHIUSA, StatoSeduta::VERBALIZZATA], TRUE)) {
      return NULL;
    }

    $indirizzo = Url::fromRoute('psiphos.seduta.verbale', ['psiphos_seduta' => $seduta->id()]);

    return $indirizzo->access($this->utente)
      ? Link::fromTextAndUrl($this->t('Verbale'), $indirizzo)
      : NULL;
  }

}
