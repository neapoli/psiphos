# Nomina del manutentore del sito a responsabile del trattamento

Documento operativo per l'adozione di Psíphos. Riguarda **chi ha in carico la
manutenzione del sito** — aggiornamenti di Drupal e dei moduli, installazione di
nuove funzionalità, assistenza — e va completato **prima della prima seduta
deliberativa a distanza**.

Il documento è redatto dal punto di vista del manutentore, perché è lui a
doverlo proporre: la scuola raramente lo chiede per prima, e la sua assenza
danneggia prima di tutto il manutentore.

## 1. Perché il manutentore è un responsabile del trattamento

Non dipende da che cosa il manutentore *fa*, ma da che cosa *può fare*.

Chi amministra un sito Drupal accede all'elenco completo delle utenze del
personale, alla banca dati, ai file riservati. Su un sito che ospita Psíphos ciò
comprende il registro delle presenze alle sedute, i **voti palesi con il
nominativo di chi li ha espressi** e i verbali sigillati.

Non serve che vi acceda mai: **la possibilità di accedervi basta**. Il GDPR
qualifica responsabile chi «tratta dati personali per conto del titolare», e
l'accesso amministrativo continuativo è trattamento.

Il §6 dell'allegato tecnico lo dice espressamente:

> «Qualora il servizio sia erogato da un fornitore esterno che tratta dati
> personali per conto dell'istituzione scolastica, deve essere formalmente
> nominato responsabile del trattamento ai sensi dell'art. 28 del GDPR.»

### Perché conviene al manutentore

L'atto di nomina è spesso letto come un adempimento a carico del fornitore. È
il contrario: **senza nomina gli obblighi del manutentore restano
indeterminati**.

Davanti a un incidente — un accesso non autorizzato, una perdita di dati, un
voto contestato — l'assenza di un atto che delimiti il perimetro lascia aperta
qualunque ricostruzione. Con l'atto, ciò che non vi è scritto non è dovuto.

## 2. Il perimetro: che cosa il manutentore fa e che cosa non fa

È la parte che va scritta con più cura, perché è quella che limita la
responsabilità. Un perimetro vago è peggio di un perimetro ristretto.

### Rientra nell'incarico

| Attività | Trattamento che comporta |
|---|---|
| Aggiornamento di Drupal, dei moduli e del tema | accesso amministrativo, potenziale accesso a tutti i dati |
| Installazione e configurazione di moduli richiesti dalla scuola | come sopra |
| Sviluppo di funzionalità su richiesta | come sopra |
| Assistenza tecnica e risoluzione di malfunzionamenti | consultazione di registri e dati per diagnosi |
| Copia dei dati in ambiente locale per prova e sviluppo | **duplicazione dell'intera banca dati presso la sede del manutentore** |

### Non rientra nell'incarico

Vanno escluse espressamente, perché il silenzio si presta a essere letto come
inclusione:

- **L'infrastruttura.** Server, rete, cifratura dei supporti, copie di sicurezza
  del fornitore di hosting: sono del fornitore dell'hosting, che ha una propria
  nomina.
- **La conservazione a norma.** Versamento dei verbali al conservatore
  accreditato, manuale di conservazione, responsabile della conservazione.
- **Le decisioni sul trattamento.** Chi sono gli aventi diritto, quali sedute si
  tengono, che cosa si vota, per quanto si conservano le tracciature: sono del
  titolare. Il manutentore configura ciò che il titolare decide.
- **I contenuti pubblicati** dalla scuola e i dati inseriti dal personale.
- **L'adozione del Regolamento d'istituto** e la valutazione d'impatto.

## 3. Le copie locali: il punto che si dimentica sempre

Chi mantiene un sito ne scarica quasi sempre una copia in locale, per provare un
aggiornamento prima di applicarlo in esercizio. È buona pratica, e Psíphos la
raccomanda.

Ma **quella copia contiene l'intera banca dati della scuola**, quindi anche i
voti palesi e i verbali. Si tratta di un trattamento presso la sede del
manutentore, e va disciplinato:

- va **dichiarato** nell'atto di nomina, non taciuto;
- la copia va **cifrata** quando è a riposo sul disco del manutentore;
- va **cancellata** quando non serve più, e comunque alla cessazione
  dell'incarico;
- l'ambiente locale **non va reso raggiungibile** dall'esterno;
- se possibile, i dati vanno **ridotti o resi fittizi** per le prove che non
  richiedono dati reali.

L'allegato tecnico lo richiama al §5 come «segregazione degli ambienti», con il
divieto di popolare gli ambienti non di esercizio con dati reali di seduta.
Dove il divieto non è praticabile — e per una verifica di aggiornamento spesso
non lo è — la circostanza va documentata e le copie trattate come i dati che
contengono.

