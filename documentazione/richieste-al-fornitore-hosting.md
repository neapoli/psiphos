# Che cosa la scuola deve chiedere al fornitore dell'hosting

Documento operativo per l'adozione di Psíphos. Riguarda il **fornitore
dell'infrastruttura** — Serverplan, VHosting o altri — e va completato **prima
della prima seduta deliberativa a distanza**.

## Perché serve

L'allegato tecnico alla nota MIM prot. 3803 del 30/06/2026 chiede due cose che
la scuola non può ricavare da sé:

- **§5** — «Nel caso di utilizzo di piattaforme cloud o servizi di terze parti,
  le istituzioni scolastiche devono verificare: la localizzazione dei dati e le
  modalità di trattamento; le misure di sicurezza dichiarate dal fornitore; la
  conformità alle normative europee e nazionali.»
- **§6** — «Qualora il servizio sia erogato da un fornitore esterno che tratta
  dati personali per conto dell'istituzione scolastica, deve essere formalmente
  nominato responsabile del trattamento ai sensi dell'art. 28 del GDPR.»

Il sito della scuola risiede su infrastruttura di un fornitore. Che i dati non
lascino il sito **non significa che restino in casa dell'istituzione**: banca
dati e file riservati stanno su macchine di qualcun altro, e quel qualcuno
tratta dati personali per conto della scuola.

Lo stesso allegato precisa che «i requisiti si considerano soddisfatti quando le
misure sopra indicate risultano **dichiarate dal fornitore**». Non serve quindi
che la scuola verifichi tecnicamente: serve che **acquisisca per iscritto** le
dichiarazioni e le conservi agli atti.

## Le otto richieste

### 1. Atto di nomina a responsabile del trattamento (art. 28 GDPR)

**Che cosa chiedere.** L'atto di nomina, o il modello di accordo sul
trattamento dei dati che il fornitore adotta, sottoscritto da entrambe le parti.

**Perché.** È l'unico documento che **definisce e delimita** gli obblighi del
fornitore. In sua assenza restano indeterminati, e davanti a un incidente la
scuola non ha nulla su cui far valere alcunché.

**Che cosa deve contenere** (art. 28, paragrafo 3): oggetto e durata del
trattamento, natura e finalità, tipi di dati e categorie di interessati,
obblighi e diritti del titolare, obbligo di riservatezza degli incaricati,
misure di sicurezza, assistenza al titolare, cancellazione o restituzione dei
dati al termine, disponibilità a controlli e audit.

**Attenzione.** Quasi tutti i fornitori hanno un modello già pronto. Va letto:
un modello che ometta l'obbligo di notifica degli incidenti o i termini di
cancellazione non assolve l'obbligo solo perché si intitola «art. 28».

**Se il fornitore non ne ha uno.** L'atto è precompilato dal sito: è il secondo
modulo di questo documento, «Atto di nomina del fornitore dell'infrastruttura».
Riporta l'istituto come titolare, il fornitore come responsabile — se indicato
in `/admin/config/psiphos` — e contiene un articolo che i modelli standard non
hanno: le **istruzioni sui registri del motore di banca dati**, che sono il
punto da cui dipende la segretezza del voto. Se il fornitore propone il proprio
modello, si sottoscrive quello, ma vale la pena confrontarne gli articoli su
registri, ubicazione dei dati e notifica degli incidenti.

### 2. Localizzazione fisica dei dati

**Che cosa chiedere.** In quale Paese risiedono fisicamente il server, la banca
dati e le copie di sicurezza. Se vi siano trasferimenti verso Paesi terzi e, in
caso affermativo, su quale base giuridica.

**Perché.** È la prima delle tre verifiche del §5. Serve anche alla valutazione
d'impatto.

**Che cosa aspettarsi.** I fornitori italiani rispondono di norma «datacenter in
Italia» o «nell'Unione europea». Va comunque ottenuto **per iscritto**: una
convinzione non è una verifica, e l'ubicazione delle *copie di sicurezza* è
spesso diversa da quella del server.

### 3. Sub-responsabili

**Che cosa chiedere.** L'elenco dei soggetti terzi di cui il fornitore si
avvale per erogare il servizio: gestore del datacenter, servizio di backup
esterno, assistenza tecnica in outsourcing.

**Perché.** L'art. 28, paragrafo 2, richiede l'autorizzazione del titolare al
ricorso a sub-responsabili. Una catena di fornitori non dichiarata è una catena
di accessi non autorizzati.

**Come formularla.** Va chiesta la dichiarazione **in entrambi i sensi**: o
l'elenco, o l'attestazione che non ve ne sono. Una domanda posta come «se
presenti» rende accettabile il silenzio, e il silenzio è precisamente ciò che
non serve agli atti: non distingue il fornitore che non ne ha da quello che non
ha risposto. In pratica quasi ogni hosting si avvale di terzi — gestore del
datacenter, servizio di copia, assistenza — e una risposta negativa merita di
essere messa per iscritto tanto quanto un elenco.

