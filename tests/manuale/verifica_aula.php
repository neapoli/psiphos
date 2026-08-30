<?php

/**
 * @file
 * Verifica funzionale dell'aula virtuale di Psíphos.
 *
 *   ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_aula.php
 *
 * Copre il §3.4 dell'allegato tecnico — sessioni attive, interruzione per
 * inattività, prevenzione di accessi simultanei — e la conduzione della
 * seduta dal banco di presidenza.
 */

declare(strict_types=1);

require_once __DIR__ . '/comune.php';

use Drupal\Core\Form\FormState;
use Drupal\psiphos\Controller\AulaController;
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
use Drupal\psiphos\Form\ControlliPresidenzaForm;
use Drupal\psiphos\Form\VotoForm;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EsitiAula {
  public static int $superate = 0;
  public static int $fallite = 0;

  public static function verifica(string $descrizione, bool $condizione): void {
    $condizione ? self::$superate++ : self::$fallite++;
    echo ($condizione ? "  ok   " : "  FAIL ") . $descrizione . "\n";
  }
}

$gestoreEntita = \Drupal::entityTypeManager();
$database = \Drupal::database();
$aula = \Drupal::service('psiphos.aula');
$urna = \Drupal::service('psiphos.urna');
$commutatore = \Drupal::service('account_switcher');
$pilaRichieste = \Drupal::service('request_stack');

/** Simula la sessione del browser da cui arriva la richiesta. */
$usaSessione = static function (string $identificativo) use ($pilaRichieste): void {
  $richiesta = Request::create('/psiphos');
  $sessione = new Session(new MockArraySessionStorage());
  $sessione->setId($identificativo);
  $richiesta->setSession($sessione);
  $pilaRichieste->push($richiesta);
};

/** Aziona un pulsante del banco di presidenza. */
$comanda = static function (string $comando, Seduta $seduta, array $valori = []): void {
  $statoForm = new FormState();
  $statoForm->setValues($valori);
  $statoForm->setTriggeringElement(['#name' => $comando]);
  $form = ControlliPresidenzaForm::create(\Drupal::getContainer());
  $struttura = [];
  $form->buildForm($struttura, $statoForm, $seduta);
  $form->submitForm($struttura, $statoForm);
};

/** Rimuove i soli dati creati da questa verifica. */
$ripulisci = static fn () => ProvaPsiphos::ripulisci();
ProvaPsiphos::esigiAmbienteDiProva($extra ?? []);
$ripulisci();

$ruolo = Role::create(['id' => 'psiphos_prova_docente', 'label' => 'Docente di prova Psíphos']);
foreach (['psiphos partecipare seduta', 'psiphos presiedere seduta', 'psiphos visualizzare verbali'] as $permesso) {
  $ruolo->grantPermission($permesso);
}
$ruolo->save();

$utenti = [];
for ($indice = 1; $indice <= 7; $indice++) {
  $utente = User::create([
    'name' => "psiphos_prova_$indice",
    'mail' => "psiphos_prova_$indice@example.test",
    'status' => 1,
    'roles' => ['psiphos_prova_docente'],
  ]);
  $utente->save();
  $utenti[] = $utente;
}
$presidente = $utenti[0];

$seduta = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Collegio dei docenti di prova'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $presidente->id(),
  'segretario' => $utenti[1]->id(),
]);
$seduta->save();
foreach ($utenti as $utente) {
  Presenza::create(['seduta' => $seduta->id(), 'utente' => $utente->id()])->save();
}
$punto = PuntoOdg::create(['seduta' => $seduta->id(), 'numero' => 1, 'oggetto' => 'Approvazione del PTOF']);
$punto->save();
$delibera = Delibera::create([
  'punto_odg' => $punto->id(),
  'quesito' => 'Si approva il PTOF 2026/29?',
  'tipo_voto' => TipoVoto::SEGRETO->value,
  'regola_maggioranza' => RegolaMaggioranza::MAGGIORANZA_PRESENTI->value,
]);
$delibera->save();

$usaSessione('sessione-presidente');

echo "\n[1] Ingresso in aula prima dell'apertura\n";
EsitiAula::verifica("l'aula è chiusa finché la seduta non è aperta", $aula->entra($seduta, $utenti[2]) === NULL);
EsitiAula::verifica('nessuno risulta presente', $seduta->numeroPresenti() === 0);

