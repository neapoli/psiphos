<?php

declare(strict_types=1);

namespace Drupal\psiphos\Service;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\psiphos\Entity\DeliberaInterface;
use Drupal\psiphos\Enum\EsitoDelibera;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;

/**
 * Conteggio delle schede, determinazione dell'esito e sigillo dell'urna.
 *
 * Il §4.3 chiede due cose che si contraddicono solo in apparenza: che
 * l'esito complessivo sia verificabile e che i voti individuali restino
 * segreti. Il sigillo le tiene insieme perché è calcolato sull'insieme
 * ordinato delle schede, non sulla loro sequenza: chiunque possa rileggere
 * l'urna può ricalcolarlo e confrontarlo con quello registrato, e scoprire
 * così qualsiasi scheda aggiunta, rimossa o alterata dopo la chiusura, senza
 * che l'ordine di deposito venga mai conservato da nessuna parte.
 *
 * È la ragione per cui le schede non sono concatenate in una catena di
 * hash, che pure sarebbe la scelta abituale per garantire integrità: una
 * catena impone un ordine, e un ordine è esattamente il metadato che il
 * §4.3 vieta di conservare.
 */
final class Scrutinio {

  public function __construct(private readonly Urna $urna) {}

  /**
   * Conta le schede depositate, voce per voce.
   *
   * Le voci che non hanno ricevuto voti compaiono con valore zero: un
   * conteggio parziale renderebbe indistinguibile l'opzione non votata da
   * quella mai presente sulla scheda.
   *
   * @return array<string, int>
   */
  public function conta(DeliberaInterface $delibera): array {
    $conteggio = array_fill_keys(array_keys($delibera->vociScheda()), 0);

    foreach ($this->urna->schede($delibera) as $scheda) {
      foreach (explode(',', $scheda) as $voce) {
        if (array_key_exists($voce, $conteggio)) {
          $conteggio[$voce]++;
        }
      }
    }

    return $conteggio;
  }

  /**
   * Determina l'esito della votazione a partire dal conteggio.
   *
   * @param array<string, int> $conteggio
   *
   * @return array{esito: \Drupal\psiphos\Enum\EsitoDelibera, prevalenti: array<int, string>}
   */
  public function determinaEsito(DeliberaInterface $delibera, array $conteggio): array {
    $presenti = (int) $delibera->get('presenti_al_voto')->value;
    $aventiDiritto = (int) $delibera->get('aventi_diritto_al_voto')->value;
    $regola = $delibera->regolaMaggioranza();
    $schema = $delibera->schemaScheda();

    if ($this->urna->numeroVotanti($delibera) === 0) {
      return ['esito' => EsitoDelibera::QUORUM_NON_RAGGIUNTO, 'prevalenti' => []];
    }

    if (!$schema->richiedeOpzioni()) {
      $favorevoli = $conteggio[SchemaScheda::VOCE_FAVOREVOLE] ?? 0;
      $contrari = $conteggio[SchemaScheda::VOCE_CONTRARIO] ?? 0;
      $approvata = $regola->approvata($favorevoli, $contrari, $presenti, $aventiDiritto);

      return [
        'esito' => $approvata ? EsitoDelibera::APPROVATA : EsitoDelibera::RESPINTA,
        'prevalenti' => $approvata ? [SchemaScheda::VOCE_FAVOREVOLE] : [],
      ];
    }

    // Su una scheda a scelta la regola di maggioranza si applica a ciascuna
    // opzione. I contrari si contano per votanti e non per preferenze: su
    // una scheda a più preferenze la somma delle preferenze altrui è un
    // multiplo dei votanti, e confrontarla con i voti di una singola opzione
    // renderebbe irraggiungibile qualsiasi soglia. Sono contrari a
    // un'opzione i votanti che non le hanno dato la propria preferenza.
    $vociDiPreferenza = $schema->vociDiPreferenza($delibera->opzioni());
    $preferenze = array_intersect_key($conteggio, array_flip($vociDiPreferenza));
    $votantiConPreferenza = $this->votantiConPreferenza($delibera, $vociDiPreferenza);

    $prevalenti = [];
    foreach ($preferenze as $voce => $voti) {
      if ($regola->approvata($voti, $votantiConPreferenza - $voti, $presenti, $aventiDiritto)) {
        $prevalenti[] = $voce;
      }
    }

    // A parità di condizioni si proclamano solo le opzioni più votate, fino
    // al numero di posti da assegnare: senza questo taglio una maggioranza
    // semplice potrebbe proclamare più eletti dei posti disponibili.
    $prevalenti = $this->limitaAiPostiDisponibili($prevalenti, $preferenze, $delibera->preferenzeMassime());

    return [
      'esito' => $prevalenti === [] ? EsitoDelibera::RESPINTA : EsitoDelibera::APPROVATA,
      'prevalenti' => $prevalenti,
    ];
  }

