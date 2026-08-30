# Elementi tecnici per la valutazione d'impatto

**Attua**: allegato tecnico alla nota MIM prot. 3803 del 30/06/2026, §6 —
*«nei casi previsti, e in particolare per i sistemi di voto digitale, deve
essere effettuata una valutazione d'impatto sulla protezione dei dati»*.

> **Che cosa è questo documento e che cosa non è.** La valutazione d'impatto è
> dell'istituzione scolastica, che del trattamento è titolare, e va condotta con
> il proprio DPO. Qui si trova la descrizione tecnica del trattamento — quella
> che il titolare non può ricavare da solo senza leggere il codice — e null'altro.
> Non è una DPIA compilata, non la sostituisce e non la semplifica: ne fornisce
> la sezione tecnica.

**L'allegato da consegnare al DPO è precompilato.** In
`/admin/reports/psiphos/documentazione`, il bottone «Scarica il modulo
precompilato» produce la *Descrizione tecnica del trattamento*: la stessa
materia dei §§ 1-7 che seguono, sulla carta intestata dell'istituto e — questo
è il punto — **con i valori della configurazione in essere**, non con quelli
predefiniti. Riporta il livello di autenticazione effettivamente osservato sul
sito, la tolleranza di collegamento, la sessione esclusiva, il formato di
conservazione producibile su quel server e i termini di ritenzione. È il
documento da allegare alla valutazione; il testo che segue serve a condurla.

Va **riprodotto a ogni modifica delle impostazioni**: la valutazione si fonda
sulla configurazione descritta, e una configurazione diversa è un trattamento
diverso.

---

## Come si conduce la valutazione

### Perché è obbligatoria, e non discrezionale

L'art. 35 del GDPR la impone quando il trattamento «può presentare un rischio
elevato per i diritti e le libertà delle persone fisiche». Il §6 dell'allegato
tecnico toglie ogni margine di apprezzamento per questo caso:

> «Valutazione d'impatto: nei casi previsti, e **in particolare per i sistemi
> di voto digitale**, deve essere effettuata una valutazione d'impatto sulla
> protezione dei dati (DPIA).»

Il voto a scrutinio segreto è il motivo per cui ricorre: la lesione temuta non
è la divulgazione di un dato, è la **compromissione della libertà di voto**, e
si produce anche solo per la percezione che la correlazione sia possibile.

### Chi la fa

| Soggetto | Ruolo |
|---|---|
| **Dirigente scolastico**, quale titolare | conduce la valutazione e la sottoscrive. È l'unico che possa accettare un rischio residuo |
| **Responsabile della protezione dei dati** | è consultato obbligatoriamente (art. 35, § 2) e il suo parere va acquisito per iscritto e conservato |
| **Fornitore del modulo** | fornisce la descrizione tecnica del trattamento, cioè questo documento (art. 28, § 3, lett. f) |
| **Fornitore dell'hosting** | fornisce le informazioni sull'infrastruttura richieste in `richieste-al-fornitore-hosting.md` |

La valutazione **non si delega al fornitore**: una DPIA redatta dal fornitore e
firmata dal dirigente senza istruttoria propria non è una valutazione del
titolare, ed è la prima cosa che un'autorità di controllo rileva.

### Quando

**Prima dell'inizio del trattamento** (art. 35, § 1), quindi prima della prima
seduta deliberativa a distanza — insieme all'adeguamento del Regolamento e agli
atti di nomina.

Va **riesaminata** quando cambia il rischio: mutamento del livello di
autenticazione, cambio del fornitore di hosting, estensione ad altri organi,
incidente di sicurezza.

### Come si compone

L'art. 35, § 7, prescrive quattro contenuti minimi. Questo documento fornisce
la materia tecnica per tutti e quattro, ma **non li sostituisce**: la
valutazione, la ponderazione e l'accettazione del rischio residuo restano atti
del titolare.

| Contenuto prescritto | Dove trovarlo qui | Che cosa vi aggiunge il titolare |
|---|---|---|
| a) Descrizione sistematica dei trattamenti e delle finalità | §§ 1, 2, 3 | il contesto organizzativo dell'istituto, i tempi, i volumi |
| b) Valutazione della necessità e proporzionalità | §§ 1, 3 | perché la modalità a distanza è necessaria e perché non basta una meno invasiva |
| c) Valutazione dei rischi per i diritti e le libertà | §§ 4, 5, 6 | la ponderazione di probabilità e gravità nel proprio contesto |
| d) Misure previste per affrontare i rischi | §§ 4, 5, 6, 8 | le misure organizzative proprie e l'accettazione motivata del residuo |

