<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Enum\EventoAudit;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\psiphos\Exception\VotoNonAmmessoException;

/**
 * Deposito e lettura delle schede di voto.
 *
 * Attua i §§4.1, 4.2 e 4.3 dell'allegato tecnico. Il voto palese e quello a
 * scrutinio segreto non condividono l'archivio: sono due percorsi distinti
 * perché i due paragrafi chiedono l'opposto l'uno dell'altro, tracciabilità
 * piena il primo e separazione irreversibile il secondo. Un unico archivio
 * con un interruttore di anonimato sarebbe, per definizione, reversibile.
 */
final class Urna {

  /**
   * Limite superiore degli identificativi di scheda.
   *
   * Restare sotto i 62 bit tiene il valore dentro un intero con segno anche
   * sulle piattaforme che lo rappresentano così, senza perdere entropia
   * utile: la probabilità di collisione resta trascurabile e in ogni caso
   * il deposito ritenta.
   */
  private const IDENTIFICATIVO_MASSIMO = 4611686018427387903;

  private const TENTATIVI_IDENTIFICATIVO = 5;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $gestoreEntita,
    private readonly TimeInterface $orologio,
    private readonly RegistroAudit $registro,
  ) {}

  /**
   * Deposita la scheda di un avente diritto.
   *
   * @param array<int, string> $voci
   *   Chiavi tecniche delle voci scelte sulla scheda.
   *
   * @throws \Drupal\psiphos\Exception\VotoNonAmmessoException
   */
  public function deposita(DeliberaInterface $delibera, AccountInterface $votante, array $voci): void {
    try {
      if (!$delibera->urnaAperta()) {
        throw VotoNonAmmessoException::urnaChiusa();
      }

      // Anche la seduta deve consentirlo. Che l'urna di una delibera sia
      // aperta mentre la seduta è chiusa è una condizione che non dovrebbe
      // esistere — ed è impedita a monte — ma se esistesse, senza questa
      // verifica la scheda entrerebbe lo stesso: un voto raccolto in una
      // seduta che non c'era più.
      $seduta = $delibera->seduta();
      if ($seduta === NULL || !$seduta->stato()->consenteOperazioniDiVoto()) {
        throw VotoNonAmmessoException::urnaChiusa();
      }

      $this->verificaLegittimazione($delibera, $votante);
      $voci = $this->normalizzaVoci($delibera, $voci);

      $delibera->tipoVoto() === TipoVoto::SEGRETO
        ? $this->depositaSegreto($delibera, $votante, $voci)
        : $this->depositaPalese($delibera, $votante, $voci);
    }
    catch (VotoNonAmmessoException $rifiuto) {
      // Il motivo del rifiuto è tracciato, la scheda respinta no: annotare
      // che cosa qualcuno aveva provato a votare significherebbe conservare
      // un voto associato a un'identità, che è ciò che il §4.3 esclude.
      $this->registro->annota(EventoAudit::VOTO_RIFIUTATO, $this->seduta($delibera), (int) $delibera->id(), [
        'votante' => (int) $votante->id(),
        'motivo' => $rifiuto->getMessage(),
      ]);
      throw $rifiuto;
    }

    $this->registro->annota(EventoAudit::VOTO_DEPOSITATO, $this->seduta($delibera), (int) $delibera->id(), [
      'votante' => (int) $votante->id(),
      'tipo_voto' => $delibera->tipoVoto()->value,
    ]);
  }

  /**
   * Identificativo della seduta della delibera.
   */
  private function seduta(DeliberaInterface $delibera): int {
    return (int) ($delibera->get('seduta')->target_id ?? 0);
  }

  /**
   * Fissa l'elettorato di una votazione: chi è in aula all'apertura.
   *
   * @return int
   *   Numero di aventi diritto ammessi.
   */
  public function fissaElettorato(DeliberaInterface $delibera): int {
    $seduta = $delibera->seduta();

    if ($seduta === NULL || $seduta->isNew()) {
      return 0;
    }

    $presenti = $this->gestoreEntita->getStorage('psiphos_presenza')->loadByProperties([
      'seduta' => $seduta->id(),
      'stato' => StatoPresenza::PRESENTE->value,
    ]);

    foreach ($presenti as $presenza) {
      $this->database->merge('psiphos_ammesso_al_voto')
        ->keys([
          'delibera' => (int) $delibera->id(),
          'utente' => (int) $presenza->get('utente')->target_id,
        ])
        ->execute();
    }

    return count($presenti);
  }

  /**
   * Vero se per la votazione è già stato fissato un elettorato.
   */
  public function elettoratoFissato(DeliberaInterface $delibera): bool {
    return (bool) $this->database->select('psiphos_ammesso_al_voto', 'a')
      ->condition('delibera', $delibera->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Vero se l'avente diritto era in aula quando l'urna è stata aperta.
   *
   * Chi arriva a votazione iniziata non vi partecipa: la maggioranza è
   * calcolata sui presenti all'apertura, e ammetterlo produrrebbe più schede
   * di quante ne prevede il denominatore su cui l'esito si determina.
   */
  public function ammessoAlVoto(DeliberaInterface $delibera, AccountInterface $votante): bool {
    return (bool) $this->database->select('psiphos_ammesso_al_voto', 'a')
      ->condition('delibera', $delibera->id())
      ->condition('utente', $votante->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Vero se l'avente diritto ha già votato su questa delibera.
   */
  public function haVotato(DeliberaInterface $delibera, AccountInterface $votante): bool {
    $tabella = $delibera->tipoVoto() === TipoVoto::SEGRETO ? 'psiphos_attestazione' : 'psiphos_voto_palese';

    return (bool) $this->database->select($tabella, 't')
      ->condition('delibera', $delibera->id())
      ->condition('utente', $votante->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Numero di aventi diritto che hanno partecipato al voto.
   */
  public function numeroVotanti(DeliberaInterface $delibera): int {
    $tabella = $delibera->tipoVoto() === TipoVoto::SEGRETO ? 'psiphos_attestazione' : 'psiphos_voto_palese';

    return (int) $this->database->select($tabella, 't')
      ->condition('delibera', $delibera->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Numero di schede presenti nell'urna.
   *
   * Su una votazione integra coincide con il numero dei votanti. Una
   * divergenza fra i due valori è l'unico modo di accorgersi, dall'esterno,
   * che qualcosa è stato inserito o rimosso: sono conteggi su tabelle
   * diverse, alimentate nella stessa transazione.
   */
  public function numeroSchede(DeliberaInterface $delibera): int {
    if ($delibera->tipoVoto() !== TipoVoto::SEGRETO) {
      return $this->numeroVotanti($delibera);
    }

    return (int) $this->database->select('psiphos_urna', 'u')
      ->condition('delibera', $delibera->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Contenuto delle schede, senza alcun riferimento ai votanti.
   *
   * Ordinato per contenuto e non per identificativo, così nemmeno chi legge
   * attraverso questo metodo ricava un ordine correlabile al deposito.
   *
   * @return array<int, string>
   */
  public function schede(DeliberaInterface $delibera): array {
    if ($delibera->tipoVoto() === TipoVoto::SEGRETO) {
      $risultato = $this->database->select('psiphos_urna', 'u')
        ->fields('u', ['voci'])
        ->condition('delibera', $delibera->id())
        ->orderBy('voci')
        ->execute();
    }
    else {
      $risultato = $this->database->select('psiphos_voto_palese', 'v')
        ->fields('v', ['voci'])
        ->condition('delibera', $delibera->id())
        ->orderBy('voci')
        ->execute();
    }

    return array_map('strval', $risultato->fetchCol());
  }

  /**
   * Registro dei votanti: chi ha partecipato al voto, mai come ha votato.
   *
   * Serve al verbale e alla verifica del quorum. Sul voto palese restituisce
   * anche la scelta espressa, come impone il §4.2.
   *
   * @return array<int, array{utente: int, momento: int, voci: string|null}>
   *   Sul voto segreto il momento è sempre zero e le voci sono nulle: di chi
   *   vota a scrutinio segreto si attesta la partecipazione, non l'istante.
   */
  public function registroVotanti(DeliberaInterface $delibera): array {
    $segreto = $delibera->tipoVoto() === TipoVoto::SEGRETO;

    // Sul voto segreto si ordina per utente e non per momento del deposito:
    // l'ordine di voto è esso stesso un metadato, e restituirlo lo metterebbe
    // a disposizione di qualunque futuro consumatore del registro.
    $interrogazione = $segreto
      ? $this->database->select('psiphos_attestazione', 'a')
        ->fields('a', ['utente'])
        ->condition('delibera', $delibera->id())
        ->orderBy('utente')
      // Sul voto palese si ordina anche per identificativo: la marca temporale
      // ha risoluzione di un secondo, e più voti depositati nello stesso
      // secondo uscirebbero in ordine arbitrario a ogni interrogazione. Un
      // registro che cambia ordine da una lettura all'altra non è un registro.
      : $this->database->select('psiphos_voto_palese', 'v')
        ->fields('v', ['utente', 'registrato_il', 'voci'])
        ->condition('delibera', $delibera->id())
        ->orderBy('registrato_il')
        ->orderBy('id');

    $registro = [];
    foreach ($interrogazione->execute() as $riga) {
      $registro[] = [
        'utente' => (int) $riga->utente,
        'momento' => $segreto ? 0 : (int) $riga->registrato_il,
        'voci' => $segreto ? NULL : (string) $riga->voci,
      ];
    }

    return $registro;
  }

  /**
   * Deposita una scheda segreta.
   *
   * Attestazione e scheda finiscono nella stessa transazione ma in tabelle
   * che non condividono alcuna colonna: o la partecipazione al voto e la
   * scheda esistono entrambe, o non esiste nessuna delle due, e in nessun
   * momento è scritto un dato che le colleghi.
   */
  private function depositaSegreto(DeliberaInterface $delibera, AccountInterface $votante, array $voci): void {
    $transazione = $this->database->startTransaction();

    try {
      // Chi, non quando: la marca temporale dell'attestazione è il metadato
      // che il §4.3 vuole trattato in modo da non consentire nemmeno
      // indirettamente la re-identificazione.
      $this->database->insert('psiphos_attestazione')
        ->fields([
          'delibera' => $delibera->id(),
          'utente' => $votante->id(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // La chiave primaria composta ha respinto un secondo voto: è il
      // presidio di unicità del §4.1 che entra in funzione.
      $transazione->rollBack();
      throw VotoNonAmmessoException::giaVotato();
    }

    $this->inserisciScheda((int) $delibera->id(), implode(',', $voci));
  }

  private function depositaPalese(DeliberaInterface $delibera, AccountInterface $votante, array $voci): void {
    try {
      $this->database->insert('psiphos_voto_palese')
        ->fields([
          'delibera' => $delibera->id(),
          'utente' => $votante->id(),
          'voci' => implode(',', $voci),
          'registrato_il' => $this->orologio->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      throw VotoNonAmmessoException::giaVotato();
    }
  }

  /**
   * Inserisce la scheda con un identificativo casuale.
   */
  private function inserisciScheda(int $delibera, string $voci): void {
    for ($tentativo = 1; $tentativo <= self::TENTATIVI_IDENTIFICATIVO; $tentativo++) {
      try {
        $this->database->insert('psiphos_urna')
          ->fields([
            'id' => random_int(1, self::IDENTIFICATIVO_MASSIMO),
            'delibera' => $delibera,
            'voci' => $voci,
          ])
          ->execute();
        return;
      }
      catch (IntegrityConstraintViolationException $collisione) {
        if ($tentativo === self::TENTATIVI_IDENTIFICATIVO) {
          throw $collisione;
        }
      }
    }
  }

  /**
   * Verifica che il votante sia legittimato a esprimere il voto.
   */
  private function verificaLegittimazione(DeliberaInterface $delibera, AccountInterface $votante): void {
    $seduta = $delibera->seduta();
    if ($seduta === NULL) {
      throw VotoNonAmmessoException::nonAventeDiritto();
    }

    $presenze = $this->gestoreEntita->getStorage('psiphos_presenza')->loadByProperties([
      'seduta' => $seduta->id(),
      'utente' => $votante->id(),
    ]);

    $presenza = reset($presenze);
    if ($presenza === FALSE) {
      throw VotoNonAmmessoException::nonAventeDiritto();
    }

    if ($presenza->stato() !== StatoPresenza::PRESENTE) {
      throw VotoNonAmmessoException::nonPresente();
    }

    if (!$this->ammessoAlVoto($delibera, $votante)) {
      throw VotoNonAmmessoException::sopraggiunto();
    }

    if ($this->haVotato($delibera, $votante)) {
      throw VotoNonAmmessoException::giaVotato();
    }
  }

  /**
   * Verifica le voci scelte e le riduce a forma canonica.
   *
   * La forma canonica è l'insieme delle chiavi, deduplicato e ordinato
   * alfabeticamente: due schede con le stesse preferenze espresse in ordine
   * diverso devono risultare identiche, altrimenti l'ordine in cui il
   * votante ha spuntato le caselle diventerebbe un dato conservato.
   *
   * @param array<int, string> $voci
   *
   * @return array<int, string>
   */
  private function normalizzaVoci(DeliberaInterface $delibera, array $voci): array {
    $voci = array_values(array_unique(array_filter(array_map('strval', $voci), static fn (string $v): bool => $v !== '')));

    if ($voci === []) {
      throw VotoNonAmmessoException::schedaVuota();
    }

    $ammesse = array_keys($delibera->vociScheda());
    foreach ($voci as $voce) {
      if (!in_array($voce, $ammesse, TRUE)) {
        throw VotoNonAmmessoException::voceNonValida($voce);
      }
    }

    if (in_array(SchemaScheda::VOCE_SCHEDA_BIANCA, $voci, TRUE) && count($voci) > 1) {
      throw VotoNonAmmessoException::schedaBiancaNonEsclusiva();
    }

    $massime = in_array(SchemaScheda::VOCE_SCHEDA_BIANCA, $voci, TRUE) ? 1 : $delibera->preferenzeMassime();
    if (count($voci) > $massime) {
      throw VotoNonAmmessoException::troppePreferenze(count($voci), $massime);
    }

    sort($voci, SORT_STRING);

    return $voci;
  }

}