  /**
   * Chiude l'urna, registra lo scrutinio sulla delibera e la sigilla.
   *
   * @throws \RuntimeException
   *   Se schede e votanti non coincidono: è la sola avvisaglia disponibile
   *   di una manomissione dell'urna, e va fermata prima di consolidare un
   *   esito costruito su dati incoerenti.
   */
  public function chiudiEScrutina(DeliberaInterface $delibera): DeliberaInterface {
    $votanti = $this->urna->numeroVotanti($delibera);
    $schede = $this->urna->numeroSchede($delibera);

    if ($votanti !== $schede) {
      throw new \RuntimeException(sprintf(
        'Scrutinio interrotto sulla delibera %s: %d votanti registrati a fronte di %d schede nell\'urna.',
        $delibera->id(),
        $votanti,
        $schede
      ));
    }

    $conteggio = $this->conta($delibera);
    $risultato = $this->determinaEsito($delibera, $conteggio);

    $delibera->transitaA(StatoDelibera::CHIUSA);
    $delibera->set('conteggio', $conteggio);
    $delibera->set('votanti', $votanti);
    $delibera->set('esito', $risultato['esito']->value);
    $delibera->set('opzioni_prevalenti', $risultato['prevalenti']);
    $delibera->set('sigillo_urna', $this->calcolaSigillo($delibera));
    $delibera->save();

    return $delibera;
  }

  /**
   * Calcola il sigillo dell'urna.
   *
   * Le schede entrano nel calcolo in ordine alfabetico: il risultato dipende
   * da quali schede ci sono, non da quando sono state depositate.
   */
  public function calcolaSigillo(DeliberaInterface $delibera): string {
    $schede = $this->urna->schede($delibera);
    sort($schede, SORT_STRING);

    return hash('sha256', implode("\n", [
      'psiphos-urna-v1',
      (string) $delibera->id(),
      (string) count($schede),
      implode(';', $schede),
    ]));
  }

  /**
   * Riconta l'urna e confronta il risultato con quanto registrato.
   *
   * È la verifica dell'esito complessivo richiesta dal §4.3, esercitabile
   * in qualunque momento dopo la chiusura senza toccare la segretezza dei
   * singoli voti.
   *
   * Una votazione mai scrutinata non ha un sigillo con cui confrontarsi, e
   * dichiararla compromessa sarebbe un allarme falso: è il caso di ogni
   * votazione annullata prima della chiusura. Lo stato va perciò distinto,
   * perché «non c'è nulla da verificare» e «la verifica non torna» chiamano
   * risposte opposte.
   *
   * @return array{sigillata: bool, integra: bool, esito: string, sigillo_atteso: string, sigillo_calcolato: string, conteggio_registrato: array<string, int>, conteggio_ricalcolato: array<string, int>}
   */
  public function verifica(DeliberaInterface $delibera): array {
    $sigilloAtteso = (string) $delibera->get('sigillo_urna')->value;
    $sigilloCalcolato = $this->calcolaSigillo($delibera);
    $registrato = $delibera->conteggio();
    $ricalcolato = $this->conta($delibera);

    ksort($registrato);
    ksort($ricalcolato);

    $sigillata = $sigilloAtteso !== '';
    $integra = $sigillata && hash_equals($sigilloAtteso, $sigilloCalcolato) && $registrato === $ricalcolato;

    return [
      'sigillata' => $sigillata,
      'integra' => $integra,
      'esito' => match (TRUE) {
        !$sigillata => (string) t("Votazione mai scrutinata: non è stato apposto alcun sigillo e non c'è nulla da verificare."),
        $integra => (string) t("L'urna corrisponde al sigillo apposto alla chiusura."),
        default => (string) t('L\'urna NON corrisponde al sigillo apposto alla chiusura.'),
      },
      'sigillo_atteso' => $sigilloAtteso,
      'sigillo_calcolato' => $sigilloCalcolato,
      'conteggio_registrato' => $registrato,
      'conteggio_ricalcolato' => $ricalcolato,
    ];
  }

