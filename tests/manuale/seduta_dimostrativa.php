<?php

/**
 * @file
 * Compone una seduta completa e sigillata, per ispezionarne la resa.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/seduta_dimostrativa.php
 *   ddev drush php:script .../seduta_dimostrativa.php -- nomeutente
 *
 * L'utenza indicata — in mancanza, quella di amministrazione — è iscritta fra
 * gli aventi diritto delle due sedute vive: una convocata e una in corso con
 * una votazione aperta. Servono a provare il blocco «le mie sedute
 * collegiali» dalla propria scrivania, dove non compare nulla se non si
 * figura in alcun elenco.
 *
 * Non è una verifica: non controlla nulla e non riferisce esiti. Serve ad
 * avere sotto gli occhi tutti i casi che i documenti devono saper rendere —
 * punto non deliberativo, approvazione unanime, approvazione a maggioranza
 * con astenuti, scrutinio segreto a scelta fra opzioni, votazione annullata e
 * ripetuta ai sensi del §8 — senza doverli produrre a mano uno per uno.
 *
 * Usa il marcatore delle prove, quindi `azzera_dati.php` e le suite di
 * verifica la rimuovono come qualsiasi altro dato di prova.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\psiphos\Entity\Delibera;
use Drupal\psiphos\Entity\Presenza;
use Drupal\psiphos\Entity\PuntoOdg;
use Drupal\psiphos\Entity\Seduta;
use Drupal\psiphos\Enum\RegolaMaggioranza;
use Drupal\psiphos\Enum\SchemaScheda;
use Drupal\psiphos\Enum\StatoDelibera;
use Drupal\psiphos\Enum\StatoPresenza;
use Drupal\psiphos\Enum\StatoSeduta;
use Drupal\psiphos\Enum\TipoOrgano;
use Drupal\psiphos\Enum\TipoVoto;
use Drupal\user\Entity\User;

ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
ProvaPsiphos::ripulisci();

$urna = \Drupal::service('psiphos.urna');
$scrutinio = \Drupal::service('psiphos.scrutinio');
$verbalizzazione = \Drupal::service('psiphos.verbalizzazione');
$commutatore = \Drupal::service('account_switcher');
$adesso = \Drupal::time()->getRequestTime();

// Il formato con cui il sito redige davvero. Va chiesto per un utente del
// sito e non per quello della riga di comando, che è anonimo e ha come
// predefinito il testo semplice: scrivere HTML in un campo «plain_text» lo fa
// comparire con i tag in chiaro, che è quel che accade a chiunque incolli
// testo formattato scegliendo il formato sbagliato.
$formatoRicco = filter_default_format(User::load(1));

// Nominativi veri: i documenti mostrano «Cognome Nome», e con i soli nomi
// utente non si vedrebbe come si impagina un registro presenze reale.
$anagrafica = [
  ['Bianchi', 'Marta'],
  ['Conti', 'Alessandro'],
  ['De Luca', 'Giovanna'],
  ['Esposito', 'Rocco'],
  ['Ferrara', 'Chiara'],
  ['Greco', 'Antonio'],
  ['Lombardi', 'Silvia'],
  ['Marino', 'Paolo'],
];

$docenti = [];
foreach ($anagrafica as $posizione => [$cognome, $nome]) {
  $utente = User::create([
    'name' => ProvaPsiphos::PREFISSO_UTENTI . strtolower(str_replace(' ', '', $cognome)),
    'mail' => sprintf('%s%d@example.test', ProvaPsiphos::PREFISSO_UTENTI, $posizione + 1),
    'status' => 1,
    'field_cognome' => $cognome,
    'field_nome' => $nome,
  ]);
  $utente->save();
  $docenti[] = $utente;
}

$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti — insediamento a.s. 2026/27'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '1/2026-27',
  'anno_scolastico' => '2026/27',
  'modalita' => 'mista',
  'url_videoconferenza' => 'https://meet.example.test/collegio-insediamento',
  'data_convocazione' => $adesso - (5 * 86400),
  'data_seduta' => $adesso - 7200,
  'presidente' => $docenti[0]->id(),
  'segretario' => $docenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto, approvato con delibera del Consiglio d'istituto n. 4 del 12/09/2026",
  'note_procedurali' => "Alle 17:42 la prof.ssa Ferrara ha segnalato l'interruzione del collegamento, ripristinato entro due minuti senza che alcuna votazione fosse aperta.",
]);
$seduta->save();

// Sette presenti su otto: una assenza giustificata mostra come il registro
// distingue chi non concorre al quorum.
foreach ($docenti as $posizione => $docente) {
  $assente = $posizione === 7;
  Presenza::create([
    'seduta' => $seduta->id(),
    'utente' => $docente->id(),
    'stato' => $assente ? StatoPresenza::ASSENTE->value : StatoPresenza::PRESENTE->value,
    'giustificazione' => $assente ? 'Congedo per motivi di famiglia' : '',
    'ingresso' => $assente ? NULL : $adesso - 7200,
    'uscita' => $assente ? NULL : $adesso - 3600,
    'ultima_attivita' => $assente ? NULL : $adesso - 3600,
  ])->save();
}

$seduta->transitaA(StatoSeduta::APERTA)->save();
$votanti = array_slice($docenti, 0, 7);

/** Crea una delibera già redatta come atto. */
$predisponi = static function (PuntoOdg $punto, array $valori) use ($seduta): Delibera {
  $delibera = Delibera::create(['punto_odg' => $punto->id()] + $valori);
  $delibera->save();

  return $delibera;
};

