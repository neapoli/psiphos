<?php

/**
 * @file
 * Verifica funzionale dell'urna e dello scrutinio di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_urna.php
 *
 * Copre i §§4.1, 4.2 e 4.3 dell'allegato tecnico alla nota MIM 3803/2026:
 * unicità del voto, tracciabilità del voto palese, separazione strutturale e
 * verificabilità dell'esito nel voto a scrutinio segreto.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\EsitoDelibera;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\psiphos\Exception\VotoNonAmmessoException;
use Drupal\user\Entity\User;

final class EsitiUrna {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }

  public static function bloccata(string $descrizione, callable $azione, string $classeAttesa): void {
    try {
      $azione();
      self::verifica($descrizione, FALSE);
    }
    catch (\Throwable $eccezione) {
      for ($corrente = $eccezione; $corrente !== NULL; $corrente = $corrente->getPrevious()) {
        if ($corrente instanceof $classeAttesa) {
          self::verifica($descrizione, TRUE);
          return;
        }
      }
      self::verifica(sprintf('%s [attesa %s, ottenuta %s]', $descrizione, $classeAttesa, get_class($eccezione)), FALSE);
    }
  }
}

$gestoreEntita = \Drupal::entityTypeManager();
$database = \Drupal::database();
$urna = \Drupal::service('psiphos.urna');
$scrutinio = \Drupal::service('psiphos.scrutinio');

/** Rimuove i soli dati creati da questa verifica. */
$ripulisci = static fn () => ProvaPsiphos::ripulisci();
ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
$ripulisci();

echo "\n[1] Struttura fisica dell'urna\n";
$colonneUrna = array_map(static fn ($r) => $r->Field, $database->query('DESCRIBE {psiphos_urna}')->fetchAll());
EsitiUrna::verifica(
  "l'urna ha solo id, delibera e voci",
  $colonneUrna === ['id', 'delibera', 'voci']
);
EsitiUrna::verifica(
  "nessuna colonna dell'urna riferisce un utente",
  [] === array_filter($colonneUrna, static fn (string $c): bool => str_contains($c, 'utente') || str_contains($c, 'uid'))
);
EsitiUrna::verifica(
  "nessuna marca temporale sulle schede",
  [] === array_filter($colonneUrna, static fn (string $c): bool => str_contains($c, 'il') && $c !== 'id')
);
$colonneAttestazione = array_map(static fn ($r) => $r->Field, $database->query('DESCRIBE {psiphos_attestazione}')->fetchAll());
EsitiUrna::verifica(
  'il registro dei votanti non contiene il contenuto del voto',
  !in_array('voci', $colonneAttestazione, TRUE)
);
EsitiUrna::verifica(
  'urna e registro non hanno colonne in comune oltre alla delibera',
  array_values(array_intersect($colonneUrna, $colonneAttestazione)) === ['delibera']
);

// Impianto di prova: una seduta aperta con 7 aventi diritto, 5 presenti.
$utenti = [];
for ($indice = 1; $indice <= 7; $indice++) {
  $utente = User::create([
    'name' => "psiphos_prova_$indice",
    'mail' => "psiphos_prova_$indice@example.test",
    'status' => 1,
  ]);
  $utente->save();
  $utenti[] = $utente;
}
$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti di prova'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $utenti[0]->id(),
  'segretario' => $utenti[1]->id(),
]);
$seduta->save();
foreach ($utenti as $posizione => $utente) {
  Presenza::create([
    'seduta' => $seduta->id(),
    'utente' => $utente->id(),
    'stato' => $posizione < 5 ? StatoPresenza::PRESENTE->value : StatoPresenza::ATTESO->value,
    'ingresso' => $posizione < 5 ? \Drupal::time()->getRequestTime() : NULL,
  ])->save();
}
$seduta->transitaA(StatoSeduta::APERTA)->save();
$punto = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 1, 'oggetto' => 'Punto di prova']);
$punto->save();

$creaDelibera = static function (array $valori) use ($punto): Delibera {
  $delibera = Delibera::create($valori + ['punto_odg' => $punto->id(), 'quesito' => 'Quesito di prova']);
  $delibera->save();
  return $delibera;
};