  /**
   * Numero di votanti che hanno espresso almeno una preferenza.
   *
   * Esclude le schede bianche, che sono partecipazione al voto ma non
   * sostegno ad alcuna opzione.
   *
   * @param array<int, string> $vociDiPreferenza
   */
  private function votantiConPreferenza(DeliberaInterface $delibera, array $vociDiPreferenza): int {
    $votanti = 0;
    foreach ($this->urna->schede($delibera) as $scheda) {
      if (array_intersect(explode(',', $scheda), $vociDiPreferenza) !== []) {
        $votanti++;
      }
    }
    return $votanti;
  }

  /**
   * Riduce le opzioni prevalenti ai posti effettivamente disponibili.
   *
   * Le opzioni si proclamano per gruppi di pari voto, scendendo dal più
   * votato: un gruppo entra solo se ci sta per intero nei posti rimasti.
   * Un pari merito invalida quindi i soli posti contesi, non l'intera
   * proclamazione — chi ha vinto senza discussione resta eletto, e il
   * ballottaggio riguarda unicamente i posti che restano.
   *
   * @param array<int, string> $prevalenti
   * @param array<string, int> $preferenze
   *
   * @return array<int, string>
   */
  private function limitaAiPostiDisponibili(array $prevalenti, array $preferenze, int $posti): array {
    if ($prevalenti === [] || $posti <= 0) {
      return [];
    }

    usort($prevalenti, static fn (string $a, string $b): int => $preferenze[$b] <=> $preferenze[$a]);

    $ammessi = [];
    $posizione = 0;
    $totale = count($prevalenti);

    while ($posizione < $totale) {
      $votiDelGruppo = $preferenze[$prevalenti[$posizione]];
      $gruppo = [];

      while ($posizione < $totale && $preferenze[$prevalenti[$posizione]] === $votiDelGruppo) {
        $gruppo[] = $prevalenti[$posizione];
        $posizione++;
      }

      // Il gruppo in pari merito non entra a metà: o i posti bastano per
      // tutti, o quei posti restano da assegnare.
      if (count($ammessi) + count($gruppo) > $posti) {
        break;
      }

      $ammessi = array_merge($ammessi, $gruppo);
    }

    return $ammessi;
  }