echo "\n[1-ter] Seduta con elenco degli aventi diritto vuoto\n";
// Aprire una seduta senza elettorato produce un vicolo cieco: in aula si
// entra solo se si figura in elenco, e il denominatore si congela
// all'apertura. Nessuno potrebbe più entrare né votare.
$sedutaVuota = Seduta::create([
  'titolo' => ProvaPsiphos::titolo('Senza aventi diritto'),
  'organo' => TipoOrgano::COLLEGIO_DOCENTI->value,
  'data_seduta' => \Drupal::time()->getRequestTime(),
  'presidente' => $presidente->id(),
  'segretario' => $utenti[1]->id(),
]);
$sedutaVuota->save();

$commutatore->switchTo($presidente);
$bancoVuoto = (string) \Drupal::service('renderer')->renderRoot(
  \Drupal::formBuilder()->getForm(ControlliPresidenzaForm::class, $sedutaVuota)
);
$commutatore->switchBack();
EsitiAula::verifica("con l'elenco vuoto non si offre di aprire la seduta", !str_contains($bancoVuoto, 'Dichiara aperta la seduta'));
EsitiAula::verifica('e se ne spiega la ragione', str_contains($bancoVuoto, 'elenco degli aventi diritto è vuoto'));

$comandaVuota = static function (string $comando) use ($sedutaVuota): void {
  $statoForm = new FormState();
  $statoForm->setTriggeringElement(['#name' => $comando]);
  $form = ControlliPresidenzaForm::create(\Drupal::getContainer());
  $struttura = [];
  $form->buildForm($struttura, $statoForm, $sedutaVuota);
  $form->submitForm($struttura, $statoForm);
};
$comandaVuota('apri_seduta');
EsitiAula::verifica(
  "e il comando forzato non apre comunque la seduta",
  $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($sedutaVuota->id())->stato() === StatoSeduta::CONVOCATA
);

\Drupal::database()->delete('psiphos_audit')->condition('seduta', $sedutaVuota->id())->execute();
$gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($sedutaVuota->id())->delete();

echo "\n[1-bis] Aula prima dell'apertura\n";
// Chi non è ancora accreditato non è in conflitto con nessuno: segnalargli
// un doppio collegamento lo manderebbe a cercare un dispositivo inesistente,
// e l'avviso interrompe la pagina prima del banco di presidenza.
$controllerPrimaApertura = AulaController::create(\Drupal::getContainer());
$commutatore->switchTo($presidente);
$usaSessione('sessione-presidente');
$paginaPrimaApertura = (string) \Drupal::service('renderer')->renderRoot(
  $controllerPrimaApertura->aula($gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()))
);
$commutatore->switchBack();
EsitiAula::verifica(
  'a seduta convocata non si segnala alcun doppio collegamento',
  !str_contains($paginaPrimaApertura, 'altro dispositivo')
);
EsitiAula::verifica(
  'il banco di presidenza resta raggiungibile',
  str_contains($paginaPrimaApertura, 'Dichiara aperta la seduta')
);
EsitiAula::verifica(
  "e chi partecipa legge che la seduta non è ancora aperta",
  str_contains($paginaPrimaApertura, 'non è ancora aperta')
);

// Prima dell'apertura nessuno è entrato: dire che il quorum manca sarebbe
// vero e fuorviante, perché si legge come un impedimento.
EsitiAula::verifica(
  'il quorum non è dichiarato mancante prima dell\'apertura',
  !$aula->quorumInDifetto($gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()))
);
EsitiAula::verifica(
  'ma si annuncia che si verificherà all\'apertura',
  str_contains($aula->etichettaQuorum($gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id())), "all'apertura")
);
EsitiAula::verifica(
  'e la pagina non porta lo stile di allarme',
  !str_contains($paginaPrimaApertura, 'quorum--mancante')
);

// Finché nessuna urna è aperta, la firma di chi presiede segue le presenze,
// così l'appello si aggiorna da sé mentre i docenti entrano.
$controllerPrimaApertura = AulaController::create(\Drupal::getContainer());
$commutatore->switchTo($presidente);
$statoPrimaApertura = (array) json_decode($controllerPrimaApertura->stato(
  $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()),
  Request::create('/stato')
)->getContent(), TRUE);
$commutatore->switchBack();
EsitiAula::verifica(
  "senza votazioni aperte la firma di chi presiede segue le presenze",
  str_contains((string) $statoPrimaApertura['firma'], 'atteso:')
);

