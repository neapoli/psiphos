<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\SedutaInterface;
use Drupal\psiphos\Enum\EventoAudit;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoSeduta;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ingresso, permanenza e uscita degli aventi diritto dall'aula virtuale.
 *
 * Attua il §3.4 dell'allegato tecnico: tracciamento delle sessioni attive,
 * interruzione automatica in caso di inattività, prevenzione di accessi
 * simultanei non autorizzati.
 *
 * L'inattività è qui assenza di contatto, non assenza di interazione. Una
 * seduta collegiale dura ore, e per quasi tutto quel tempo chi partecipa
 * ascolta: segue la videoconferenza e non tocca l'aula, che è uno strumento
 * di voto e non il luogo della discussione. Pretendere un'interazione per
 * restare presenti farebbe decadere il collegio intero nel mezzo del
 * dibattito.
 *
 * Il segnale di abbandono è che il collegamento cessi. Finché la pagina
 * dell'aula è aperta e continua a farsi viva, la persona è nella seduta come
 * lo sarebbe restando seduta in una stanza; quando la pagina viene chiusa, il
 * dispositivo si spegne o la rete cade, i segnali smettono di arrivare e la
 * presenza decade da sé, come richiede il §3.4.
 */
final class Aula {

  public function __construct(
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly ConfigFactoryInterface $configurazione,
    private readonly TimeInterface $orologio,
    private readonly RequestStack $richieste,
    private readonly RegistroAudit $registro,
  ) {}

  /**
   * Registra l'ingresso in aula di un avente diritto.
   *
   * @return \Drupal\psiphos\Entity\Presenza|null
   *   NULL se non figura nell'elenco degli aventi diritto.
   */
  public function entra(SedutaInterface $seduta, AccountInterface $utente): ?Presenza {
    $presenza = $this->presenza($seduta, $utente);
    if ($presenza === NULL || !$seduta->stato()->consenteOperazioniDiVoto()) {
      return NULL;
    }

    $adesso = $this->orologio->getRequestTime();
    $eraPresente = $presenza->concorreAlQuorum();
    $improntaPrecedente = (string) $presenza->get('impronta_sessione')->value;

    if ($presenza->get('ingresso')->value === NULL) {
      $presenza->set('ingresso', $adesso);
    }

    // L'ingresso da un nuovo dispositivo sostituisce la sessione precedente:
    // l'impronta cambia e la sessione superata smette di essere riconosciuta.
    $presenza->set('stato', StatoPresenza::PRESENTE->value)
      ->set('uscita', NULL)
      ->set('ultima_attivita', $adesso)
      ->set('impronta_sessione', $improntaCorrente = $this->improntaSessioneCorrente())
      ->save();

    if ($eraPresente && $improntaPrecedente !== '' && $improntaPrecedente !== $improntaCorrente) {
      $this->registro->annota(EventoAudit::AULA_SESSIONE_SOSTITUITA, (int) $seduta->id(), 0, [
        'avente_diritto' => (int) $utente->id(),
      ]);
    }
    elseif (!$eraPresente) {
      $this->registro->annota(EventoAudit::AULA_INGRESSO, (int) $seduta->id(), 0, [
        'avente_diritto' => (int) $utente->id(),
      ]);
    }

    return $presenza;
  }

  /**
   * Registra l'uscita volontaria dall'aula.
   */
  public function esci(SedutaInterface $seduta, AccountInterface $utente): void {
    $presenza = $this->presenza($seduta, $utente);

    if ($presenza === NULL || !$presenza->concorreAlQuorum()) {
      return;
    }

    $this->registraUscita($presenza, (int) $seduta->id(), (int) $utente->id(), 'uscita');
  }