  /**
   * Motivazione dell'esito, da riportare accanto allo scrutinio.
   *
   * Il nome della regola dice quale criterio si applica; qui si dice che
   * cosa è successo in concreto, compresi i posti eventualmente rimasti da
   * assegnare, che è l'informazione con cui chi presiede deve proclamare.
   */
  /**
   * Frase con cui l'organo proclama l'esito, nel registro degli atti.
   *
   * «Il Collegio dei docenti approva all'unanimità con la seguente
   * votazione:» — soggetto, verbo, e il prospetto che segue. È la forma con
   * cui le delibere scolastiche dichiarano l'esito, e il verbo dipende sia
   * dall'esito sia dalla struttura della scheda: su una scheda di
   * approvazione l'organo approva o non approva, su una scheda a scelta
   * proclama una designazione, e sono due atti diversi.
   *
   * Non entra nella struttura su cui si calcola l'impronta: è testo tradotto
   * e interamente derivabile dai numeri, che invece vi entrano. Chi verifica
   * l'atto ricalcola sui numeri, non sulla loro prosa.
   */
  public function proclamazione(DeliberaInterface $delibera): string {
    $esito = $delibera->esito();

    if ($esito === NULL) {
      return '';
    }

    if ($esito === EsitoDelibera::ANNULLATA) {
      return $this->testoSemplice(t('La votazione è stata annullata e non produce effetti. Resta agli atti la votazione svolta:'));
    }

    $organo = $delibera->seduta()?->organo()->etichettaConArticolo() ?? (string) t('L\'organo collegiale');

    if ($esito === EsitoDelibera::QUORUM_NON_RAGGIUNTO) {
      return $this->testoSemplice(t('@organo non ha deliberato: il quorum richiesto non è stato raggiunto. Votazione:', [
        '@organo' => $organo,
      ]));
    }

    $unanimita = $this->unanime($delibera) ? (string) t("all'unanimità") : '';

    if (!$delibera->schemaScheda()->richiedeOpzioni()) {
      $modello = $esito === EsitoDelibera::APPROVATA
        ? t('@organo approva @unanimita con la seguente votazione:', ['@organo' => $organo, '@unanimita' => $unanimita])
        : t('@organo non approva, con la seguente votazione:', ['@organo' => $organo]);

      return $this->testoSemplice($modello);
    }

    if ($esito !== EsitoDelibera::APPROVATA) {
      return $this->testoSemplice(t('@organo non ha proclamato alcuna opzione: nessuna ha raggiunto la maggioranza richiesta. Votazione:', [
        '@organo' => $organo,
      ]));
    }

    return $this->testoSemplice(t('@organo proclama @proclamati @unanimita con la seguente votazione:', [
      '@organo' => $organo,
      '@proclamati' => $this->proclamati($delibera),
      '@unanimita' => $unanimita,
    ]));
  }

  /**
   * Riduce un testo tradotto a testo semplice.
   *
   * I segnaposto di t() vengono convertiti in entità HTML, perché il valore
   * atteso è markup. Quando il testo è destinato a essere reso da Twig — che
   * lo escaperà a sua volta — o confrontato come stringa, quelle entità
   * arrivano in chiaro: «Collegio dei docenti approva all&#039;unanimità».
   * Qui si produce testo semplice, e la resa in HTML resta compito di chi
   * rende.
   */
  private function testoSemplice(\Stringable|string $testo): string {
    // Gli spazi doppi nascono dai segnaposto facoltativi: «approva @unanimita
    // con» diventa «approva  con» quando l'unanimità non c'è. Toglierli qui
    // evita di moltiplicare le varianti del messaggio da tradurre.
    return trim((string) preg_replace('/\s{2,}/u', ' ', PlainTextOutput::renderFromHtml((string) $testo)));
  }

  /**
   * Prospetto della votazione: le cifre incolonnate, una per riga.
   *
   * Incolonnate e non in prosa perché si controllano una per una, e perché su
   * una scheda a più opzioni la frase discorsiva diventa illeggibile. Le voci
   * seguono l'ordine della scheda, che è quello con cui si è votato.
   *
   * @return array<int, array{voce: string, valore: int, qualifica: string}>
   */
  public function prospettoVotazione(DeliberaInterface $delibera): array {
    if ($delibera->esito() === NULL) {
      return [];
    }

    $prospetto = [
      ['voce' => (string) t('Aventi diritto'), 'valore' => (int) $delibera->get('aventi_diritto_al_voto')->value, 'qualifica' => ''],
      ['voce' => (string) t('Presenti'), 'valore' => (int) $delibera->get('presenti_al_voto')->value, 'qualifica' => ''],
      ['voce' => (string) t('Votanti'), 'valore' => (int) $delibera->get('votanti')->value, 'qualifica' => ''],
    ];

    $voci = $delibera->vociScheda();

    // La proclamazione si segna accanto alla cifra solo sulle schede a scelta,
    // dove indica quale opzione ha prevalso fra le altre. Su una scheda di
    // approvazione la voce prevalente è «Favorevole», e marcarla come
    // proclamata è rumore: che la proposta sia approvata lo dice la frase.
    $prevalenti = [];
    if ($delibera->schemaScheda()->richiedeOpzioni()) {
      foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
        $prevalenti[] = (string) $elemento->value;
      }
    }