### 4. Cifratura dei dati a riposo

**Che cosa chiedere.** Se banca dati, filesystem e copie di sicurezza siano
cifrati a riposo, e con quali modalità.

**Perché.** È la prima misura del §5.

**Che cosa aspettarsi — e come comportarsi.** Su hosting condiviso la risposta è
spesso negativa o generica, e **non è attuabile né verificabile dal cliente**.
Non è una mancanza della scuola. La condotta corretta è: acquisire la risposta
quale che sia, e **registrare il residuo fra i rischi accettati** nella
valutazione d'impatto. Dichiarare una cifratura che non c'è è l'unica condotta
che espone davvero.

### 5. Copie di sicurezza e ripristino

**Che cosa chiedere.** Frequenza delle copie, periodo di conservazione, dove
sono conservate, se sono cifrate, con quale procedura e in quanto tempo il
fornitore ripristina su richiesta, e se il ripristino venga provato
periodicamente.

**Perché.** Il §5 richiede «misure di continuità operativa, inclusi sistemi di
backup e procedure di ripristino».

**Un dettaglio che riguarda Psíphos.** I verbali sigillati e gli estratti di
delibera **non stanno nella banca dati**: sono file nella cartella riservata del
sito. Una copia che comprenda la sola banca dati non consentirebbe di
ripristinare gli atti. Va chiesto espressamente che la copia comprenda
**banca dati e file**.

### 6. Registri del motore di banca dati

**Che cosa chiedere.** Se siano attivi i registri delle transazioni del motore
di banca dati (*binary log*, *redo log*, *general query log*), per quanto tempo
siano conservati, chi vi abbia accesso, e se sia possibile disattivarli o
ridurne la conservazione per il dominio della scuola.

**Perché.** È la richiesta più specifica di questo elenco, e vale la pena capire
a che cosa serve.

Psíphos tiene il voto segreto separato dall'identità del votante: la scheda e
l'attestazione di partecipazione stanno in due tabelle senza colonne comuni,
nessuna delle due porta marche temporali, e la scheda ha una chiave casuale che
non conserva l'ordine di deposito. **Chi guardi le tabelle non può risalire a
chi ha votato che cosa.**

Chi disponga dei **registri del motore**, però, vi legge l'ordine in cui le
schede sono state scritte, e accostandolo alle tracciature della seduta
ricostruisce la corrispondenza. È un margine che nessuna applicazione può
eliminare, perché nasce sotto di essa — ed è dichiarato nell'attestazione di
conformità e nella dichiarazione del fornitore del modulo.

Su hosting condiviso **quei registri sono nella disponibilità del fornitore
dell'hosting**. Contenerlo è quindi una misura organizzativa: sapere se
esistono, chi vi accede e per quanto restano.

**Che cosa aspettarsi.** Molti fornitori non sapranno rispondere subito.
Insistere: la risposta, anche negativa o parziale, va agli atti.

### 7. Gestione e notifica degli incidenti

**Che cosa chiedere.** Con quale procedura e **entro quanto tempo** il fornitore
notifica alla scuola un incidente di sicurezza che riguardi i suoi dati, e a
quale recapito.

**Perché.** Il termine di 72 ore dell'art. 33 GDPR decorre da quando il
**titolare** viene a conoscenza dell'incidente. Se il fornitore notifica dopo
una settimana, la scuola è già fuori termine senza aver fatto nulla di male.

**Che cosa pretendere.** Un termine espresso in ore, non «tempestivamente».

### 8. Requisiti tecnici per il funzionamento

**Che cosa chiedere.** Che sia disponibile **Ghostscript** e che la funzione PHP
`proc_open` non sia disabilitata per il dominio.

**Perché.** Servono a produrre i verbali in **PDF/A**, il formato prescritto
dalle Linee guida AgID per la conservazione. Senza, il modulo sigilla ugualmente
in PDF ordinario e lo dichiara sul verbale — ma è un documento che il
conservatore tratterà diversamente.

**Che cosa aspettarsi.** `proc_open` è spesso disabilitata su hosting condiviso
per ragioni di sicurezza, e la direttiva `disable_functions` ha livello di
sistema: **non è modificabile da un `php.ini` dell'utente**. Va chiesto al
fornitore di intervenire sulla configurazione del pool PHP del dominio. Se non
lo concede, si procede in PDF ordinario dichiarandolo.

## Che cosa il fornitore dell'hosting NON è

