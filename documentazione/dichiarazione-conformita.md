# Dichiarazione di conformità e documentazione tecnica

**Attua**: allegato tecnico alla nota MIM prot. 3803 del 30/06/2026, §9 —
*«Le istituzioni scolastiche sono tenute a verificare preventivamente la
coerenza delle soluzioni adottate ai requisiti del presente documento,
acquisendo idonea documentazione tecnica attestante le caratteristiche del
sistema utilizzato, nonché una dichiarazione di conformità da parte del
fornitore o partner tecnologico.»*

---

## Premessa sulla natura della soluzione

Psíphos è un **modulo software installato sull'infrastruttura dell'istituzione
scolastica**, non un servizio erogato da terzi. La distinzione ha conseguenze
concrete sui requisiti dell'allegato:

- non vi è fornitore esterno che tratti dati di seduta per conto della scuola,
  e non ricorre quindi la nomina a responsabile del trattamento ex art. 28 GDPR
  per le operazioni di voto (§6);
- non si pone la verifica della localizzazione dei dati né delle misure di
  sicurezza dichiarate da un fornitore cloud (§5), perché i dati restano nel
  sistema informativo dell'istituzione;
- per converso, **le misure infrastrutturali del §5 ricadono interamente
  sull'istituzione**, che non può demandarle a nessuno.

Il modulo **non eroga la videoconferenza** e non intende sostituirla. Il §1
dell'allegato ammette espressamente che le istituzioni utilizzino strumenti di
uso corrente «anche mediante integrazione di più strumenti o procedure
organizzative, purché tali soluzioni risultino conformi ai requisiti definiti
nel presente documento». L'audio-video resta allo strumento in uso; Psíphos
copre le operazioni di voto, la verbalizzazione e le evidenze.

Ne consegue che **lo strumento di videoconferenza adottato va valutato
separatamente**, e che per esso restano necessarie la nomina a responsabile del
trattamento e la verifica della localizzazione dei dati.

## Attestazione della singola installazione

Questa documentazione descrive il modulo. Lo **stato effettivo di attuazione
dei requisiti nella specifica installazione** è prodotto dalla configurazione
in essere ed è consultabile in:

```
/admin/reports/psiphos/conformita
```

È esportabile in formato strutturato e **va riprodotto e allegato agli atti
dopo ogni modifica della configurazione**. Una dichiarazione generica non
assolve l'obbligo del §9: attesterebbe ciò che il modulo può fare, non ciò che
questa scuola fa davvero.

Il rapporto sullo stato del sito (`/admin/reports/status`) riporta inoltre in
via continuativa cinque presidi: livello di autenticazione, gestione delle
sessioni, formato di conservazione, archiviazione riservata dei verbali e
integrità delle catene di tracciature.

---

## Architettura, per requisito

### §3.1 e §3.2 — Identificazione e autenticazione

L'elenco degli aventi diritto associa ciascuna posizione a un'utenza nominativa
del sito. Non sono ammessi accessi anonimi, generici o condivisi in aula.

Il livello di autenticazione è configurabile su tre gradi — account personale,
account con secondo fattore, autenticazione forte tramite SPID, CIE, OpenID
Connect o SAML.

**L'impostazione dichiara il livello, non lo eroga.** Drupal da solo non ha un
secondo fattore: quello arriva da un modulo, e senza quel modulo l'impostazione
resta un'intenzione. L'attestazione riscontra perciò quel che risulta
installato e attivo, e segnala come rilievo il livello dichiarato ma non
erogato: dichiarare un secondo fattore che nessuno eroga è la sola condizione
peggiore del non averlo, perché induce a ritenere protetto ciò che non lo è.

Nemmeno il riscontro basta a sé: che un modulo di secondo fattore sia attivo
non implica che sia obbligatorio per tutti gli aventi diritto. Va imposto nella
configurazione del modulo stesso e verificato prima della prima seduta.

Il livello predefinito è quello con secondo fattore, che è la lettura più
difendibile del «privilegiando ove possibile modalità di autenticazione forte»
del §3.2. Il livello minimo, se adottato, va motivato nel Regolamento
d'istituto rispetto al livello di rischio.