// --- 1. Punto non deliberativo -------------------------------------------
$primo = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 1,
  'oggetto' => 'Comunicazioni del Dirigente scolastico',
  'descrizione' => [
    'value' => '<p>Il Dirigente informa il Collegio sull\'organico di diritto assegnato e sul calendario delle attività funzionali.</p>',
    'format' => $formatoRicco,
  ],
  'deliberativo' => FALSE,
]);
$primo->save();

// --- 2. Approvazione unanime ---------------------------------------------
$secondo = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 2,
  'oggetto' => 'Approvazione del Piano Annuale per l\'Inclusione 2026/2027',
]);
$secondo->save();

$pai = $predisponi($secondo, [
  'numero_delibera' => '35',
  'quesito' => 'Si approva il Piano Annuale per l\'Inclusione 2026/2027?',
  'oggetto' => 'Piano Annuale per l\'Inclusione 2026/2027',
  'premesse' => [
    'value' => "Visto il DPR 275/1999, Regolamento recante norme in materia di autonomia delle istituzioni scolastiche\nVisto il D.lgs. 297/1994, Testo unico delle disposizioni legislative in materia di istruzione\nVista la L. 107/2015\nVisto il D.lgs. 66/2017\nVista la L. 170/2010\nVista la Direttiva ministeriale del 27/12/2012\nVista la C.M. n. 8 del 06/03/2013\nTenuto conto della proposta formulata dal Gruppo di Lavoro per l'Inclusione nella seduta del 12/06/2026",
    'format' => 'plain_text',
  ],
  'dispositivo' => [
    'value' => "Approva il Piano Annuale per l'Inclusione (PAI) per l'anno scolastico 2026/2027, allegato alla presente delibera di cui costituisce parte integrante.",
    'format' => 'plain_text',
  ],
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$pai->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ($votanti as $docente) {
  $urna->deposita($pai, $docente, [SchemaScheda::VOCE_FAVOREVOLE]);
}
$scrutinio->chiudiEScrutina($pai);

// --- 3. Approvazione a maggioranza, con contrari e astenuti ---------------
$terzo = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 3,
  'oggetto' => 'Adozione del piano delle attività funzionali all\'insegnamento',
]);
$terzo->save();