**Non è un conservatore accreditato.** Le copie di sicurezza non sono
conservazione a norma: servono a ripristinare un sistema, non ad assicurare nel
tempo autenticità, integrità, leggibilità e reperibilità dei documenti. La
conservazione dei verbali è un processo distinto, con un responsabile della
conservazione, un manuale e un versamento verso un conservatore. Confondere le
due cose è l'errore più comune, e si scopre quando serve un verbale di dieci
anni prima.

## La richiesta da inviare

Questo documento produce **due moduli**, in
`/admin/reports/psiphos/documentazione`: la richiesta da spedire e l'atto di
nomina da sottoscrivere. Il primo è la lettera che segue.

Riporta la carta intestata dell'istituto — denominazione, indirizzo, codice
fiscale, codice meccanografico, posta ordinaria e PEC, letti dal luogo marcato
come sede legale — il dominio del sito e le otto richieste sopra illustrate.

Il **destinatario è compilato** quando il fornitore dell'infrastruttura è
indicato in `/admin/config/psiphos`. Restano in bianco soltanto **il termine
per il riscontro, il luogo, la data e la firma** del Dirigente scolastico.

### Che cosa registrare quando il riscontro arriva

Nelle impostazioni del modulo, sotto «Fornitore dell'infrastruttura», si
annotano **protocollo e data del riscontro** e **il Paese di ubicazione dei
dati dichiarato**. Non sono dati decorativi: da lì passano nel registro delle
attività di trattamento, nella descrizione tecnica per la valutazione d'impatto
e nell'attestazione di conformità, dove documentano che la verifica del §5 è
stata condotta.

Sono registrati **come dichiarazioni del fornitore, non come fatti verificati**,
e in questa forma compaiono: il sito non può osservare dove risiedano i dati,
può solo dare atto di che cosa gli è stato risposto e con quale atto.

### Due clausole di chiusura, e perché stanno nella lettera

La lettera chiede espressamente **un riscontro su ciascun punto, comprese le
risposte negative**, e **indica una data** entro cui riceverlo. Sono le due
clausole che trasformano una richiesta in un atto istruttorio.

Stanno nella lettera e non nella comunicazione di accompagnamento perché è la
lettera ad andare agli atti. Se la richiesta di completezza restasse nella sola
mail di trasmissione, e il fornitore rispondesse in modo parziale,
l'istituzione avrebbe agli atti una richiesta generica e una risposta generica:
nulla che mostri che si era chiesto di più.

**La data serve alla scuola, non è un ultimatum al fornitore.** Senza un
riferimento temporale, il silenzio è indistinguibile da una risposta in
lavorazione: «non ha ancora risposto» resta vero per mesi, il sollecito non ha
un punto di partenza, e nella valutazione d'impatto non si può scrivere che il
fornitore non ha dato conto delle misure — perché formalmente potrebbe ancora
farlo.

Per questo la lettera la indica in tono di richiesta e non di intimazione: dice
perché quella data serve — gli adempimenti preliminari alla prima seduta
deliberativa — e aggiunge che, se non è compatibile con i tempi del fornitore,
**basta che ne indichi un'altra**. Quella risposta è essa stessa un riscontro
utile: mette agli atti che la richiesta è stata presa in carico e sposta la data
del sollecito senza che nulla resti indeterminato.

Quindici giorni sono un intervallo consueto per un fornitore commerciale, ma
conviene calcolarli a ritroso dalla prima seduta deliberativa, non in avanti
dalla spedizione.

Il testo della lettera non è riprodotto qui di proposito: esisterebbe in due
copie, e due copie della stessa lettera divergono senza che nessuno se ne
accorga finché una scuola non ne spedisce una superata.

## Che cosa fare con le risposte

1. **Protocollarle** e conservarle insieme all'attestazione di conformità
   prodotta dal modulo e alla dichiarazione del fornitore del software.
2. **Sottoscrivere l'atto di nomina** prima della prima seduta deliberativa.
3. **Riportare nella valutazione d'impatto** ciò che il fornitore non garantisce
   — tipicamente la cifratura a riposo e l'accesso ai registri del motore —
   come rischio residuo accettato, con la motivazione dell'accettazione.
4. **Rileggerle a ogni cambio di fornitore o di piano di servizio.** Le
   dichiarazioni valgono per la configurazione a cui si riferiscono.

## Se il fornitore non risponde

Il silenzio non solleva il titolare. Se dopo due solleciti il fornitore non
fornisce la nomina ai sensi dell'art. 28, la scuola si trova a far trattare i
propri dati da un soggetto privo di istruzioni scritte: la circostanza va
verbalizzata, portata al Responsabile della protezione dei dati e considerata
nella scelta del fornitore.

---

*Documento a corredo del modulo Psíphos. Fa parte della documentazione tecnica
che il §9 dell'allegato chiede alle istituzioni scolastiche di acquisire.*