### Se il rischio residuo resta elevato

L'art. 36 impone la **consultazione preventiva del Garante** quando la
valutazione indica un rischio elevato che il titolare non riesce ad attenuare.

Non è il caso ordinario di questo trattamento: le misure tecniche descritte al
§ 4 riducono il rischio principale a un residuo di natura infrastrutturale,
contenibile con misure organizzative. Ma se l'istituzione non riuscisse a
ottenere dal fornitore dell'hosting alcuna informazione sui registri del motore
di banca dati, né alcun impegno sul loro utilizzo, quel residuo resterebbe
indeterminato — e l'ipotesi dell'art. 36 andrebbe valutata con il DPO.

---

## 1. Descrizione del trattamento

**Finalità.** Svolgimento a distanza delle attività collegiali deliberative dei
docenti previste dall'art. 44, commi 3 lett. a) e b), del CCNL Istruzione e
Ricerca 2019-2021.

**Base giuridica.** Esecuzione di un compito di interesse pubblico connesso
all'esercizio di pubblici poteri (art. 6, par. 1, lett. e, GDPR), in attuazione
del CCNL e della nota ministeriale citata.

**Natura della soluzione.** Modulo software installato sull'infrastruttura
dell'istituzione scolastica. **Non vi è alcun fornitore esterno che tratti dati
di seduta per conto del titolare**: non ricorre la nomina a responsabile del
trattamento ai sensi dell'art. 28 GDPR per le operazioni di voto, e i dati non
lasciano il sistema informativo della scuola.

Resta esterno lo strumento di videoconferenza, che il modulo non eroga e non
sostituisce: per quello la nomina a responsabile, la verifica della
localizzazione dei dati e le garanzie contrattuali restano necessarie e vanno
valutate separatamente.

## 2. Categorie di interessati

Personale docente avente diritto di partecipazione agli organi collegiali.
Non sono trattati dati di studenti né di famiglie.

## 3. Dati trattati

| Dato | Dove | Conservazione |
|---|---|---|
| Identificativo utenza, nominativo | elenco aventi diritto, presenze | fino alla cancellazione della seduta |
| Ingresso, uscita, ultima interazione | registro presenze | come sopra |
| Impronta della sessione (HMAC-SHA256) | registro presenze | azzerata all'uscita |
| Partecipazione al voto (chi ha votato) | attestazioni di voto | come sopra |
| **Voto espresso, con nominativo** | voti palesi | come sopra |
| **Voto espresso, senza alcun riferimento al votante** | urna segreta | come sopra |
| Nominativi, presenze, esiti, voti palesi | verbale sigillato e documento conservato | termini di conservazione degli atti |
| Eventi del procedimento con identificativo utenza | tracciature tecniche | configurabile, predefinito 10 anni |
| **Giustificazione dell'assenza (testo libero)** | registro presenze, verbale sigillato | termini di conservazione degli atti |

**Non sono trattati**: indirizzi IP, dati di navigazione, registrazioni audio o
video, dati biometrici.

**Categorie particolari di dati (art. 9 GDPR): il modulo non ne raccoglie, ma
non può impedire che vi finiscano.** La giustificazione dell'assenza è un campo
di testo libero, e chi verbalizza può annotarvi il motivo di salute che
l'assenza ha determinato. Quel testo entra nel registro presenze e finisce nel
verbale sigillato, dove diventa immodificabile e destinato a durare quanto gli
atti dell'istituto.

Nessun controllo automatico può distinguere «assenza giustificata» da
«ricovero ospedaliero»: la misura è organizzativa, e consiste nell'istruire chi
redige a indicare la sola qualificazione formale dell'assenza e non la ragione.
Va prevista nel Regolamento o nelle istruzioni al segretario verbalizzante.

L'identificativo di sessione non è conservato: se ne conserva l'impronta
calcolata con chiave, sufficiente a riconoscere la sessione accreditata e
inservibile a ricostruirla.

## 4. Il rischio principale: correlazione fra identità e voto segreto