$commutatore->switchTo($utenti[2]);
$statoPartecipante = (array) json_decode($controllerPrimaApertura->stato(
  $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()),
  Request::create('/stato')
)->getContent(), TRUE);
$commutatore->switchBack();
EsitiAula::verifica(
  'mentre quella di chi partecipa no',
  !str_contains((string) $statoPartecipante['firma'], 'atteso:')
);

echo "\n[2] Apertura della seduta dal banco di presidenza\n";
$commutatore->switchTo($presidente);
$comanda('apri_seduta', $seduta);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('seduta dichiarata aperta', $seduta->stato() === StatoSeduta::APERTA);

echo "\n[3] Quorum costitutivo e messa ai voti\n";
foreach ([0, 1, 2] as $posizione) {
  $usaSessione('sessione-' . $posizione);
  $aula->entra($seduta, $utenti[$posizione]);
}
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('3 presenti su 7: quorum non raggiunto', !$seduta->validamenteCostituita());
EsitiAula::verifica('a seduta aperta il difetto di quorum è dichiarato', $aula->quorumInDifetto($seduta));
EsitiAula::verifica('con l\'etichetta «non raggiunto»', $aula->etichettaQuorum($seduta) === 'non raggiunto');

$comanda('apri_votazione:' . $delibera->id(), $seduta);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica(
  'senza quorum la votazione non si apre',
  $delibera->stato() === StatoDelibera::PREDISPOSTA
);

foreach ([3, 4] as $posizione) {
  $usaSessione('sessione-' . $posizione);
  $aula->entra($seduta, $utenti[$posizione]);
}
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('5 presenti su 7: quorum raggiunto', $seduta->validamenteCostituita());
EsitiAula::verifica('e l\'etichetta diventa «raggiunto»', $aula->etichettaQuorum($seduta) === 'raggiunto');

$comanda('apri_votazione:' . $delibera->id(), $seduta);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica('con il quorum la votazione si apre', $delibera->stato() === StatoDelibera::IN_VOTAZIONE);
EsitiAula::verifica('presenti congelati al voto = 5', (int) $delibera->get('presenti_al_voto')->value === 5);

echo "\n[3-bis] Aggiornamento del quorum senza ricaricare\n";
$controllerAula = AulaController::create(\Drupal::getContainer());
$statoPer = static function ($utente) use ($controllerAula, $gestoreEntita, $seduta, $commutatore): array {
  $commutatore->switchTo($utente);
  $risposta = $controllerAula->stato(
    $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()),
    Request::create('/stato')
  );
  $commutatore->switchBack();

  return (array) json_decode($risposta->getContent(), TRUE);
};

$statoPresidente = $statoPer($presidente);
EsitiAula::verifica("lo stato riferisce l'etichetta del quorum", ($statoPresidente['quorumEtichetta'] ?? '') === 'raggiunto');
EsitiAula::verifica('e la costituzione della seduta', ($statoPresidente['costituita'] ?? FALSE) === TRUE);

// Il quorum entra nella firma di chi presiede, perché ne cambia i comandi,
// e non in quella di chi vota, per non azzerargli la scheda in compilazione.
EsitiAula::verifica(
  'la firma di chi presiede tiene conto del quorum',
  str_contains((string) $statoPresidente['firma'], 'costituita')
);
$statoVotante = $statoPer($utenti[2]);
EsitiAula::verifica(
  'quella di chi vota no',
  !str_contains((string) $statoVotante['firma'], 'costituita')
);

// Perdendo il quorum la firma del presidente cambia: i comandi vanno rifatti.
// Su 7 aventi diritto la soglia è 4: per scendere sotto occorre che se ne
// disconnettano due dei cinque presenti.
$firmaConQuorum = (string) $statoPresidente['firma'];
foreach ([4, 3] as $posizioneUscente) {
  \Drupal::moduleHandler()->invoke('psiphos', 'user_logout', [$utenti[$posizioneUscente]]);
}
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('due disconnessioni fanno scendere i presenti a 3', $seduta->numeroPresenti() === 3);
EsitiAula::verifica('e il quorum non è più raggiunto', !$seduta->validamenteCostituita());

$statoPresidente = $statoPer($presidente);
EsitiAula::verifica('il presidente vede cambiare la firma', (string) $statoPresidente['firma'] !== $firmaConQuorum);
EsitiAula::verifica("e l'etichetta diventa «non raggiunto»", ($statoPresidente['quorumEtichetta'] ?? '') === 'non raggiunto');