## 4. Obblighi che ricadono sul manutentore

Sono obblighi propri, non della scuola. Vale la pena conoscerli prima di
sottoscrivere la nomina.

**Registro delle attività di trattamento (art. 30, paragrafo 2).** Il
responsabile tiene un proprio registro delle categorie di trattamento svolte per
conto di ciascun titolare. L'esonero previsto per chi ha meno di 250 dipendenti
**non si applica** quando il trattamento non è occasionale: la manutenzione
continuativa di un sito non lo è. Un registro semplice — un foglio per scuola,
con categorie di dati, finalità, misure di sicurezza, sub-responsabili — assolve
l'obbligo.

**Misure di sicurezza (art. 32).** Riguardano **l'ambiente del manutentore**,
non il sito: cifratura del disco della postazione da cui si amministra,
credenziali personali non condivise, secondo fattore sugli account e sui
pannelli che lo consentono, aggiornamento del proprio sistema.

**Il secondo fattore sul sito è un caso a parte, e va detto con precisione.**
Drupal non ha un secondo fattore nativo: lo eroga un modulo, che oggi quasi
nessuna scuola ha installato. Impegnarsi nell'atto ad accedere «con secondo
fattore» significherebbe promettere una misura che il sito non consente, cioè
sottoscrivere una dichiarazione non veritiera — lo stesso difetto che
l'attestazione di conformità segnala al §3.2 quando il livello dichiarato non
corrisponde a quello erogato.

L'atto formula quindi l'impegno per quello che è: il secondo fattore è adottato
**ove i sistemi lo consentano**, e dove non è disponibile il responsabile **ne
informa il titolare**, cui spetta disporne l'adozione. È una clausola che
protegge entrambi: il manutentore non promette l'impossibile, e l'istituzione
riceve per iscritto la segnalazione di una misura che le manca — che è poi la
condizione perché possa decidere di dotarsene.

**Notifica delle violazioni (art. 33, paragrafo 2).** Il responsabile informa il
titolare «senza ingiustificato ritardo» dopo esserne venuto a conoscenza.
Conviene impegnarsi a un termine espresso in ore: è la stessa cosa che si chiede
al fornitore dell'hosting, e le 72 ore del titolare decorrono da quando ne viene
a conoscenza.

### Riservatezza: chi è vincolato, e chi no

L'art. 28, paragrafo 3, lettera b), impone di garantire che «le persone
autorizzate al trattamento» si siano impegnate alla riservatezza. Riprodotta
senza contesto, la formula è ambigua: sembra riferirsi a chiunque possa
accedere al sito.

**Riguarda soltanto le persone che operano sotto l'autorità del manutentore**:
collaboratori, tirocinanti, professionisti incaricati, chiunque metta le mani
sul sito con le sue credenziali e su suo incarico.

**Non riguarda le persone che il dirigente autorizza nella propria
organizzazione.** Se il titolare conferisce privilegi amministrativi a un
proprio collaboratore, di quella persona risponde il titolare ai sensi
dell'art. 29 del Regolamento, non il manutentore. L'atto lo dice espressamente
all'art. 5, secondo capoverso: in un documento che serve a ripartire
responsabilità, ciò che non è scritto si presta a essere letto nel modo
peggiore.

#### Le due strade sono alternative

| Via | A chi si applica |
|---|---|
| Impegno alla riservatezza sottoscritto | chiunque lavori con il manutentore |
| Adeguato obbligo legale di riservatezza | professioni con vincolo di legge: avvocati, notai, medici |

Chi sviluppa siti non ha un obbligo legale di riservatezza. Vale quindi la
prima via: un impegno firmato.

#### Chi lavora da solo

La clausola è per lo più rivolta al futuro: il manutentore è già vincolato
dall'atto che sottoscrive, e non c'è nessun altro da vincolare. Ma ciò che
garantisce è che **nessuno** acceda a quei dati senza esserlo, e questo si
traduce oggi in una misura sola: **non condividere le credenziali**. Il giorno
in cui coinvolge qualcuno, l'impegno va fatto firmare prima dell'accesso, non
dopo.

#### Collaboratore o sub-responsabile: la distinzione da non sbagliare

| | Persona autorizzata (art. 5) | Sub-responsabile (art. 8) |
|---|---|---|
| Come lavora | sotto la direzione del manutentore, su sue istruzioni | in autonomia, decidendo i propri mezzi |
| Esempio | un tirocinante seguito dal manutentore | un'agenzia cui si subappalta lo sviluppo di un modulo |
| Che cosa serve | impegno alla riservatezza | **autorizzazione scritta del titolare** e distinto atto di nomina |

Il discrimine è **chi decide come si lavora**. Trattare un subappaltatore come
un semplice collaboratore, e saltare l'autorizzazione della scuola, è un
errore che non si sana a posteriori.