È il rischio che qualifica il trattamento e che rende la valutazione d'impatto
necessaria. La lesione non è la divulgazione di un dato, è la compromissione
della libertà di voto — e si produce anche solo per la percezione che la
correlazione sia possibile.

### Misure tecniche adottate

| Misura | Effetto |
|---|---|
| Attestazione di voto e scheda in tabelle separate, senza colonne comuni oltre alla votazione | nessun campo collega identità e voto |
| Tabelle semplici e non entità del CMS: niente uuid, marche di creazione o modifica, né hook intercettabili da altri componenti | superficie di scrittura ridotta al minimo |
| Nessuna marca temporale, né sulla scheda né sull'attestazione di partecipazione | non esiste l'istante da accostare all'ordine di deposito. L'ora di ciascun voto resta nelle sole tracciature, che non toccano la scheda |
| Identificativo di scheda casuale a 62 bit come chiave primaria | l'ordine fisico di memorizzazione non conserva l'ordine di deposito |
| Preferenze ridotte a forma canonica ordinata | l'ordine di spunta non viene conservato |
| Nessuna bozza di scheda in sessione, cache o archivi temporanei | la scelta esiste solo nella richiesta che la deposita |
| Tracciature che registrano *che* si è votato, mai *che cosa* | il registro di audit non reintroduce la correlazione |

### Rischio residuo, da dichiarare

Le misure sopra impediscono la correlazione a **chi guardi le tabelle**: non
esiste alcun campo, alcuna marca temporale e alcun ordine che colleghi la
scheda al votante.

Resta esposto chi disponga dei **registri del motore di banca dati** — i
*binary log*, i *redo log*, i registri delle interrogazioni — che stanno sotto
l'applicazione e ne conservano l'ordine di scrittura. Chi vi accede legge la
sequenza in cui le schede sono state deposte e, accostandola alle tracciature
della seduta, ricostruisce la corrispondenza fra votante e voto.

Tre precisazioni che cambiano la valutazione:

- **Non è una correlazione statistica**, è deterministica. Una prima stesura di
  questo documento la descriveva come probabilistica ed esercitabile solo a urna
  aperta: era una descrizione più mite del vero, e va corretta prima di
  fondarvi una valutazione.
- **Non si esaurisce con la chiusura dell'urna**: dura quanto i registri sono
  conservati.
- **Nessuna applicazione può eliminarla**, perché nasce sotto di essa.

Su hosting condiviso quei registri sono nella disponibilità del **fornitore
dell'infrastruttura**, non dell'istituzione. Il rischio è quindi organizzativo
e va trattato come tale: accertare presso il fornitore se i registri siano
attivi, per quanto siano conservati e chi vi acceda; richiamarne il divieto di
utilizzo nell'atto di nomina a responsabile; limitare e documentare gli accessi
amministrativi alla banca dati durante le sedute.

Le richieste da rivolgere al fornitore sono in
`richieste-al-fornitore-hosting.md`, punto 6.

## 5. Altri rischi e misure

| Rischio | Misura |
|---|---|
| Voto espresso da persona diversa dall'avente diritto | credenziali personali; livello di autenticazione configurabile fino a SPID/CIE; sessione unica per avente diritto |
| Voto multiplo | unicità imposta da vincolo di integrità della banca dati, non da controllo applicativo |
| Alterazione dell'esito dopo la chiusura | sigillo SHA-256 sull'insieme ordinato delle schede; riconteggio verificabile in qualunque momento |
| Alterazione del verbale | verbale sigillato non modificabile; doppia impronta su contenuto e documento |
| Rimozione o alterazione delle tracciature | catena di impronte per seduta, con verifica automatica |
| Deliberazione su quorum fittizio | decadenza della presenza quando il collegamento cessa; appello nominale disponibile al Presidente in qualunque momento. **Vedi il limite dichiarato sotto** |
| Voto raccolto dopo la chiusura della seduta | la seduta non si chiude finché una votazione è aperta o sospesa, e su una seduta chiusa nessuna scheda è accettata |
| Annotazione di dati particolari nella giustificazione dell'assenza | campo di testo libero: nessun controllo automatico è possibile. Misura organizzativa, vedi §3 |
| Conservazione eccessiva | rimozione automatica delle tracciature dopo il termine configurato, solo su sedute verbalizzate |
| Accesso non autorizzato ai verbali | archiviazione fra i file riservati; permessi distinti per consultazione ed esportazione |