// Si ripristina la situazione per le sezioni successive.
foreach ([3, 4] as $posizioneRientrante) {
  $usaSessione('sessione-' . $posizioneRientrante);
  $aula->entra($gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()), $utenti[$posizioneRientrante]);
}
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('rientrati entrambi, il quorum torna', $seduta->validamenteCostituita() && $seduta->numeroPresenti() === 5);

echo "\n[3-ter] Appello per chi presiede\n";
// Durante l'ingresso in aula chi presiede ha bisogno di sapere chi manca,
// non solo quanti: l'elenco si aggiorna da sé. A urna aperta no, perché
// ridisegnare azzererebbe la scheda in compilazione.
$commutatore->switchTo($presidente);
$usaSessione('sessione-presidente');
$paginaAppello = (string) \Drupal::service('renderer')->renderRoot(
  $controllerAula->aula($gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()))
);
$commutatore->switchBack();
EsitiAula::verifica("l'appello compare a chi presiede", str_contains($paginaAppello, 'Appello —'));
EsitiAula::verifica('e riporta i nominativi degli aventi diritto', str_contains($paginaAppello, $utenti[0]->getAccountName()));

// A urna aperta l'impronta delle presenze esce dalla firma: un ingresso non
// deve ricostruire la pagina di chi sta compilando la scheda.
$firmaPresidenteVotazione = (string) $statoPer($presidente)['firma'];
EsitiAula::verifica(
  "a urna aperta la firma di chi presiede non segue più le presenze",
  !str_contains($firmaPresidenteVotazione, 'presente:')
);
EsitiAula::verifica(
  'e quella di chi vota nemmeno',
  !str_contains((string) $statoPer($utenti[2])['firma'], 'presente:')
);

echo "\n[4] Scheda di voto\n";
$deposita = static function (Delibera $delibera, User $votante, array $scelte): void {
  $statoForm = new FormState();
  $statoForm->setValues(['scelte' => count($scelte) === 1 ? reset($scelte) : array_combine($scelte, $scelte)]);
  $form = VotoForm::create(\Drupal::getContainer());
  $struttura = [];
  $form->buildForm($struttura, $statoForm, $delibera, $votante);
  $form->submitForm($struttura, $statoForm);
};

$usaSessione('sessione-2');
$commutatore->switchTo($utenti[2]);
$deposita($delibera, $utenti[2], [SchemaScheda::VOCE_FAVOREVOLE]);
EsitiAula::verifica('scheda depositata dal form', $urna->numeroSchede($delibera) === 1);
EsitiAula::verifica('il votante risulta avere votato', $urna->haVotato($delibera, $utenti[2]));

$deposita($delibera, $utenti[2], [SchemaScheda::VOCE_CONTRARIO]);
EsitiAula::verifica('un secondo deposito non aggiunge schede', $urna->numeroSchede($delibera) === 1);

$struttura = [];
$statoForm = new FormState();
$form = VotoForm::create(\Drupal::getContainer());
$struttura = $form->buildForm($struttura, $statoForm, $delibera, $utenti[3]);
// Il form è ricostruito anche dentro il frammento di aggiornamento, da cui
// erediterebbe l'indirizzo del frammento: l'invio finirebbe su una risposta
// che il browser mostra come testo invece di seguirla. L'indirizzo è perciò
// dichiarato dal form stesso e non dipende da dove viene reso.
$indirizzoAula = \Drupal\Core\Url::fromRoute('psiphos.aula', ['psiphos_seduta' => $seduta->id()])->toString();
$schedaResa = \Drupal::formBuilder()->getForm(VotoForm::class, $delibera, $utenti[3]);
EsitiAula::verifica("la scheda invia alla pagina dell'aula", ($schedaResa['#action'] ?? '') === $indirizzoAula);
$bancoReso = \Drupal::formBuilder()->getForm(ControlliPresidenzaForm::class, $seduta);
EsitiAula::verifica('e così il banco di presidenza', ($bancoReso['#action'] ?? '') === $indirizzoAula);

EsitiAula::verifica('scheda a voce unica resa come radio', $struttura['scelte']['#type'] === 'radios');
EsitiAula::verifica('tre voci sulla scheda di approvazione', count($struttura['scelte']['#options']) === 3);
EsitiAula::verifica('la scheda dichiara la modalità di voto', str_contains((string) $struttura['modalita']['#markup'], 'scrutinio segreto'));