$piano = $predisponi($terzo, [
  'numero_delibera' => '36',
  'quesito' => 'Si adotta il piano delle attività funzionali all\'insegnamento?',
  'oggetto' => 'Piano delle attività funzionali all\'insegnamento 2026/2027',
  'premesse' => [
    'value' => "Visto l'art. 28 del CCNL comparto Istruzione e Ricerca del 18/01/2024\nVista la proposta del Dirigente scolastico\nSentite le osservazioni formulate in sede di discussione",
    'format' => 'plain_text',
  ],
  'dispositivo' => [
    'value' => 'Adotta il piano delle attività funzionali all\'insegnamento per l\'anno scolastico 2026/2027, con la riduzione a due incontri delle riunioni per materia del primo quadrimestre.',
    'format' => 'plain_text',
  ],
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$piano->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ([0, 1, 2, 3] as $posizione) {
  $urna->deposita($piano, $votanti[$posizione], [SchemaScheda::VOCE_FAVOREVOLE]);
}
$urna->deposita($piano, $votanti[4], [SchemaScheda::VOCE_CONTRARIO]);
$urna->deposita($piano, $votanti[5], [SchemaScheda::VOCE_ASTENUTO]);
$urna->deposita($piano, $votanti[6], [SchemaScheda::VOCE_ASTENUTO]);
$scrutinio->chiudiEScrutina($piano);

// --- 4. Votazione annullata e ripetuta (§8) -------------------------------
$quarto = PuntoOdg::create([
  'seduta' => $seduta->id(),
  'numero' => 4,
  'oggetto' => 'Designazione del referente per l\'inclusione',
]);
$quarto->save();

$annullata = $predisponi($quarto, [
  'quesito' => 'Chi si designa referente per l\'inclusione?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Prof.ssa Bianchi', 'Prof. Conti'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
]);
$annullata->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
$urna->deposita($annullata, $votanti[0], ['opzione_1']);
$urna->deposita($annullata, $votanti[1], ['opzione_2']);
$annullata->transitaA(
  StatoDelibera::ANNULLATA,
  'Il quesito ometteva la terza candidatura presentata in sede di discussione: la votazione è annullata e ripetuta con la scheda corretta.'
)->save();

$designazione = $predisponi($quarto, [
  'numero_delibera' => '37',
  'quesito' => 'Chi si designa referente per l\'inclusione?',
  'oggetto' => 'Designazione del referente per l\'inclusione 2026/2027',
  'premesse' => [
    'value' => "Visto il D.lgs. 66/2017\nVista la disponibilità dichiarata dalle docenti e dai docenti in sede di discussione\nRitenuto di procedere a scrutinio segreto trattandosi di designazione di persone",
    'format' => 'plain_text',
  ],
  'dispositivo' => [
    'value' => 'Designa la prof.ssa Marta Bianchi quale referente per l\'inclusione per l\'anno scolastico 2026/2027.',
    'format' => 'plain_text',
  ],
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'schema_scheda' => SchemaScheda::SCELTA_SINGOLA->value,
  'opzioni' => ['Prof.ssa Bianchi', 'Prof. Conti', 'Prof.ssa Ferrara'],
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_RELATIVA->value,
  'ripetizione_di' => $annullata->id(),
]);
$designazione->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ([0, 1, 2, 3] as $posizione) {
  $urna->deposita($designazione, $votanti[$posizione], ['opzione_1']);
}
$urna->deposita($designazione, $votanti[4], ['opzione_2']);
$urna->deposita($designazione, $votanti[5], ['opzione_3']);
$urna->deposita($designazione, $votanti[6], [SchemaScheda::VOCE_SCHEDA_BIANCA]);
$scrutinio->chiudiEScrutina($designazione);

$seduta->transitaA(StatoSeduta::CHIUSA)->save();

// --- Verbale e sigillo ----------------------------------------------------
$commutatore->switchTo($docenti[1]);
$verbale = $verbalizzazione->perSeduta($seduta);
$verbale->set('testo', [
  'value' => "<p>Il Presidente, verificata la regolare costituzione del Collegio, dichiara aperta la seduta alle ore 17:00.</p>\n<p>Sul secondo punto il Dirigente illustra le linee del Piano Annuale per l'Inclusione, richiamando il lavoro istruttorio del Gruppo di Lavoro per l'Inclusione. Interviene la prof.ssa De Luca chiedendo che sia esplicitato il raccordo con i Piani Didattici Personalizzati; il Dirigente conferma che il raccordo è già previsto al paragrafo 4 del Piano.</p>\n<p>Sul terzo punto interviene il prof. Greco, che manifesta perplessità sul numero delle riunioni per materia previste nel primo quadrimestre. Dopo ampia discussione il Presidente accoglie la proposta di ridurre a due gli incontri e la pone ai voti nella formulazione emendata.</p>\n<p>Sul quarto punto, aperta la votazione, la prof.ssa Ferrara segnala che la scheda non riporta la propria candidatura, presentata in sede di discussione. Il Presidente dispone l'annullamento della votazione ai sensi del §8 dell'allegato tecnico e la ripetizione con la scheda corretta.</p>\n<p>Esauriti i punti all'ordine del giorno, il Presidente dichiara chiusa la seduta alle ore 18:00.</p>",
  'format' => $formatoRicco,
])->save();

$verbale = $verbalizzazione->sigilla($verbale, $docenti[1]);
$commutatore->switchBack();

// --- Due sedute vive, per la scrivania -------------------------------------
// La seduta sigillata sopra non si tocca: aggiungervi un avente diritto dopo
// il sigillo farebbe divergere il registro vivo da quello conservato, che è
// esattamente ciò che il sigillo impedisce. L'utenza che guarda si iscrive
// invece a due sedute nuove, che mostrano gli stati su cui il blocco agisce.

$ospite = NULL;
$nomeOspite = trim((string) ($extra[0] ?? ''));
if ($nomeOspite !== '') {
  $trovate = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $nomeOspite]);
  $ospite = $trovate === [] ? NULL : reset($trovate);
  if ($ospite === NULL) {
    printf("\nAttenzione: nessuna utenza di nome «%s». Si usa quella di amministrazione.\n", $nomeOspite);
  }
}
$ospite ??= User::load(1);