### Un limite da dichiarare: la presenza attesta il collegamento, non l'attenzione

Il dispositivo segnala la propria presenza finché la pagina dell'aula resta
aperta, **senza che l'avente diritto debba fare alcunché**. È una scelta
deliberata: chi ascolta una relazione di venti minuti non deve dimostrare di
esserci.

Ne segue che un collegamento lasciato aperto da chi si è allontanato continua a
concorrere al numero legale. Il sistema non distingue — e non può distinguere —
la presenza dall'attenzione.

Il presidio è procedurale e sta in mano al Presidente, che dispone
dell'**appello nominale** in qualunque momento della seduta. Il Regolamento
d'istituto può prevederne l'obbligatorietà prima delle votazioni su punti di
particolare rilievo.

Va dichiarato, non taciuto: una valutazione che attribuisse al sistema una
verifica dell'attenzione che esso non compie descriverebbe un presidio
inesistente.

## 6. Chi accede ai verbali, e per quale titolo

La consultazione non dipende dal permesso posseduto ma dall'**appartenenza
all'organo** che ha prodotto l'atto. Vi accede chi, su quella determinata
seduta, figura fra gli aventi diritto, ovvero l'ha presieduta, verbalizzata o
convocata. Chi non ricorre in alcuno di questi titoli non vi accede, quale che
sia il suo permesso.

È il presidio che risponde alla domanda che il DPO farà per prima: **un docente
non apre il verbale del Consiglio di una classe che non è la sua, né quello del
gruppo di lavoro operativo di un alunno di cui non fa parte.** Quel verbale
riferisce della disabilità di un minore identificato, e la restrizione non è
una cortesia organizzativa: è ciò che circoscrive dati dell'art. 9 a chi ha
titolo per conoscerli.

Fanno eccezione il dirigente scolastico e il personale di segreteria da lui
individuato, ai quali è attribuito un permesso distinto e riservato che
consente la consultazione di ogni organo.

### I titoli sono ancorati alla seduta, non al ruolo in essere

Chi ha presieduto, verbalizzato o convocato resta scritto su quella seduta.
Continua ad accedervi anche quando cessa dall'incarico, e non acquista nulla
su ciò che non lo riguarda:

| Chi cambia | Che cosa conserva | Che cosa perde |
|---|---|---|
| Coordinatore che non è più tale | le proprie sedute passate e i relativi verbali | la facoltà di convocarne di nuove |
| Dirigente uscente | le sedute che ha presieduto o convocato | la consultazione generale di ogni organo |
| Docente trasferito ad altra classe | i Consigli della classe di cui faceva parte | quelli della classe nuova, finché non è iscritto in elenco |
| Docente arrivato quest'anno | — | i Collegi degli anni precedenti, ai quali non era chiamato |

È la scelta che consente all'archivio di reggere il ricambio annuale degli
incarichi senza che nessuno perda accesso a ciò che ha compiuto.

> **Il titolo di convocante è perpetuo.** Chi ha convocato una seduta ne
> conserva l'accesso senza limiti di tempo, anche se di quell'organo non ha mai
> fatto parte. È il titolo più debole dei quattro, ed è una scelta consapevole:
> nella pratica chi convoca un gruppo di lavoro operativo ne è componente, e la
> perdita silenziosa dell'accesso ai propri atti sarebbe un danno maggiore del
> rischio che si eviterebbe. Va dichiarata al DPO, non taciuta.

## 7. Diritti degli interessati

**Accesso e rettifica** sono esercitabili sui dati identificativi e sul registro
presenze.

**Non sono esercitabili sul voto a scrutinio segreto**, e non per una scelta del
titolare: il dato che consentirebbe di individuare il voto di una persona
determinata non esiste. Il titolare non è in grado di identificare
l'interessato in relazione a quel dato, e ricorre l'art. 11 GDPR.

**Cancellazione e limitazione** sono compresse dalla natura di atto
amministrativo del verbale e dai termini di conservazione degli atti degli
organi collegiali.

## 8. Misure che restano a carico dell'istituzione

Il modulo non le attua e non può attuarle. Vanno documentate nella valutazione
d'impatto come misure del titolare.