### §3.3 — Ruoli

Sette permessi distinti: convocare, presiedere, verbalizzare, partecipare,
consultare i verbali, esportare gli esiti, consultare le tracciature.

Il ruolo è verificato **sulla singola seduta**: il permesso di presiedere
consente di presiedere la seduta in cui si è designati presidente, non
qualunque seduta. Lo stesso vale per la verbalizzazione.

### §3.4 — Sessioni

La presenza si mantiene **finché il collegamento all'aula resta attivo**, e non
richiede alcuna interazione: la pagina interroga lo stato della seduta ogni
cinque secondi — venti quando la scheda non è in primo piano — e ogni
interrogazione rinnova la presenza. Chi ascolta una relazione di venti minuti
senza toccare nulla resta presente a ogni effetto.

Trascorso l'intervallo configurato senza che alcun segnale pervenga — pagina
chiusa, dispositivo spento, rete caduta — la presenza decade e non concorre più
al numero legale.

**Ne segue un limite che va dichiarato**: un collegamento lasciato aperto da chi
si è allontanato continua a concorrere al quorum. Il sistema attesta il
collegamento, non l'attenzione, e non è in grado di distinguerli. Il presidio è
procedurale e sta in mano al Presidente, che dispone dell'appello nominale in
qualunque momento della seduta.

La sessione esclusiva opera per sostituzione: l'ingresso da un nuovo
dispositivo revoca l'accreditamento del precedente, che resta autenticato ma
non abilitato al voto. Della sessione si conserva l'impronta calcolata con
chiave, mai l'identificativo.

### §4.1 — Requisiti generali del voto

L'unicità del voto è imposta da una **chiave primaria composta** su
(votazione, avente diritto): il secondo voto viola un vincolo di integrità
della banca dati, non un controllo applicativo aggirabile.

Tipo di voto, struttura della scheda, opzioni, numero di preferenze e regola di
maggioranza si bloccano all'apertura dell'urna. Il denominatore dei quorum è
congelato due volte: all'apertura della seduta e all'apertura di ciascuna urna,
perché un ingresso in aula a votazione in corso non sposti la soglia mentre si
vota.

Lo scrutinio si arresta se il numero delle schede non coincide con quello dei
votanti: sono conteggi su tabelle diverse alimentate nella stessa transazione,
e una divergenza significa manomissione.

### §4.2 — Voto palese

Archivio dedicato che conserva il voto unitamente al nominativo. Il verbale
riporta per esteso chi ha votato e come.

### §4.3 — Voto a scrutinio segreto

Due archivi che non condividono alcuna colonna oltre al riferimento alla
votazione:

- **attestazioni**: chi ha votato, con il momento della partecipazione;
- **urna**: che cosa è stato votato, senza alcun riferimento al votante e
  **senza alcuna marca temporale**.

Entrambi sono **tabelle semplici e non entità del CMS**, deliberatamente:
un'entità porta con sé identificativo universale, marche di creazione e
modifica, metadati di revisione e una catena di hook che altri componenti
possono intercettare per registrare altrove. Il modo più solido per garantire
che nemmeno l'amministratore di sistema risalga al voto è non scrivere il dato
che glielo permetterebbe.

Misure contro la re-identificazione tramite metadati:

1. **Nessuna marca temporale sulla scheda.** Senza ora di deposito, l'ora di
   attestazione del votante non ha nulla con cui essere accostata.
2. **Identificativo di scheda casuale a 62 bit come chiave primaria.** Il
   motore di banca dati ordina fisicamente le righe per chiave primaria: con
   una chiave casuale l'ordine di memorizzazione non conserva quello di
   deposito.
3. **Preferenze in forma canonica ordinata.** Chi spunta due opzioni in ordine
   diverso produce righe identiche: l'ordine di spunta non viene conservato e
   schede uguali restano indistinguibili.
4. **Nessuna bozza di scheda** in sessione, cache o archivi temporanei. La
   scelta esiste solo nella richiesta che la deposita.