echo "\n[2] Legittimazione al voto\n";
$chiusa = $creaDelibera(['tipo_voto' => TipoVoto::PALESE->value]);
EsitiUrna::bloccata(
  'urna non ancora aperta: voto rifiutato',
  static fn () => $urna->deposita($chiusa, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]),
  VotoNonAmmessoException::class
);
$chiusa->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
EsitiUrna::bloccata(
  'avente diritto non presente in aula: voto rifiutato',
  static fn () => $urna->deposita($chiusa, $utenti[5], [SchemaScheda::VOCE_FAVOREVOLE]),
  VotoNonAmmessoException::class
);
$estraneo = User::create(['name' => 'psiphos_prova_estraneo', 'mail' => 'psiphos_prova_estraneo@example.test', 'status' => 1]);
$estraneo->save();
EsitiUrna::bloccata(
  'chi non è nell\'elenco degli aventi diritto: voto rifiutato',
  static fn () => $urna->deposita($chiusa, $estraneo, [SchemaScheda::VOCE_FAVOREVOLE]),
  VotoNonAmmessoException::class
);

echo "\n[2-bis] Elettorato fissato all'apertura dell'urna\n";
// Il denominatore dei quorum è congelato all'apertura: perché resti coerente
// deve esserlo anche chi può votare, altrimenti si depositano più schede di
// quante ne prevede la base su cui la maggioranza si calcola.
$conElettorato = $creaDelibera([
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$conElettorato->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$conElettorato = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($conElettorato->id());

EsitiUrna::verifica("l'elettorato è registrato all'apertura", $urna->elettoratoFissato($conElettorato));
EsitiUrna::verifica(
  'e coincide con i presenti al voto',
  (int) $conElettorato->get('presenti_al_voto')->value === 5
);
EsitiUrna::verifica('chi era in aula è ammesso', $urna->ammessoAlVoto($conElettorato, $utenti[0]));
EsitiUrna::verifica('chi non lo era non è ammesso', !$urna->ammessoAlVoto($conElettorato, $utenti[5]));

// Un avente diritto entra a votazione iniziata: potrà votare dai punti
// successivi, non su questo.
$sopraggiunto = $gestoreEntita->getStorage('psiphos_presenza')->loadByProperties([
  'seduta' => $seduta->id(),
  'utente' => $utenti[5]->id(),
]);
$sopraggiunto = reset($sopraggiunto);
$sopraggiunto->set('stato', StatoPresenza::PRESENTE->value)
  ->set('ingresso', \Drupal::time()->getRequestTime())
  ->save();

EsitiUrna::bloccata(
  'chi entra a votazione iniziata non vi partecipa',
  static fn () => $urna->deposita($conElettorato, $utenti[5], [SchemaScheda::VOCE_FAVOREVOLE]),
  VotoNonAmmessoException::class
);
EsitiUrna::verifica('e nessuna scheda in più finisce nel voto', $urna->numeroVotanti($conElettorato) === 0);

// La ripresa dopo una sospensione non riapre l'elettorato.
$conElettorato->transitaA(StatoDelibera::SOSPESA, 'Verifica del collegamento.')->save();
$conElettorato->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
EsitiUrna::verifica(
  "la ripresa non amplia l'elettorato",
  !$urna->ammessoAlVoto($gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($conElettorato->id()), $utenti[5])
);

// Ripristino della posizione per le sezioni successive.
$sopraggiunto->set('stato', StatoPresenza::ATTESO->value)->set('ingresso', NULL)->save();

echo "\n[3] Validazione della scheda\n";
$elettiva = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_MULTIPLA->value,
  'opzioni' => ['Rossi', 'Bianchi', 'Verdi', 'Neri'],
  'preferenze_massime' => 2,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
]);
$elettiva->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
EsitiUrna::bloccata('scheda vuota rifiutata', static fn () => $urna->deposita($elettiva, $utenti[0], []), VotoNonAmmessoException::class);
EsitiUrna::bloccata('voce inesistente rifiutata', static fn () => $urna->deposita($elettiva, $utenti[0], ['opzione_9']), VotoNonAmmessoException::class);
EsitiUrna::bloccata(
  'preferenze oltre il massimo rifiutate',
  static fn () => $urna->deposita($elettiva, $utenti[0], ['opzione_1', 'opzione_2', 'opzione_3']),
  VotoNonAmmessoException::class
);
EsitiUrna::bloccata(
  'scheda bianca insieme a una preferenza rifiutata',
  static fn () => $urna->deposita($elettiva, $utenti[0], ['opzione_1', SchemaScheda::VOCE_SCHEDA_BIANCA]),
  VotoNonAmmessoException::class
);
EsitiUrna::verifica('nessuna scheda depositata dai tentativi respinti', $urna->numeroSchede($elettiva) === 0);
EsitiUrna::verifica('nessun votante registrato dai tentativi respinti', $urna->numeroVotanti($elettiva) === 0);