echo "\n[4-bis] Partecipazione visibile al banco di presidenza\n";
// Il presidente deve sapere quante schede sono state depositate per decidere
// quando chiudere; il conteggio dei voti resta invisibile a tutti fino allo
// scrutinio, anche a lui.
$bancoInVotazione = (string) \Drupal::service('renderer')->renderRoot(
  \Drupal::formBuilder()->getForm(ControlliPresidenzaForm::class, $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()))
);
EsitiAula::verifica('il banco riporta le schede depositate', str_contains($bancoInVotazione, 'Schede depositate'));
EsitiAula::verifica(
  'e il numero è aggiornabile senza ricaricare',
  str_contains($bancoInVotazione, 'data-psiphos="votanti"')
);
// Il confronto è sui valori dentro i contrassegni, non sul testo attorno:
// legarlo alla formulazione della frase renderebbe la verifica fragile a
// ogni riscrittura del messaggio.
$valoreContrassegnato = static function (string $html, string $dato): ?string {
  return preg_match('#data-psiphos="' . preg_quote($dato, '#') . '"[^>]*>([^<]*)<#', $html, $trovato)
    ? trim($trovato[1])
    : NULL;
};
EsitiAula::verifica('una scheda risulta depositata', $valoreContrassegnato($bancoInVotazione, 'votanti') === '1');
EsitiAula::verifica('su cinque presenti all\'apertura dell\'urna', $valoreContrassegnato($bancoInVotazione, 'presenti-al-voto') === '5');
EsitiAula::verifica('e ne mancano quattro', $valoreContrassegnato($bancoInVotazione, 'mancanti') === '4');
EsitiAula::verifica(
  'ma non compare alcun conteggio dei voti',
  !str_contains($bancoInVotazione, 'Favorevole') && !str_contains($bancoInVotazione, 'Contrario')
);

// Ogni numero che cambia mentre si vota deve essere contrassegnato: uno solo
// aggiornato accanto a uno statico produce due dati che si contraddicono.
foreach (['votanti', 'presenti-al-voto', 'mancanti', 'quorum', 'presenti', 'aventi-diritto'] as $datoVivo) {
  EsitiAula::verifica(
    sprintf('il banco contrassegna «%s» come aggiornabile', $datoVivo),
    str_contains($bancoInVotazione, 'data-psiphos="' . $datoVivo . '"')
  );
}

$statoInVotazione = $statoPer($presidente);
EsitiAula::verifica(
  'lo stato riferisce le schede mancanti',
  ($statoInVotazione['mancanti'] ?? NULL) === ($statoInVotazione['presentiAlVoto'] ?? 0) - ($statoInVotazione['votanti'] ?? 0)
);

echo "\n[5] Sessione esclusiva (§3.4)\n";
$presenza = $aula->presenza($seduta, $utenti[2]);
EsitiAula::verifica('la sessione corrente è riconosciuta', $aula->sessioneRiconosciuta($presenza));
$usaSessione('sessione-2-altro-dispositivo');
$presenza = $aula->presenza($seduta, $utenti[2]);
EsitiAula::verifica('una sessione diversa non è riconosciuta', !$aula->sessioneRiconosciuta($presenza));
EsitiAula::verifica('ed è segnalata come soppiantata', $aula->sessioneSuperata($presenza));
$maiEntrato = $aula->presenza($seduta, $utenti[6]);
EsitiAula::verifica(
  'mentre chi non è mai entrato non risulta soppiantato da nessuno',
  $maiEntrato !== NULL && !$aula->sessioneSuperata($maiEntrato)
);
EsitiAula::verifica('e non è abilitata al voto', !$aula->abilitatoAlVoto($seduta, $utenti[2]));
$aula->entra($seduta, $utenti[2]);
$presenza = $aula->presenza($seduta, $utenti[2]);
EsitiAula::verifica("l'ingresso dal nuovo dispositivo accredita questa sessione", $aula->sessioneRiconosciuta($presenza));
$usaSessione('sessione-2');
EsitiAula::verifica('e la sessione precedente non è più riconosciuta', !$aula->sessioneRiconosciuta($aula->presenza($seduta, $utenti[2])));