**Verificabilità dell'esito senza compromettere la segretezza.** Il sigillo
dell'urna è uno SHA-256 calcolato sull'insieme **ordinato alfabeticamente**
delle schede. La scelta usuale sarebbe una catena di impronte, ma una catena
impone un ordine, e l'ordine è esattamente il metadato che il §4.3 vieta di
conservare. Il sigillo sull'insieme ordinato ottiene lo stesso risultato —
rileva qualsiasi scheda aggiunta, rimossa o alterata — senza introdurre alcuna
sequenza. Il riconteggio è esercitabile in qualunque momento dopo la chiusura.

**Limite dichiarato.** Le misure sopra impediscono la correlazione a chi guardi
le tabelle: non esiste alcun campo, alcuna marca temporale e alcun ordine che
colleghi la scheda al votante.

Resta esposto chi disponga dei **registri del motore di banca dati** — i binary
log, i redo log, i registri delle interrogazioni — che stanno sotto
l'applicazione e ne conservano l'ordine di scrittura: chi vi accede legge la
sequenza in cui le schede sono state deposte e, accostandola alle tracciature,
ricostruisce la corrispondenza fra votante e voto. Non è una correlazione
statistica ma deterministica, non si esaurisce con la chiusura dell'urna e dura
quanto i registri sono conservati.

Nessuna applicazione può eliminarla, perché nasce sotto di essa. Su
infrastruttura condivisa quei registri sono nella disponibilità del fornitore
dell'hosting: la contromisura è organizzativa — accertarne l'esistenza e la
conservazione, limitare e documentare gli accessi, richiamarne il divieto
nell'atto di nomina — e va dichiarata nella valutazione d'impatto.

### §5 — Sicurezza informatica

Attuati dal modulo: registrazione degli eventi del procedimento in un registro
concatenato per seduta, con verifica automatica dell'integrità; nessun segreto
conservato in chiaro.

**A carico dell'istituzione**: cifratura del canale e dei supporti,
aggiornamenti di sicurezza, segregazione degli ambienti, copie di sicurezza e
prova del ripristino, procedura di gestione degli incidenti.

Sugli aggiornamenti l'attestazione riferisce se il sito sia in grado di
riconoscere le vulnerabilità note, quando abbia controllato l'ultima volta e se
risultino componenti con avvisi di sicurezza non applicati. Non può attestare
per il futuro un'attività continuativa: l'esecuzione spetta di norma a chi ha
in carico la manutenzione del sito e va assegnata per iscritto nel contratto,
con periodicità e tempi di intervento.

Sulla cifratura l'attestazione riferisce ciò che osserva: se la pagina è servita
in HTTPS e se il cookie di sessione è ristretto alle sole connessioni cifrate.
Restano fuori dall'osservazione la cifratura dei supporti — su hosting condiviso
non verificabile dal cliente, e da richiedere al fornitore in sede di nomina a
responsabile del trattamento — e quella delle copie di sicurezza, che è invece
sempre attuabile da chi le produce.

### §6 — Protezione dei dati personali

L'urna non contiene dati personali. Le tracciature sono rimosse dopo il termine
configurato — dieci anni per impostazione predefinita — e solo su sedute già
verbalizzate, dove il verbale sigillato resta come evidenza documentale.

Elementi tecnici per la valutazione d'impatto: `dpia-elementi.md`.

### §7 — Documenti e conservazione

**L'esportazione è conservata, non rigenerata.** Al sigillo la struttura della
seduta è serializzata una volta sola e i byte restano sul verbale: l'impronta
è calcolata su quei byte e il documento PDF è generato dagli stessi. Documento
ed esportazione non possono divergere, e l'impronta resta ripetibile nel tempo
indipendentemente da modifiche successive al codice, alle traduzioni o ai dati
di origine — condizione necessaria perché il §7 chiede autenticità e integrità
**nel tempo**.

Il verbale sigillato non è modificabile in alcuna parte, nemmeno da un
amministratore: la correzione avviene con verbale di rettifica, che lascia
traccia di entrambi. Due impronte SHA-256:

- **del contenuto**, sull'esportazione conservata, ricalcolabile da chiunque
  con un qualunque strumento, **anche fuori dal sito**;
- **del documento**, sul file consegnato alla conservazione.

La pagina di verifica tiene distinta l'integrità dei documenti conservati
dalla loro corrispondenza con la banca dati. Una divergenza della seconda non
costituisce di per sé indizio di manomissione: può derivare da correzioni
legittime dei dati di origine, che il documento sigillato non recepisce. La
distinzione evita che rilievi privi di rilevanza si presentino come violazioni
di integrità.

Il documento è prodotto in **PDF/A-2B** mediante conversione con Ghostscript.
Se lo strumento di conversione non è disponibile sul server, il verbale è
sigillato in PDF ordinario e **il formato effettivo è registrato sul verbale**:
un documento non conforme al formato di conservazione va trattato diversamente
dal conservatore, e la circostanza deve risultare agli atti.

**Estratti di delibera.** Ogni votazione conclusa produce, oltre al verbale,
un proprio documento nella forma degli atti collegiali — numero, oggetto,
denominazione dell'organo, premesse, dispositivo — perché è nella forma di
atto autonomo che la delibera viene protocollata, pubblicata in
Amministrazione Trasparente e trasmessa agli uffici. La proclamazione
dell'esito e il prospetto della votazione che chiudono l'atto sono composti
dal sistema leggendo l'urna e non trascritti a mano.

Verbale ed estratti si sigillano **nello stesso atto**: sono lo stesso momento
di chiusura, e un atto incompleto impedisce il sigillo del verbale. Ciascun
estratto conserva la propria esportazione e porta due impronte proprie — su di
essa e sul file — così che
chi lo riceve possa verificarlo senza disporre del verbale, e dichiara al
tempo stesso l'identificativo del verbale da cui è tratto, la sua impronta e
il sigillo dell'urna da cui l'esito è uscito.

Le votazioni annullate non producono estratto: restano documentate nel verbale,
ma il §8 le vuole prive di effetti e non sono atti da far circolare.

**Produrre un documento nel formato giusto non è conservarlo.** La
conservazione a norma è un processo distinto, con un responsabile della
conservazione, un manuale e un pacchetto di versamento verso un conservatore
accreditato. Il modulo produce documenti conservabili e le evidenze che ne
attestano autenticità e integrità; non li conserva. Nel frattempo i documenti
restano fra i file riservati del sito, che non è un sistema di conservazione.

I documenti portano i metadati previsti dalle Linee guida AgID — identificativo,
tipologia documentale, data di chiusura, modalità di formazione, oggetto,
soggetto produttore — oltre alle impronte e ai riferimenti normativo e
regolamentare che li contestualizzano. La corrispondenza fra questi metadati e
quelli richiesti dal sistema di conservazione prescelto va concordata con il
conservatore **prima del primo versamento**: l'insieme minimo è normativo, la
sua rappresentazione dipende dalle specifiche del conservatore.

### §8 — Requisiti organizzativi

Presidente e segretario verbalizzante sono designati sulla convocazione e le
rispettive funzioni sono esercitabili solo da chi vi è designato.

Sospensione e annullamento di una votazione richiedono una motivazione scritta
e sono tracciati. La ripetizione **apre una nuova votazione** che conserva il
riferimento a quella annullata: nessun esito registrato viene mai riscritto.
La seduta non può essere chiusa finché una votazione è aperta o sospesa.

Ogni seduta richiede l'indicazione dell'articolo del Regolamento d'istituto che
la legittima, riportato nel verbale. **L'adozione del Regolamento è
dell'istituzione**: bozza di articolo in `regolamento-articolo.md`.

### §2 — Trasparenza e verificabilità

Il §2 chiede due cose distinte, ed entrambe sono prodotte:

- **evidenze documentali**: il verbale sigillato con la sua esportazione
  strutturata, che documenta l'**esito**;
- **tracciature tecniche**: il registro concatenato degli eventi, che documenta
  il **procedimento** — chi è entrato quando, chi ha aperto la votazione, chi
  l'ha sospesa e perché, quali depositi sono stati rifiutati.