  /**
   * Fa uscire un avente diritto da ogni seduta in cui risulti presente.
   *
   * Serve alla disconnessione dal sito. L'interruzione per inattività
   * prevista dal §3.4 presidia chi abbandona la seduta senza dirlo, ma chi
   * si disconnette lo sta dicendo: attendere altri quindici minuti prima di
   * toglierlo dal computo significherebbe deliberare, in quell'intervallo,
   * su un quorum che comprende chi ha già lasciato.
   *
   * @return int
   *   Numero di sedute da cui l'avente diritto è uscito.
   */
  public function abbandonaOgniSeduta(AccountInterface $utente): int {
    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');

    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('utente', $utente->id())
      ->condition('stato', StatoPresenza::PRESENTE->value)
      ->execute();

    if ($identificativi === []) {
      return 0;
    }

    $archivioSedute = $this->gestoreEntita->getStorage('psiphos_seduta');
    $uscite = 0;

    foreach ($archivio->loadMultiple($identificativi) as $presenza) {
      $seduta = $archivioSedute->load($presenza->get('seduta')->target_id);

      // Una seduta non più aperta non ha presenze da aggiornare: il registro
      // di quella seduta è già consolidato e non va toccato.
      if (!$seduta instanceof SedutaInterface || !$seduta->stato()->consenteOperazioniDiVoto()) {
        continue;
      }

      $this->registraUscita($presenza, (int) $seduta->id(), (int) $utente->id(), 'disconnessione');
      $uscite++;
    }

    return $uscite;
  }

  /**
   * Registra l'uscita, annotandone la causa.
   */
  private function registraUscita(Presenza $presenza, int $seduta, int $utente, string $causa): void {
    $presenza->set('stato', StatoPresenza::USCITO->value)
      ->set('uscita', $this->orologio->getRequestTime())
      ->set('impronta_sessione', NULL)
      ->save();

    $this->registro->annota(EventoAudit::AULA_USCITA, $seduta, 0, [
      'avente_diritto' => $utente,
      'causa' => $causa,
    ]);
  }

  /**
   * Rinnova la permanenza in aula a fronte di un segnale dal dispositivo.
   *
   * Ogni interrogazione dello stato è un segnale: dichiara che la pagina
   * dell'aula è ancora aperta su quel dispositivo e su quella sessione.
   */
  public function rinnova(SedutaInterface $seduta, AccountInterface $utente): void {
    $presenza = $this->presenza($seduta, $utente);
    if ($presenza === NULL || !$presenza->concorreAlQuorum() || !$this->sessioneRiconosciuta($presenza)) {
      return;
    }

    $presenza->set('ultima_attivita', $this->orologio->getRequestTime())->save();
  }

  /**
   * Vero se la sessione da cui arriva la richiesta è quella accreditata.
   *
   * Richiede un accreditamento in essere e coincidente: senza, non si vota.
   */
  public function sessioneRiconosciuta(Presenza $presenza): bool {
    if (!$this->configurazione->get('psiphos.settings')->get('sessione.sessione_esclusiva')) {
      return TRUE;
    }

    $accreditata = (string) $presenza->get('impronta_sessione')->value;

    return $accreditata !== '' && hash_equals($accreditata, $this->improntaSessioneCorrente());
  }

  /**
   * Vero se questa sessione è stata soppiantata da un'altra.
   *
   * Va tenuto distinto dal semplice non essere accreditati: chi non è ancora
   * entrato in aula, o vi si affaccia mentre la seduta è solo convocata, non
   * ha alcun accreditamento e non è in conflitto con nessuno. Segnalarglielo
   * come un doppio collegamento gli farebbe cercare un dispositivo che non
   * esiste, e ricaricare la pagina non cambierebbe nulla.
   */
  public function sessioneSuperata(Presenza $presenza): bool {
    if (!$this->configurazione->get('psiphos.settings')->get('sessione.sessione_esclusiva')) {
      return FALSE;
    }

    $accreditata = (string) $presenza->get('impronta_sessione')->value;

    return $accreditata !== '' && !hash_equals($accreditata, $this->improntaSessioneCorrente());
  }

  /**
   * Fa decadere le presenze rimaste inattive oltre la soglia configurata.
   *
   * @return int
   *   Numero di presenze decadute.
   */
  public function decadiPresenzeScadute(SedutaInterface $seduta): int {
    if (!$seduta->stato()->consenteOperazioniDiVoto()) {
      return 0;
    }

    $soglia = $this->orologio->getRequestTime() - $this->timeoutInattivita();
    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');

    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('stato', StatoPresenza::PRESENTE->value)
      ->condition('ultima_attivita', $soglia, '<')
      ->execute();

    if ($identificativi === []) {
      return 0;
    }

    foreach ($archivio->loadMultiple($identificativi) as $presenza) {
      $presenza->set('stato', StatoPresenza::DECADUTA->value)
        ->set('uscita', $this->orologio->getRequestTime())
        ->set('impronta_sessione', NULL)
        ->save();

      $this->registro->annota(EventoAudit::AULA_DECADENZA, (int) $seduta->id(), 0, [
        'avente_diritto' => (int) $presenza->get('utente')->target_id,
        'senza_contatto_da_secondi' => $this->timeoutInattivita(),
      ]);
    }

    return count($identificativi);
  }