echo "\n[6] Interruzione per assenza di contatto (§3.4)\n";
$timeout = $aula->timeoutInattivita();
EsitiAula::verifica('tolleranza configurata a 900 secondi', $timeout === 900);
$presenzaScaduta = $aula->presenza($seduta, $utenti[4]);
$presenzaScaduta->set('ultima_attivita', \Drupal::time()->getRequestTime() - $timeout - 1)->save();
$decadute = $aula->decadiPresenzeScadute($seduta);
EsitiAula::verifica('una presenza inattiva è decaduta', $decadute === 1);
EsitiAula::verifica(
  'lo stato è decaduta, non uscito',
  $aula->presenza($seduta, $utenti[4])->stato() === StatoPresenza::DECADUTA
);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('i presenti scendono a 4', $seduta->numeroPresenti() === 4);
EsitiAula::verifica('chi è decaduto non può più votare', !$aula->abilitatoAlVoto($seduta, $utenti[4]));

$usaSessione('sessione-3');
$presenzaViva = $aula->presenza($seduta, $utenti[3]);
$presenzaViva->set('ultima_attivita', \Drupal::time()->getRequestTime() - $timeout - 1)->save();
$aula->rinnova($seduta, $utenti[3]);
EsitiAula::verifica(
  'un segnale dal dispositivo rinnova la permanenza',
  $aula->decadiPresenzeScadute($seduta) === 0
);

// L'interrogazione dello stato è essa stessa il segnale: chi segue la
// videoconferenza senza toccare l'aula non deve decadere.
$presenzaViva = $aula->presenza($seduta, $utenti[3]);
$presenzaViva->set('ultima_attivita', \Drupal::time()->getRequestTime() - $timeout - 1)->save();
$commutatore->switchTo($utenti[3]);
$controllerAula->stato(
  $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id()),
  Request::create('/stato')
);
$commutatore->switchBack();
EsitiAula::verifica(
  "la sola interrogazione dello stato mantiene la presenza, senza alcuna interazione",
  $aula->decadiPresenzeScadute($seduta) === 0
);
$usaSessione('sessione-estranea');
$aula->presenza($seduta, $utenti[3])->set('ultima_attivita', \Drupal::time()->getRequestTime() - $timeout - 1)->save();
$aula->rinnova($seduta, $utenti[3]);
EsitiAula::verifica(
  'una sessione non riconosciuta non rinnova nulla',
  $aula->decadiPresenzeScadute($seduta) === 1
);

echo "\n[6-bis] Disconnessione dal sito\n";
// Chi si disconnette lascia l'aula: attendere il timeout di inattività
// vorrebbe dire computarlo fra i presenti mentre non c'è più.
$usaSessione('sessione-2');
$aula->entra($seduta, $utenti[2]);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
$presentiPrima = $seduta->numeroPresenti();

\Drupal::moduleHandler()->invoke('psiphos', 'user_logout', [$utenti[2]]);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('la disconnessione toglie subito dal computo dei presenti', $seduta->numeroPresenti() === $presentiPrima - 1);
$posizioneDisconnessa = $aula->presenza($seduta, $utenti[2]);
EsitiAula::verifica('lo stato è uscito, non decaduto', $posizioneDisconnessa->stato() === StatoPresenza::USCITO);
EsitiAula::verifica(
  "l'accreditamento della sessione è revocato",
  ((string) $posizioneDisconnessa->get('impronta_sessione')->value) === ''
);
EsitiAula::verifica('chi si è disconnesso non può votare', !$aula->abilitatoAlVoto($seduta, $utenti[2]));
$usciteTracciate = \Drupal::database()->select('psiphos_audit', 'a')
  ->fields('a', ['contesto'])
  ->condition('seduta', $seduta->id())
  ->condition('evento', 'aula.uscita')
  ->execute()
  ->fetchCol();
EsitiAula::verifica(
  "l'uscita è tracciata distinguendone la causa",
  $usciteTracciate !== [] && str_contains((string) end($usciteTracciate), 'disconnessione')
);

$usaSessione('sessione-2-rientro');
$aula->entra($seduta, $utenti[2]);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('rientrando si torna a essere presenti', $seduta->numeroPresenti() === $presentiPrima);

echo "\n[7] Sospensione e ripresa dal banco di presidenza (§8)\n";
$commutatore->switchTo($presidente);
$usaSessione('sessione-presidente');
$comanda('sospendi:' . $delibera->id(), $seduta);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica('senza motivazione la sospensione non passa', $delibera->stato() === StatoDelibera::IN_VOTAZIONE);