- **Trasporto cifrato obbligatorio.** Non basta che HTTPS sia disponibile: il
  sito va reso raggiungibile *solo* in HTTPS, con reindirizzamento permanente
  da HTTP e intestazione HSTS. Finché resta raggiungibile in chiaro, il cookie
  di sessione può viaggiare in chiaro e l'identificazione dell'avente diritto
  è aggirabile
- **Cifratura dei supporti di archiviazione.** Su hosting condiviso non è
  attuabile né verificabile dal cliente: va richiesta al fornitore in sede di
  nomina a responsabile del trattamento ai sensi dell'art. 28 GDPR, allegando
  quanto il fornitore dichiara, e il residuo va registrato fra i rischi
  accettati. Le richieste da rivolgere al fornitore, con il modello di
  lettera, sono in `richieste-al-fornitore-hosting.md`
- **Cifratura delle copie di sicurezza.** È invece sempre attuabile da chi le
  produce, ed è il punto in cui il rischio è concreto: una copia della banca
  dati contiene il registro dei voti palesi e i nominativi di tutti i presenti
- **Applicazione tempestiva degli aggiornamenti di sicurezza.** L'istituzione
  ne resta titolare, ma l'esecuzione spetta di norma a chi ha in carico la
  manutenzione del sito: va assegnata per iscritto nel contratto, con la
  periodicità dei controlli e il tempo massimo di intervento sugli avvisi di
  sicurezza. L'attestazione riferisce quel che osserva — se il sito sappia
  riconoscere gli aggiornamenti, quando ha controllato l'ultima volta e se ne
  risultino di non applicati — ma non può attestare per il futuro un'attività
  continuativa
- Segregazione degli ambienti di sviluppo, prova ed esercizio, con divieto di
  popolare gli ambienti non di esercizio con dati reali di seduta
- Copie di sicurezza verificate e prova periodica del ripristino
- Procedura di gestione degli incidenti di sicurezza
- Disciplina degli accessi amministrativi alla banca dati, con particolare
  riguardo alle finestre temporali in cui le urne sono aperte
- Istruzioni agli incaricati e informativa agli aventi diritto
- Valutazione separata dello strumento di videoconferenza adottato

## 9. Verifica

L'attestazione di conformità prodotta dalla configurazione in essere è
consultabile in `/admin/reports/psiphos/conformita` ed esportabile. Riporta,
requisito per requisito, che cosa è attuato dal modulo e che cosa resta a
carico dell'istituzione. Va riprodotta e allegata dopo ogni modifica della
configurazione.

## 10. Che cosa allegare alla valutazione

La valutazione d'impatto non vive da sola: è il documento che tiene insieme gli
altri, e ciascuno di essi ne è un presupposto.

| Allegato | Da chi | Perché serve alla valutazione |
|---|---|---|
| Attestazione di conformità della configurazione | prodotta dal sistema | è la descrizione dello stato di fatto su cui la valutazione si fonda |
| Dichiarazione di conformità del fornitore del modulo | fornitore del software | attesta le caratteristiche tecniche e dichiara il rischio residuo (§9) |
| **Descrizione tecnica del trattamento** (modulo precompilato) | prodotta dal sistema, a firma del fornitore del software | fornisce la descrizione tecnica nella configurazione in essere (art. 28, § 3, lett. f) |
| Atto di nomina del fornitore dell'hosting e sue dichiarazioni | fornitore dell'infrastruttura | senza, il rischio infrastrutturale resta indeterminato |
| Atto di nomina del manutentore del sito | manutentore | delimita chi accede ai dati e con quali obblighi |
| Articolo del Regolamento d'istituto | Consiglio d'istituto | è il presupposto di legittimità del trattamento |
| Scheda del registro delle attività di trattamento | titolare | è la registrazione del trattamento valutato: una valutazione su un trattamento non iscritto a registro è priva di oggetto formale |
| Parere del Responsabile della protezione dei dati | DPO | obbligatorio ai sensi dell'art. 35, § 2 |

**Un allegato mancante non è una formalità omessa.** Senza le dichiarazioni del
fornitore dell'hosting, per esempio, il rischio residuo sul voto segreto non è
valutabile: non se ne conosce l'estensione, e una valutazione che lo desse per
contenuto senza saperlo non sarebbe una valutazione.