$elencoVivo = [$ospite, ...array_slice($docenti, 0, 5)];

/** Costituisce una seduta con i propri aventi diritto. */
$costituisci = static function (array $valori, array $componenti): Seduta {
  $seduta = Seduta::create($valori);
  $seduta->save();
  foreach ($componenti as $componente) {
    Presenza::create(['seduta' => $seduta->id(), 'utente' => $componente->id()])->save();
  }

  return $seduta;
};

$convocata = $costituisci([
  'titolo' => ProvaPsiphos::titolo("Consiglio d'istituto — approvazione del programma annuale"),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '2/2026-27',
  'anno_scolastico' => '2026/27',
  'modalita' => 'mista',
  'url_videoconferenza' => 'https://meet.example.test/consiglio-programma',
  'data_convocazione' => $adesso,
  'data_seduta' => $adesso + (4 * 86400),
  'presidente' => $docenti[0]->id(),
  'segretario' => $docenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto, approvato con delibera del Consiglio d'istituto n. 4 del 12/09/2026",
], $elencoVivo);
PuntoOdg::create([
  'seduta' => $convocata->id(),
  'numero' => 1,
  'oggetto' => 'Approvazione del programma annuale',
])->save();

$inCorso = $costituisci([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti — seduta in corso'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '3/2026-27',
  'anno_scolastico' => '2026/27',
  'modalita' => 'telematica',
  'url_videoconferenza' => 'https://meet.example.test/collegio-in-corso',
  'data_convocazione' => $adesso - (3 * 86400),
  'data_seduta' => $adesso - 900,
  'presidente' => $docenti[0]->id(),
  'segretario' => $docenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto, approvato con delibera del Consiglio d'istituto n. 4 del 12/09/2026",
], $elencoVivo);
$puntoVivo = PuntoOdg::create([
  'seduta' => $inCorso->id(),
  'numero' => 1,
  'oggetto' => "Adesione all'accordo di rete per la formazione",
]);
$puntoVivo->save();
$inCorso->transitaA(StatoSeduta::APERTA)->save();
Delibera::create([
  'punto_odg' => $puntoVivo->id(),
  'quesito' => "Si approva l'adesione all'accordo di rete per la formazione?",
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
  'stato' => StatoDelibera::IN_VOTAZIONE->value,
])->save();

$conclusa = $costituisci([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti — verifica del piano di formazione'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'numero' => '4/2026-27',
  'anno_scolastico' => '2026/27',
  'modalita' => 'telematica',
  'url_videoconferenza' => 'https://meet.example.test/collegio-formazione',
  'data_convocazione' => $adesso - (12 * 86400),
  'data_seduta' => $adesso - (5 * 86400),
  'presidente' => $docenti[0]->id(),
  'segretario' => $docenti[1]->id(),
  'riferimento_regolamento' => "Art. 12-bis del Regolamento d'istituto, approvato con delibera del Consiglio d'istituto n. 4 del 12/09/2026",
], $elencoVivo);

$puntoConcluso = PuntoOdg::create([
  'seduta' => $conclusa->id(),
  'numero' => 1,
  'oggetto' => 'Verifica intermedia del piano di formazione del personale',
]);
$puntoConcluso->save();
$conclusa->transitaA(StatoSeduta::APERTA)->save();

// La presenza si registra sui componenti che hanno preso parte: senza, il
// verbale riporterebbe una seduta priva di numero legale.
foreach (\Drupal::entityTypeManager()->getStorage('psiphos_presenza')->loadByProperties(['seduta' => $conclusa->id()]) as $presenza) {
  $presenza
    ->set('stato', StatoPresenza::PRESENTE->value)
    ->set('ingresso', $adesso - (5 * 86400))
    ->set('uscita', $adesso - (5 * 86400) + 3000)
    ->set('ultima_attivita', $adesso - (5 * 86400) + 3000)
    ->save();
}