echo "\n[4] Voto a scrutinio segreto: unicità e separazione\n";
$urna->deposita($elettiva, $utenti[0], ['opzione_2', 'opzione_1']);
EsitiUrna::verifica('scheda depositata', $urna->numeroSchede($elettiva) === 1);
EsitiUrna::verifica('votante attestato', $urna->haVotato($elettiva, $utenti[0]));
EsitiUrna::bloccata(
  'secondo voto dello stesso avente diritto rifiutato',
  static fn () => $urna->deposita($elettiva, $utenti[0], ['opzione_3']),
  VotoNonAmmessoException::class
);
EsitiUrna::verifica('il secondo tentativo non ha aggiunto schede', $urna->numeroSchede($elettiva) === 1);

$scheda = $database->select('psiphos_urna', 'u')->fields('u', ['voci'])->condition('delibera', $elettiva->id())->execute()->fetchField();
EsitiUrna::verifica(
  'le preferenze sono conservate in forma canonica, non nell\'ordine di spunta',
  $scheda === 'opzione_1,opzione_2'
);
EsitiUrna::verifica(
  'il registro dei votanti non espone il contenuto del voto',
  $urna->registroVotanti($elettiva)[0]['voci'] === NULL
);

$urna->deposita($elettiva, $utenti[1], ['opzione_1', 'opzione_2']);
$identiche = $database->select('psiphos_urna', 'u')
  ->fields('u', ['voci'])->condition('delibera', $elettiva->id())->condition('voci', 'opzione_1,opzione_2')
  ->countQuery()->execute()->fetchField();
EsitiUrna::verifica('due schede uguali restano indistinguibili fra loro', (int) $identiche === 2);

$urna->deposita($elettiva, $utenti[2], ['opzione_1', 'opzione_3']);
$urna->deposita($elettiva, $utenti[3], [SchemaScheda::VOCE_SCHEDA_BIANCA]);
$urna->deposita($elettiva, $utenti[4], ['opzione_1']);

$identificativi = $database->select('psiphos_urna', 'u')->fields('u', ['id'])->condition('delibera', $elettiva->id())->execute()->fetchCol();
EsitiUrna::verifica(
  'gli identificativi di scheda non sono progressivi',
  count($identificativi) === 5 && (max($identificativi) - min($identificativi)) > count($identificativi)
);

echo "\n[5] Scrutinio di una scheda a scelta multipla\n";
$conteggio = $scrutinio->conta($elettiva);
EsitiUrna::verifica('Rossi ha 4 preferenze', $conteggio['opzione_1'] === 4);
EsitiUrna::verifica('Bianchi ha 2 preferenze', $conteggio['opzione_2'] === 2);
EsitiUrna::verifica('Verdi ha 1 preferenza', $conteggio['opzione_3'] === 1);
EsitiUrna::verifica('Neri non ha preferenze ma compare nel conteggio', $conteggio['opzione_4'] === 0);
EsitiUrna::verifica('una scheda bianca conteggiata', $conteggio[SchemaScheda::VOCE_SCHEDA_BIANCA] === 1);
EsitiUrna::verifica('somma delle voci pari alle preferenze espresse', array_sum($conteggio) === 8);