    foreach ($delibera->conteggio() as $chiave => $numero) {
      $prospetto[] = [
        'voce' => $voci[$chiave] ?? (string) $chiave,
        'valore' => (int) $numero,
        'qualifica' => in_array((string) $chiave, $prevalenti, TRUE) ? (string) t('proclamata') : '',
      ];
    }

    return $prospetto;
  }

  /**
   * Voci proclamate, in chiaro e separate da virgola.
   */
  private function proclamati(DeliberaInterface $delibera): string {
    $voci = $delibera->vociScheda();
    $proclamate = [];

    foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
      $chiave = (string) $elemento->value;
      $proclamate[] = $voci[$chiave] ?? $chiave;
    }

    return implode(', ', $proclamate);
  }

  /**
   * Vero se tutti i votanti hanno espresso la stessa scelta.
   *
   * L'unanimità si legge dal conteggio e non dall'esito: una proposta può
   * essere approvata a maggioranza schiacciante senza essere unanime, e la
   * differenza è quella che il collegio si aspetta di ritrovare nell'atto.
   * Una sola voce con voti, pari al numero dei votanti: se qualcuno si è
   * astenuto o ha lasciato scheda bianca l'unanimità non c'è, perché anche
   * quelle sono voci della scheda.
   */
  private function unanime(DeliberaInterface $delibera): bool {
    $votanti = (int) $delibera->get('votanti')->value;

    if ($votanti < 1) {
      return FALSE;
    }

    $conVoti = array_filter($delibera->conteggio(), static fn (int $numero): bool => $numero > 0);

    return count($conVoti) === 1 && (int) reset($conVoti) === $votanti;
  }

  public function motivazioneEsito(DeliberaInterface $delibera): string {
    if ($delibera->esito() === NULL) {
      return '';
    }

    // Su una votazione annullata il criterio di maggioranza non ha più nulla
    // da spiegare: non ci sono posti da assegnare né soglie da raggiungere.
    // Lo scrutinio resta visibile perché è agli atti, ma va detto che non
    // produce effetti, altrimenti si legge come un esito valido.
    if ($delibera->esito() === EsitoDelibera::ANNULLATA) {
      return (string) t('La votazione è stata annullata: lo scrutinio resta agli atti ma non produce effetti. Per deliberare sul punto occorre una nuova votazione.');
    }

    $schema = $delibera->schemaScheda();
    $conteggio = $delibera->conteggio();
    $presenti = (int) $delibera->get('presenti_al_voto')->value;
    $aventiDiritto = (int) $delibera->get('aventi_diritto_al_voto')->value;

    $votantiConPreferenza = $schema->richiedeOpzioni()
      ? max(0, ((int) $delibera->get('votanti')->value) - ($conteggio[SchemaScheda::VOCE_SCHEDA_BIANCA] ?? 0))
      : NULL;

    $motivazione = $this->testoSemplice($delibera->regolaMaggioranza()->spiegazione($presenti, $aventiDiritto, $votantiConPreferenza));

    if (!$schema->richiedeOpzioni()) {
      return $motivazione;
    }

    $proclamate = 0;
    foreach ($delibera->get('opzioni_prevalenti') as $elemento) {
      $proclamate++;
    }

    $residui = $delibera->preferenzeMassime() - $proclamate;

    if ($residui > 0) {
      $motivazione .= ' ' . $this->testoSemplice(\Drupal::translation()->formatPlural(
        $residui,
        'Resta 1 posto da assegnare: le opzioni che se lo contendono sono in pari merito e occorre un ballottaggio.',
        'Restano @count posti da assegnare: le opzioni che se li contendono sono in pari merito e occorre un ballottaggio.'
      ));
    }

    return $motivazione;
  }

}