$deliberaConclusa = Delibera::create([
  'punto_odg' => $puntoConcluso->id(),
  'numero_delibera' => '38',
  'quesito' => 'Si approva la verifica intermedia del piano di formazione?',
  'oggetto' => 'Verifica intermedia del piano di formazione del personale',
  'premesse' => [
    'value' => "Visto il D.lgs. 297/1994
Vista la L. 107/2015, articolo 1, comma 124
Visto il piano di formazione approvato con delibera n. 12 del 03/09/2026
Sentita la relazione della funzione strumentale",
    'format' => 'plain_text',
  ],
  'dispositivo' => [
    'value' => "Approva la verifica intermedia del piano di formazione del personale per l'anno scolastico 2026/2027, confermando le unità formative già programmate.",
    'format' => 'plain_text',
  ],
  'tipo_voto' => TipoVoto::PALESE->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_VOTANTI->value,
]);
$deliberaConclusa->save();
$deliberaConclusa->transitaA(StatoDelibera::IN_VOTAZIONE)->save();
foreach ($elencoVivo as $componente) {
  $urna->deposita($deliberaConclusa, $componente, [SchemaScheda::VOCE_FAVOREVOLE]);
}
$scrutinio->chiudiEScrutina($deliberaConclusa);

$conclusa->transitaA(StatoSeduta::CHIUSA)->save();
$verbaleConcluso = $verbalizzazione->perSeduta($conclusa);
$verbaleConcluso->set('testo', [
  'value' => "<p>Il Presidente, verificata la regolare costituzione del Collegio, dichiara aperta la seduta.</p>\n<p>La funzione strumentale illustra lo stato di attuazione del piano di formazione. Non si registrano interventi contrari. Il Presidente pone ai voti la verifica intermedia, che è approvata all'unanimità.</p>",
  'format' => $formatoRicco,
])->save();
$verbaleConcluso = $verbalizzazione->sigilla($verbaleConcluso, $docenti[1]);

// --- Riepilogo ------------------------------------------------------------
$archivioDelibere = \Drupal::entityTypeManager()->getStorage('psiphos_delibera');
$archivioDelibere->resetCache();

echo "\nSeduta dimostrativa pronta.\n\n";
printf("  %-28s /psiphos/seduta/%d\n", 'Convocazione e o.d.g.', $seduta->id());
printf("  %-28s /psiphos/verbale/%d\n", 'Verbale', $verbale->id());
printf("  %-28s /psiphos/verbale/%d/documento\n", 'Verbale in PDF', $verbale->id());
printf("  %-28s /psiphos/verbale/%d/verifica\n", 'Verifica di integrità', $verbale->id());
printf("  %-28s /psiphos/seduta/%d/tracciature\n", 'Tracciature', $seduta->id());

echo "\n  Estratti di delibera\n";
foreach ($verbalizzazione->delibereDaFormalizzare($seduta) as $delibera) {
  $ricaricata = $archivioDelibere->load($delibera->id());
  printf(
    "  %-28s /psiphos/delibera/%d   (PDF: /psiphos/delibera/%d/documento)\n",
    'n. ' . $ricaricata->get('numero_delibera')->value . ' — ' . $ricaricata->oggettoAtto(),
    $ricaricata->id(),
    $ricaricata->id()
  );
}

printf("\n  Formato di conservazione: %s\n", (string) $verbale->get('formato')->value);
printf("\n  Sedute vive, per il blocco «le mie sedute collegiali»\n");
printf("  %-28s /psiphos/seduta/%d\n", 'Convocata fra quattro giorni', $convocata->id());
printf("  %-28s /psiphos/seduta/%d/aula\n", 'Aperta, votazione in corso', $inCorso->id());
printf("  %-28s /psiphos/verbale/%d\n", 'Conclusa e verbalizzata', $verbaleConcluso->id());
printf("  Avente diritto a tutte e tre: %s (uid %d).\n", $ospite->getAccountName(), (int) $ospite->id());
printf("  Il blocco si colloca da /admin/structure/block, categoria «Psíphos».\n");

echo "\n  Per rimuoverla: azzera_dati.php, oppure una qualunque suite di verifica.\n";