$scrutinio->chiudiEScrutina($elettiva);
$elettiva = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($elettiva->id());
EsitiUrna::verifica('votazione chiusa', $elettiva->stato() === StatoDelibera::CHIUSA);
EsitiUrna::verifica('votanti registrati sulla delibera', (int) $elettiva->get('votanti')->value === 5);
EsitiUrna::verifica('conteggio consolidato sulla delibera', $elettiva->conteggio()['opzione_1'] === 4);
$prevalenti = array_map(static fn ($e) => $e->value, iterator_to_array($elettiva->get('opzioni_prevalenti')));
EsitiUrna::verifica('proclamati due nomi su due posti', count($prevalenti) === 2);
EsitiUrna::verifica('i proclamati sono i più votati', $prevalenti === ['opzione_1', 'opzione_2']);
EsitiUrna::verifica('esito di proclamazione', $elettiva->esito() === EsitoDelibera::APPROVATA);

echo "\n[5-bis] Il conteggio è visibile solo a urna chiusa\n";
$resaSeduta = static fn (): string => (string) \Drupal::service('renderer')->renderRoot(
  $gestoreEntita->getViewBuilder('psiphos_seduta')->view(
    $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id())
  )
);
// Il confronto è differenziale: sulla pagina compaiono anche gli scrutini
// delle votazioni già chiuse nelle sezioni precedenti, e un confronto
// assoluto direbbe soltanto che quelli esistono.
$scrutiniPrima = substr_count($resaSeduta(), 'Scrutinio');