Le tracciature sono agganciate al salvataggio delle entità e non ai comandi
dell'interfaccia: qualunque strada porti al cambio di stato, l'annotazione
viene scritta. Nessuna annotazione riporta il contenuto di un voto segreto.

---

## Requisiti tecnici di esercizio

| Componente | Requisito |
|---|---|
| Drupal | 10.3 o superiore, oppure 11 |
| PHP | 8.3 o superiore |
| File system riservato | **obbligatorio** — i verbali contengono nominativi, presenze e voti palesi |
| Ghostscript | necessario per il formato di conservazione PDF/A-2B |
| Cron | necessario per la rimozione delle tracciature scadute |
| HTTPS | **obbligatorio** in esercizio |

## Verifica funzionale

Il modulo è corredato di suite di verifica ripetibili, eseguibili
sull'installazione:

```bash
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_modello.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_urna.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_aula.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_verbale.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_tracciature.php
```

Verificano fra l'altro, in forma di asserzione e non di dichiarazione: che
l'urna non contenga colonne riferite a un utente né marche temporali; che gli
identificativi di scheda non siano progressivi; che le tracciature non
contengano voci di scheda; che una scheda aggiunta dopo la chiusura rompa il
sigillo; che un verbale sigillato non sia salvabile; che una tracciatura
alterata o rimossa rompa la catena.

---

## Dichiarazione

*Fornitore dichiarante: [denominazione] — P. IVA / C.F. [numero] — [recapito].*

Il §9 chiede all'istituzione di acquisire «una dichiarazione di conformità da
parte del fornitore o partner tecnologico»: una dichiarazione che non
identifichi chi la rende non assolve quell'obbligo. I dati del fornitore si
indicano in `/admin/config/psiphos` e compaiono nel documento da sottoscrivere.

Il sottoscritto, in qualità di *[qualifica]* dell'operatore che ha realizzato e
messo a disposizione il modulo Psíphos,

**dichiara** che il modulo, nella versione installata presso
*[denominazione dell'istituzione]* e nella configurazione documentata
dall'attestazione allegata, presenta le caratteristiche tecniche descritte nel
presente documento e attua i requisiti dell'allegato tecnico alla nota MIM
prot. 3803 del 30/06/2026 per le parti ivi indicate come a proprio carico;

**dà atto** che i requisiti indicati come a carico dell'istituzione scolastica —
cifratura, aggiornamenti, segregazione degli ambienti, copie di sicurezza,
gestione degli incidenti, valutazione d'impatto e adozione del Regolamento
d'istituto — **non sono attuati dal modulo** e restano di competenza del
titolare del trattamento;

**dà atto** del limite dichiarato al §4.3: il voto a scrutinio segreto conserva
un margine residuo per chi disponga dei **registri del motore di banca dati** —
non del solo accesso alle tabelle — potendo leggervi l'ordine di scrittura delle
schede e accostarlo alle tracciature. Il margine non è eliminabile da
un'applicazione, perché nasce sotto di essa; su infrastruttura condivisa quei
registri sono nella disponibilità del fornitore dell'hosting, e il contenimento
è affidato a misure organizzative e all'atto di nomina di quest'ultimo.

Luogo e data ____________________

Firma ____________________

*Allegato: attestazione di conformità della configurazione, esportata da
`/admin/reports/psiphos/conformita/esporta`.*

**La verifica del §9 è preventiva.** Entrambe le firme — quella del fornitore e
la presa d'atto del dirigente — vanno datate e il documento protocollato
**prima della prima seduta deliberativa**: una verifica successiva al proprio
oggetto non è preventiva, e la deliberazione svolta senza di essa resta
esposta a impugnazione per quel solo motivo.

L'attestazione riferisce la configurazione **in essere al momento in cui è
prodotta**. Va riprodotta e riacquisita dopo ogni modifica delle impostazioni
del modulo: livello di autenticazione, gestione delle sessioni, formato di
conservazione, ritenzione delle tracciature.