  /**
   * Registra l'uscita di chi è ancora in aula quando i lavori si chiudono.
   *
   * Chi resta collegato fino alla fine non compie alcun gesto di uscita, e il
   * registro conserverebbe l'ora di ingresso e nessuna ora di uscita: nel
   * verbale si leggerebbe come un dato mancante anziché come la circostanza
   * normale di chi ha partecipato all'intera seduta.
   *
   * La posizione resta «presente»: è quella con cui la persona ha concluso i
   * lavori, e distinguerla da chi è uscito prima o è decaduto è precisamente
   * ciò che il registro deve dire. Cambia solo il momento in cui la presenza
   * si è conclusa, che è la chiusura stessa.
   *
   * @return int
   *   Numero di presenze chiuse.
   */
  public function chiudiPresenze(SedutaInterface $seduta): int {
    $archivio = $this->gestoreEntita->getStorage('psiphos_presenza');

    $identificativi = $archivio->getQuery()
      ->accessCheck(FALSE)
      ->condition('seduta', $seduta->id())
      ->condition('stato', StatoPresenza::PRESENTE->value)
      ->notExists('uscita')
      ->execute();

    if ($identificativi === []) {
      return 0;
    }

    $conclusione = (int) ($seduta->get('chiusa_il')->value ?? $this->orologio->getRequestTime());

    foreach ($archivio->loadMultiple($identificativi) as $presenza) {
      $presenza->set('uscita', $conclusione)
        ->set('impronta_sessione', NULL)
        ->save();
    }

    return count($identificativi);
  }

  /**
   * Come si legge il quorum costitutivo nello stato in cui è la seduta.
   *
   * Prima dell'apertura nessuno è ancora entrato, e dire che il quorum non è
   * raggiunto sarebbe vero quanto inutile: si leggerebbe come un impedimento
   * quando è semplicemente il momento in cui i lavori non sono cominciati.
   */
  public function etichettaQuorum(SedutaInterface $seduta): string {
    return match (TRUE) {
      $seduta->stato() === StatoSeduta::CONVOCATA => (string) t("si verifica all'apertura"),
      $seduta->validamenteCostituita() => (string) t('raggiunto'),
      default => (string) t('non raggiunto'),
    };
  }

  /**
   * Vero se il quorum costitutivo manca in un momento in cui dovrebbe esserci.
   */
  public function quorumInDifetto(SedutaInterface $seduta): bool {
    return $seduta->stato() !== StatoSeduta::CONVOCATA && !$seduta->validamenteCostituita();
  }

  /**
   * Secondi di inattività oltre i quali la presenza decade.
   */
  public function timeoutInattivita(): int {
    return (int) $this->configurazione->get('psiphos.settings')->get('sessione.timeout_inattivita');
  }

  /**
   * Posizione di un avente diritto rispetto alla seduta.
   */
  public function presenza(SedutaInterface $seduta, AccountInterface $utente): ?Presenza {
    if ($seduta->isNew()) {
      return NULL;
    }

    $trovate = $this->gestoreEntita->getStorage('psiphos_presenza')->loadByProperties([
      'seduta' => $seduta->id(),
      'utente' => $utente->id(),
    ]);

    $presenza = reset($trovate);

    return $presenza instanceof Presenza ? $presenza : NULL;
  }

  /**
   * Vero se l'utente può esprimere il voto in questo momento.
   */
  public function abilitatoAlVoto(SedutaInterface $seduta, AccountInterface $utente): bool {
    if ($seduta->stato() !== StatoSeduta::APERTA) {
      return FALSE;
    }

    $presenza = $this->presenza($seduta, $utente);

    return $presenza !== NULL
      && $presenza->concorreAlQuorum()
      && $this->sessioneRiconosciuta($presenza);
  }

  /**
   * Impronta della sessione da cui proviene la richiesta corrente.
   */
  private function improntaSessioneCorrente(): string {
    $richiesta = $this->richieste->getCurrentRequest();
    if ($richiesta === NULL || !$richiesta->hasSession()) {
      return '';
    }

    return Presenza::improntaSessione($richiesta->getSession()->getId());
  }

}