$inCorso = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$inCorso->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($inCorso, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$gestoreEntita->getStorage('psiphos_delibera')->resetCache();
EsitiUrna::verifica(
  'a urna aperta non compare alcuno scrutinio in più',
  substr_count($resaSeduta(), 'Scrutinio') === $scrutiniPrima
);

$scrutinio->chiudiEScrutina($gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($inCorso->id()));
$gestoreEntita->getStorage('psiphos_delibera')->resetCache();
$resa = $resaSeduta();
EsitiUrna::verifica('chiusa l\'urna, lo scrutinio compare', substr_count($resa, 'Scrutinio') === $scrutiniPrima + 1);
EsitiUrna::verifica('con il dettaglio per voce', str_contains($resa, 'Favorevole'));
EsitiUrna::verifica(
  "e con il criterio che motiva l'esito",
  str_contains($resa, 'Occorrevano almeno')
);

// Su una scheda a scelta il criterio dichiara la base di calcolo effettiva.
$elettivaChiusa = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Rossi', 'Bianchi'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$elettivaChiusa->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($elettivaChiusa, $utenti[0], ['opzione_1']);
$urna->deposita($elettivaChiusa, $utenti[1], ['opzione_2']);
$urna->deposita($elettivaChiusa, $utenti[2], [SchemaScheda::VOCE_SCHEDA_BIANCA]);
$scrutinio->chiudiEScrutina($gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($elettivaChiusa->id()));
$elettivaChiusa = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($elettivaChiusa->id());
$conteggioElettivo = $elettivaChiusa->conteggio();
$votantiConPreferenza = ((int) $elettivaChiusa->get('votanti')->value) - $conteggioElettivo[SchemaScheda::VOCE_SCHEDA_BIANCA];
EsitiUrna::verifica('le schede bianche non contano fra chi ha espresso preferenze', $votantiConPreferenza === 2);
$criterio = $elettivaChiusa->regolaMaggioranza()->spiegazione(
  (int) $elettivaChiusa->get('presenti_al_voto')->value,
  (int) $elettivaChiusa->get('aventi_diritto_al_voto')->value,
  $votantiConPreferenza
);
EsitiUrna::verifica('il criterio dichiara quanti hanno espresso una preferenza', str_contains($criterio, '2 votanti'));
EsitiUrna::verifica('e la soglia che ne discende', str_contains($criterio, 'almeno 2'));
EsitiUrna::verifica('e che le bianche non concorrono', str_contains($criterio, 'bianche non concorrono'));

echo "\n[6] Sigillo e verificabilità dell'esito\n";
EsitiUrna::verifica('sigillo registrato', strlen((string) $elettiva->get('sigillo_urna')->value) === 64);
$controllo = $scrutinio->verifica($elettiva);
EsitiUrna::verifica('il ricalcolo conferma l\'urna integra', $controllo['integra']);
EsitiUrna::verifica('il conteggio ricalcolato coincide con quello registrato', $controllo['conteggio_registrato'] === $controllo['conteggio_ricalcolato']);

// Manomissione: una scheda in più depositata dopo la chiusura.
$database->insert('psiphos_urna')->fields([
  'id' => random_int(1, 4611686018427387903),
  'delibera' => $elettiva->id(),
  'voci' => 'opzione_4',
])->execute();
$controlloDopo = $scrutinio->verifica($elettiva);
EsitiUrna::verifica('una scheda aggiunta dopo la chiusura rompe il sigillo', !$controlloDopo['integra']);
EsitiUrna::verifica('il sigillo calcolato diverge da quello registrato', $controlloDopo['sigillo_atteso'] !== $controlloDopo['sigillo_calcolato']);
$database->delete('psiphos_urna')->condition('delibera', $elettiva->id())->condition('voci', 'opzione_4')->execute();
EsitiUrna::verifica('rimossa la scheda estranea, il sigillo torna valido', $scrutinio->verifica($elettiva)['integra']);

// Una votazione annullata prima della chiusura non ha sigillo: dichiararla
// compromessa sarebbe un allarme falso.
$maiScrutinata = $creaDelibera([
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$maiScrutinata->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$maiScrutinata->transitaA(StatoDelibera::ANNULLATA, 'Annullata prima della chiusura.')->save();
$controlloMaiScrutinata = $scrutinio->verifica(
  $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($maiScrutinata->id())
);
EsitiUrna::verifica('una votazione mai scrutinata non risulta sigillata', !$controlloMaiScrutinata['sigillata']);
EsitiUrna::verifica('né viene dichiarata compromessa', !$controlloMaiScrutinata['integra']);
EsitiUrna::verifica(
  "e l'esito lo dice: non c'è nulla da verificare",
  str_contains($controlloMaiScrutinata['esito'], 'nulla da verificare')
);
EsitiUrna::verifica(
  'mentre una votazione scrutinata risulta sigillata',
  $scrutinio->verifica($elettiva)['sigillata']
);

echo "\n[7] Discordanza fra votanti e schede\n";
$discorde = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$discorde->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($discorde, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$database->delete('psiphos_urna')->condition('delibera', $discorde->id())->execute();
EsitiUrna::bloccata(
  'lo scrutinio si ferma se le schede non tornano con i votanti',
  static fn () => $scrutinio->chiudiEScrutina($discorde),
  \RuntimeException::class
);
$discorde = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($discorde->id());
EsitiUrna::verifica('la votazione discorde non è stata chiusa', $discorde->stato() === StatoDelibera::IN_VOTAZIONE);

echo "\n[8] Voto palese\n";
$palese = $creaDelibera([
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$palese->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($palese, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($palese, $utenti[1], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($palese, $utenti[2], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($palese, $utenti[3], [SchemaScheda::VOCE_CONTRARIO]);
$urna->deposita($palese, $utenti[4], [SchemaScheda::VOCE_ASTENUTO]);
EsitiUrna::bloccata(
  'anche nel voto palese si vota una volta sola',
  static fn () => $urna->deposita($palese, $utenti[0], [SchemaScheda::VOCE_CONTRARIO]),
  VotoNonAmmessoException::class
);
EsitiUrna::verifica('nessuna scheda finita nell\'urna segreta', (int) $database->select('psiphos_urna', 'u')->condition('delibera', $palese->id())->countQuery()->execute()->fetchField() === 0);
$registro = $urna->registroVotanti($palese);
EsitiUrna::verifica('il voto palese è riconducibile al votante', $registro[0]['utente'] === (int) $utenti[0]->id() && $registro[0]['voci'] === 'favorevole');

$scrutinio->chiudiEScrutina($palese);
$palese = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($palese->id());
EsitiUrna::verifica('3 favorevoli su 5 presenti: approvata', $palese->esito() === EsitoDelibera::APPROVATA);
EsitiUrna::verifica('astenuto conteggiato a parte', $palese->conteggio()[SchemaScheda::VOCE_ASTENUTO] === 1);

$respinta = $creaDelibera([
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$respinta->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($respinta, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($respinta, $utenti[1], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($respinta, $utenti[2], [SchemaScheda::VOCE_ASTENUTO]);
$scrutinio->chiudiEScrutina($respinta);
$respinta = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($respinta->id());
EsitiUrna::verifica(
  '2 favorevoli su 5 presenti non bastano alla maggioranza assoluta',
  $respinta->esito() === EsitoDelibera::RESPINTA
);

echo "\n[8-bis] Proclamazione per gruppi di pari voto\n";
// Un pari merito invalida i soli posti contesi. Chi ha vinto senza
// discussione resta eletto: scartare anche lui perché due altri sono alla
// pari sul posto successivo non ha fondamento in nessun regolamento.
$graduatoria = new \ReflectionMethod(\Drupal\psiphos\Service\Scrutinio::class, 'limitaAiPostiDisponibili');
$graduatoria->setAccessible(TRUE);
$proclama = static function (array $preferenze, int $posti) use ($graduatoria, $scrutinio): array {
  $esito = $graduatoria->invoke($scrutinio, array_keys($preferenze), $preferenze, $posti);
  sort($esito);

  return $esito;
};

$casiDiProclamazione = [
  'il primo indiscusso è proclamato anche con pari merito sul secondo posto' => [['a' => 3, 'b' => 1, 'c' => 1], 2, ['a']],
  'due posti a due vincitori distinti' => [['a' => 3, 'b' => 2, 'c' => 1], 2, ['a', 'b']],
  'tre opzioni tutte alla pari per due posti: nessuna proclamata' => [['a' => 2, 'b' => 2, 'c' => 2], 2, []],
  'un pari merito che sta nei posti disponibili passa per intero' => [['a' => 3, 'b' => 3, 'c' => 1], 2, ['a', 'b']],
  'posti più numerosi delle opzioni: passano tutte' => [['a' => 5, 'b' => 1, 'c' => 1], 3, ['a', 'b', 'c']],
  'su posto unico il primo vince' => [['a' => 2, 'b' => 1], 1, ['a']],
  'su posto unico il pari merito non proclama' => [['a' => 1, 'b' => 1], 1, []],
];
foreach ($casiDiProclamazione as $descrizione => $caso) {
  [$preferenze, $posti, $atteso] = $caso;
  EsitiUrna::verifica($descrizione, $proclama($preferenze, $posti) === $atteso);
}

// Prova completa: tre votanti, due preferenze, un vincitore netto.
$multipla = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_MULTIPLA->value,
  'opzioni' => ['Rossi', 'Bianchi', 'Verdi'],
  'preferenze_massime' => 2,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
]);
$multipla->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($multipla, $utenti[0], ['opzione_1', 'opzione_2']);
$urna->deposita($multipla, $utenti[1], ['opzione_1', 'opzione_3']);
$urna->deposita($multipla, $utenti[2], ['opzione_1']);
$scrutinio->chiudiEScrutina($multipla);
$multipla = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($multipla->id());
$proclamate = array_map(static fn ($e) => $e->value, iterator_to_array($multipla->get('opzioni_prevalenti')));
EsitiUrna::verifica('Rossi con 3 preferenze è proclamato', $proclamate === ['opzione_1']);
EsitiUrna::verifica("l'esito è di proclamazione, non di rigetto", $multipla->esito() === EsitoDelibera::APPROVATA);
$motivazione = $scrutinio->motivazioneEsito($multipla);
EsitiUrna::verifica('la motivazione segnala il posto rimasto da assegnare', str_contains($motivazione, 'ballottaggio'));
EsitiUrna::verifica('e ne dichiara il numero', str_contains($motivazione, 'Resta 1 posto'));

// Annullata la votazione, il criterio non ha più nulla da spiegare: non
// esistono posti da assegnare né soglie da raggiungere.
$multipla->transitaA(StatoDelibera::ANNULLATA, 'Malfunzionamento accertato dopo lo scrutinio.')->save();
$motivazioneAnnullata = $scrutinio->motivazioneEsito(
  $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($multipla->id())
);
EsitiUrna::verifica('annullata, la motivazione lo dichiara', str_contains($motivazioneAnnullata, 'annullata'));
EsitiUrna::verifica('e non parla più di ballottaggi o posti', !str_contains($motivazioneAnnullata, 'ballottaggio'));
EsitiUrna::verifica(
  'ma dice che lo scrutinio resta agli atti',
  str_contains($motivazioneAnnullata, 'resta agli atti')
);

echo "\n[9] Pari merito sull'ultimo posto\n";
$ballottaggio = $creaDelibera([
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Rossi', 'Bianchi'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
]);
$ballottaggio->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($ballottaggio, $utenti[0], ['opzione_1']);
$urna->deposita($ballottaggio, $utenti[1], ['opzione_2']);
$scrutinio->chiudiEScrutina($ballottaggio);
$ballottaggio = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($ballottaggio->id());
EsitiUrna::verifica(
  'il pari merito non proclama nessuno',
  $ballottaggio->esito() === EsitoDelibera::RESPINTA && iterator_count($ballottaggio->get('opzioni_prevalenti')) === 0
);
EsitiUrna::verifica(
  'e si legge come mancata maggioranza, non come proposta respinta',
  str_contains(EsitoDelibera::RESPINTA->etichettaPer(SchemaScheda::SCELTA_SINGOLA), 'maggioranza')
);
EsitiUrna::bloccata(
  'maggioranza relativa rifiutata su una scheda di approvazione',
  static fn () => $creaDelibera([
    'tipo_voto' => TipoVoto::PALESE->value,
    'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
  ]),
  \InvalidArgumentException::class
);

echo "\n[Metadati del voto segreto (§4.3)]\n";
// Il §4.3 chiede che log e marche temporali siano trattati in modo da non
// consentire «neanche indirettamente» la re-identificazione. L'istante in cui
// ciascuno ha votato, accostato all'ordine di deposito delle schede,
// ricostruirebbe la corrispondenza: non va conservato accanto al votante.
$schemaDatabase = \Drupal::database()->schema();
EsitiUrna::verifica(
  "l'attestazione di voto segreto non porta marche temporali",
  !$schemaDatabase->fieldExists('psiphos_attestazione', 'registrata_il')
);
EsitiUrna::verifica(
  'né la scheda ne porta alcuna',
  !$schemaDatabase->fieldExists('psiphos_urna', 'registrata_il')
    && !$schemaDatabase->fieldExists('psiphos_urna', 'creata_il')
);
$colonneAttestazione = array_keys(\Drupal::database()
  ->query('SHOW COLUMNS FROM {psiphos_attestazione}')->fetchAllKeyed(0, 0));
sort($colonneAttestazione);
EsitiUrna::verifica(
  "dell'attestazione restano la delibera e chi ha votato, nient'altro",
  $colonneAttestazione === ['delibera', 'utente']
);
$conMetadati = $creaDelibera(['tipo_voto' => TipoVoto::SEGRETO->value]);
$conMetadati->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($conMetadati, $utenti[0], [SchemaScheda::VOCE_FAVOREVOLE]);
$urna->deposita($conMetadati, $utenti[1], [SchemaScheda::VOCE_CONTRARIO]);
$registroSegreto = $urna->registroVotanti($conMetadati);

EsitiUrna::verifica(
  'il registro attesta la partecipazione di entrambi',
  count($registroSegreto) === 2
);
EsitiUrna::verifica(
  'ma non espone alcun istante',
  array_sum(array_column($registroSegreto, 'momento')) === 0
);
EsitiUrna::verifica(
  'né alcuna preferenza',
  array_filter(array_column($registroSegreto, 'voci')) === []
);

printf("\n--- %d superate, %d fallite ---\n", EsitiUrna::$superate, EsitiUrna::$fallite);

$ripulisci();
echo "pulizia completata\n";