#### Modello di impegno alla riservatezza

Una pagina, da conservare insieme all'atto di nomina.

> **IMPEGNO ALLA RISERVATEZZA**
>
> Il sottoscritto [nome e cognome], [qualifica: collaboratore / tirocinante /
> professionista incaricato] di [denominazione del manutentore],
>
> premesso che nello svolgimento della propria attività può accedere a dati
> personali trattati per conto di [denominazione dell'istituto], titolare del
> trattamento, in forza dell'atto di nomina del [data],
>
> **si impegna a**
>
> 1. trattare i dati personali cui accede esclusivamente per le finalità e nei
>    limiti delle istruzioni ricevute, e a non trattarli per fini propri;
> 2. non divulgare, comunicare né diffondere a terzi i dati e le informazioni
>    di cui venga a conoscenza, in qualunque forma;
> 3. custodire con diligenza le credenziali di accesso, a non cederle ad alcuno
>    e a non consentirne l'uso a terzi;
> 4. segnalare senza ritardo a [denominazione del manutentore] ogni violazione
>    o sospetta violazione della sicurezza dei dati;
> 5. cancellare o restituire i dati e le copie eventualmente detenute al
>    termine dell'incarico.
>
> Il presente impegno **permane anche dopo la cessazione del rapporto**, per
> tutto il tempo in cui le informazioni conservino carattere riservato.
>
> [Luogo e data] — Firma ______________________

Va fatto firmare **prima** dell'accesso ai dati: un impegno sottoscritto dopo
non copre ciò che è già avvenuto.

**Assistenza al titolare.** Il responsabile assiste il titolare nel rispondere
alle richieste degli interessati e nella valutazione d'impatto, nei limiti di
quanto conosce.

**Restituzione o cancellazione.** Alla cessazione dell'incarico, i dati vanno
restituiti o cancellati, comprese le copie locali, e va data conferma scritta.

## 5. L'atto da sottoscrivere

L'atto è prodotto **già compilato** dal sito, in
`/admin/reports/psiphos/documentazione`, alla voce di questo documento:
«Scarica il modulo precompilato».

Riporta il titolare con i dati letti dal luogo marcato come sede legale, il
responsabile con i dati indicati in `/admin/config/psiphos`, il dominio del
sito nell'oggetto dell'incarico e la PEC dell'istituto come recapito per la
notifica delle violazioni. Segue la struttura descritta ai paragrafi
precedenti: quattordici articoli, dall'oggetto al registro delle attività di
trattamento, con l'esclusione espressa di ciò che non rientra nell'incarico.

Restano in bianco i dati che il sito non può conoscere: **chi firma per
ciascuna parte, il termine di notifica delle violazioni, il luogo e la data.**

La **durata** non è in bianco: l'art. 1 la àncora al contratto di manutenzione
in essere e ai suoi rinnovi. L'art. 28, paragrafo 3, chiede che la durata sia
*determinata*, non che sia una data: il rinvio al contratto la determina, e ha
il vantaggio di non divergere dal contratto stesso quando questo viene
rinnovato. L'articolo aggiunge che l'atto cessa con quel contratto, così che un
rapporto concluso non lasci in piedi una nomina senza oggetto.

Se il fornitore non è indicato nelle impostazioni, l'atto non lo tace: stampa
la casella vuota e avverte che va indicato perché l'atto risulti completo.

Il testo dell'atto non è riprodotto qui di proposito: esisterebbe in due copie
destinate a divergere, e la copia che conta è quella che si stampa e si firma.

Va sottoposto al Responsabile della protezione dei dati dell'istituzione prima
della sottoscrizione, per le ragioni dette al § 7.

## 6. Che cosa fare dopo la firma

1. **Protocollare** l'atto e conservarlo insieme alla nomina del fornitore
   dell'hosting, all'attestazione di conformità del modulo e alla dichiarazione
   del fornitore del software.
2. **Aprire il proprio registro** delle attività di trattamento, con una scheda
   per ciascuna scuola.
3. **Riesaminare l'atto** quando cambia il perimetro: se un domani la
   manutenzione comprendesse anche le copie di sicurezza o il versamento in
   conservazione, l'art. 13 non sarebbe più vero.

## 7. Un limite di questo documento

È un testo di lavoro, non un parere legale. Ogni istituzione ha un Responsabile
della protezione dei dati, ed è il soggetto tenuto a validarlo: sottoporglielo
prima della firma è nell'interesse di entrambe le parti, e una nomina validata
dal DPO della scuola vale, in caso di contestazione, molto più di una
predisposta dal solo fornitore.

---

*Documento a corredo del modulo Psíphos. Attua il §6 dell'allegato tecnico alla
nota MIM prot. 3803 del 30/06/2026.*