$comanda('sospendi:' . $delibera->id(), $seduta, ['motivazione_' . $delibera->id() => 'Interruzione della connessione in aula.']);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica('con la motivazione la votazione è sospesa', $delibera->stato() === StatoDelibera::SOSPESA);
EsitiAula::verifica('le schede già depositate restano nell\'urna', $urna->numeroSchede($delibera) === 1);

$comanda('chiudi_seduta', $seduta);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('la seduta non si chiude con una votazione sospesa', $seduta->stato() === StatoSeduta::APERTA);

$comanda('riprendi:' . $delibera->id(), $seduta);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica('votazione ripresa', $delibera->stato() === StatoDelibera::IN_VOTAZIONE);

echo "\n[8] Chiusura della votazione e della seduta\n";
foreach ([0, 1] as $posizione) {
  $usaSessione('sessione-' . $posizione);
  $commutatore->switchTo($utenti[$posizione]);
  $deposita($delibera, $utenti[$posizione], [SchemaScheda::VOCE_FAVOREVOLE]);
}
$commutatore->switchTo($presidente);
$usaSessione('sessione-presidente');
$comanda('chiudi_votazione:' . $delibera->id(), $seduta);
$delibera = $gestoreEntita->getStorage('psiphos_delibera')->loadUnchanged($delibera->id());
EsitiAula::verifica('votazione chiusa e scrutinata', $delibera->stato() === StatoDelibera::CHIUSA);
EsitiAula::verifica('3 favorevoli su 5 presenti al voto: approvata', $delibera->esito() === EsitoDelibera::APPROVATA);
EsitiAula::verifica('sigillo apposto alla chiusura', strlen((string) $delibera->get('sigillo_urna')->value) === 64);

$comanda('chiudi_seduta', $seduta);
$seduta = $gestoreEntita->getStorage('psiphos_seduta')->loadUnchanged($seduta->id());
EsitiAula::verifica('conclusa la votazione, la seduta si chiude', $seduta->stato() === StatoSeduta::CHIUSA);

// Chi è rimasto collegato fino alla fine non compie alcun gesto di uscita:
// la sua presenza si conclude con la chiusura dei lavori.
$registroFinale = $gestoreEntita->getStorage('psiphos_presenza')->loadByProperties(['seduta' => $seduta->id()]);
$senzaUscita = array_filter(
  $registroFinale,
  static fn ($posizione): bool => $posizione->get('ingresso')->value !== NULL && $posizione->get('uscita')->value === NULL
);
EsitiAula::verifica('nessun ingresso resta senza la propria uscita', $senzaUscita === []);

$rimasti = array_filter(
  $registroFinale,
  static fn ($posizione): bool => $posizione->stato() === StatoPresenza::PRESENTE
);
EsitiAula::verifica('chi ha concluso i lavori resta registrato come presente', $rimasti !== []);
$primoRimasto = reset($rimasti);
EsitiAula::verifica(
  "e la sua uscita coincide con la chiusura della seduta",
  (int) $primoRimasto->get('uscita')->value === (int) $seduta->get('chiusa_il')->value
);
EsitiAula::verifica(
  "l'accreditamento della sessione è stato revocato",
  ((string) $primoRimasto->get('impronta_sessione')->value) === ''
);

echo "\n[9] Endpoint di stato\n";
$commutatore->switchTo($utenti[1]);
$controller = AulaController::create(\Drupal::getContainer());
$carico = json_decode($controller->stato($seduta, Request::create('/stato'))->getContent(), TRUE);
EsitiAula::verifica('lo stato riporta la seduta chiusa', $carico['seduta'] === 'chiusa');
EsitiAula::verifica('nessuna votazione in corso', $carico['delibera'] === NULL);
EsitiAula::verifica('la risposta non contiene alcun conteggio di voto', !array_key_exists('conteggio', $carico) && !array_key_exists('favorevoli', $carico));
EsitiAula::verifica('la firma è valorizzata', is_string($carico['firma']) && $carico['firma'] !== '');

printf("\n--- %d superate, %d fallite ---\n", EsitiAula::$superate, EsitiAula::$fallite);

ProvaPsiphos::ripristinaUtenza();
$ripulisci();
echo "pulizia completata\n";
