# psiphos
Psíphos, dal greco “sassolino, ciottolo”, perché nell'antica Grecia i sassolini venivano utilizzati per votare, Modulo Drupal per la gestione delle votazioni durante le sedute dei vari organi collegiali.

## Documenti per l'istituzione scolastica

Il §9 impone alle scuole di verificare preventivamente la coerenza della
soluzione ai requisiti, acquisendo documentazione tecnica e una dichiarazione
di conformità. In `documentazione/`:

| Documento | A che serve |
|---|---|
| `dichiarazione-conformita.md` | Documentazione tecnica e dichiarazione da sottoscrivere (§9) |
| `dpia-elementi.md` | Procedura e sezione tecnica per la valutazione d'impatto della scuola (§6) |
| `regolamento-articolo.md` | Procedura di adozione e bozza di articolo per il Regolamento d'istituto (§8) |
| `richieste-al-fornitore-hosting.md` | Che cosa la scuola deve chiedere al fornitore dell'infrastruttura, con modello di richiesta (§5, §6) |
| `nomina-del-manutentore.md` | Nomina a responsabile del trattamento di chi mantiene il sito, con modello di atto (§6) |
| `conservazione-a-norma.md` | Come far entrare verbali e delibere nel processo di conservazione dell'istituto (§7) |

**Senza l'adozione dell'articolo di Regolamento non si delibera a distanza**:
il §8 lo richiede espressamente, e le deliberazioni assunte senza copertura
regolamentare sono impugnabili.

Dal sito i documenti si leggono e si scaricano in
`/admin/reports/psiphos/documentazione`: il §9 chiede all'istituzione di
**acquisire** la documentazione tecnica, e un percorso di file dentro la
cartella del modulo non è acquisibile da un dirigente scolastico.

### L'ordine in cui affrontarli

I primi quattro si esauriscono **prima della prima seduta deliberativa**; il
quinto accompagna ogni seduta successiva.

| | Passo | Chi lo compie |
|---|---|---|
| 1 | Richieste al fornitore dell'infrastruttura | istituzione, con il fornitore hosting |
| 2 | Nomina del manutentore del sito | istituzione e manutentore |
| 3 | Adeguamento del Regolamento d'istituto | Consiglio d'istituto |
| 4 | Valutazione d'impatto | dirigente, con il DPO |
| 5 | Conservazione a norma | responsabile della conservazione |

L'ordine non è arbitrario: la valutazione d'impatto non è conducibile finché il
fornitore dell'infrastruttura non ha dichiarato che cosa fa, e il Regolamento
non si adatta finché la configurazione del modulo non è stata scelta.

### Attestazione della singola installazione

`/admin/reports/psiphos/conformita` produce l'attestazione **dalla
configurazione in essere**, requisito per requisito. Va riprodotta e allegata
agli atti dopo ogni modifica della configurazione: una dichiarazione generica
attesterebbe ciò che il modulo può fare, non ciò che questa scuola fa davvero.

Due formati, per due destinatari:

- **PDF/A da firmare** — con dichiarazione e blocchi di firma per chi
  realizza il modulo e per il dirigente scolastico. È il documento che va agli
  atti e che la segreteria protocolla.
- **JSON strutturato** — per la verifica automatica e per il confronto fra
  configurazioni nel tempo.

Sui 28 requisiti censiti, 21 sono attuati dal modulo e **7 restano a carico
dell'istituzione** — cifratura, aggiornamenti, segregazione degli ambienti,
copie di sicurezza e incidenti, nomina del responsabile per la
videoconferenza, valutazione d'impatto, Regolamento d'istituto. L'attestazione
li elenca invece di tacerli, perché una dichiarazione che li omettesse sarebbe
rassicurante e falsa.

## Riferimenti normativi

- Nota MIM prot. 3803 del 30/06/2026 (AOODPIT) — *Svolgimento a distanza delle
  attività di carattere collegiale che rivestono carattere deliberativo nelle
  istituzioni scolastiche. Esiti chiusura confronto con le organizzazioni sindacali*
- Allegato tecnico — *Requisiti tecnico-organizzativi per la gestione digitale
  degli organi collegiali e delle operazioni di voto nelle istituzioni scolastiche*
- Art. 44, commi 3 lett. a) e b) e 6, CCNL comparto Istruzione e Ricerca 18/01/2024
- Orientamento applicativo ARAN 12/06/2024, id. 31472

Entrambi i documenti ministeriali sono in `documentazione/`.

## Ambito

Copre le attività collegiali deliberative dei docenti: collegio dei docenti
(inclusi programmazione, verifiche di inizio e fine anno, informazione alle
famiglie) e consigli di classe, interclasse e intersezione, compresi i gruppi
di lavoro operativo per l'inclusione. Il Consiglio d'istituto resta fuori
ambito: l'art. 44 CCNL disciplina le attività dei docenti.

Psíphos **non** sostituisce la piattaforma di videoconferenza. L'allegato
tecnico (§1) ammette l'integrazione di più strumenti purché ciascuno sia
conforme per la propria funzione: l'audio-video resta allo strumento in uso,
Psíphos copre le operazioni di voto, la verbalizzazione e le evidenze.

## Stato di avanzamento

- [x] 1 — Scheletro installabile: permessi, impostazioni §3
- [x] 2 — Modello dati: seduta, ordine del giorno, delibera, presenze
- [x] 3 — Urna a scrutinio segreto §4.3
- [x] 4 — Aula virtuale e votazione live §4
- [x] 5 — Verbale sigillato ed export strutturato §7
- [x] 6 — Audit, sessioni e sicurezza §3.4 / §5
- [x] 7 — Dichiarazione di conformità §9, DPIA, bozza di regolamento §8

## Verifica

```bash
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_modello.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_urna.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_aula.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_verbale.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_tracciature.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_conformita.php
ddev drush php:script web/modules/custom/psiphos/tests/manuale/verifica_atto.php
```

Se un estratto di delibera non si scarica:

```bash
drush php:script web/modules/custom/psiphos/tests/manuale/diagnosi_estratti.php
```

Non modifica nulla. Riferisce, per ogni delibera conclusa, quale delle quattro
condizioni manca — rotta assente, atto non sigillato, file eliminato, file non
leggibile — che da fuori si presentano tutte come una pagina non trovata.

Per comporre una seduta completa e sigillata da ispezionare — punto non
deliberativo, approvazione unanime, approvazione a maggioranza con astenuti,
scrutinio segreto a scelta fra opzioni, votazione annullata e ripetuta:

```bash
ddev drush php:script web/modules/custom/psiphos/tests/manuale/seduta_dimostrativa.php
```

Su un'installazione di prova, per ripartire da zero:

```bash
drush php:script web/modules/custom/psiphos/tests/manuale/azzera_dati.php
```

Rimuove **tutti** i dati Psíphos — sedute, delibere, verbali, urne, tracciature
e i documenti prodotti. Non è uno strumento di manutenzione: su
un'installazione in esercizio cancellerebbe atti amministrativi.

Senza argomenti non cancella nulla: mostra di quale sito si tratta, quanti
verbali sigillati contiene e il comando per confermare, che richiede il nome
del sito digitato per esteso.

```bash
drush php:script web/modules/custom/psiphos/tests/manuale/azzera_dati.php -- 'IC 66 Martiri'
```

La conferma esiste perché chi amministra più istituti lancia questo comando
dalla stessa sessione SSH cambiando solo cartella, e una cartella sbagliata
porterebbe via atti veri: il nome del sito è l'unica cosa che distingue una
scuola dall'altra prima che il danno sia fatto.

Creano dati di prova, esercitano macchina a stati, quorum, vincoli di
immodificabilità, deposito delle schede, scrutinio, sigillo, estratti di
delibera, tracciature e attestazione di conformità, poi ripuliscono.

**Le suite rimuovono soltanto ciò che hanno creato.** Le sedute di prova
portano il prefisso `[verifica] ` nel titolo e le utenze il prefisso
`psiphos_prova_`; la pulizia agisce su quelle e su ciò che vi dipende, mai su
altro. Se sull'installazione esistono sedute non di prova le suite lo
segnalano e le lasciano intatte. Sono ripetibili e ripartono pulite anche
dopo un'interruzione a metà.

## Percorso di una seduta

| Passaggio | Dove |
|---|---|
| Assegnare i permessi ai ruoli | `/admin/people/permissions` |
| Convocare la seduta | Contenuti › Sedute collegiali › Convoca una seduta |
| Comporre l'elenco degli aventi diritto | scheda **Aventi diritto** sulla seduta |
| Inserire i punti all'ordine del giorno | `/admin/content/psiphos/punto/aggiungi` |
| Predisporre le delibere sui punti | `/admin/content/psiphos/delibera/aggiungi` |
| Preparare il testo degli atti | blocco **Testo dell'atto** sulla delibera |
| Condurre la seduta | scheda **Aula** sulla seduta |
| Completare gli atti delle delibere votate | comando **Redigi l'atto** sotto ciascuna delibera |
| Redigere e sigillare il verbale | scheda **Verbale** sulla seduta |
| Ricostruire il procedimento | scheda **Tracciature** sulla seduta |

L'elenco degli aventi diritto è parte dell'atto di convocazione, non
un'operazione accessoria: è il denominatore di ogni quorum e viene
cristallizzato all'apertura della seduta.

## Tema delle pagine

Nessuna pagina del modulo è destinata al pubblico: convocazioni, aula, verbali
e tracciature sono atti interni. Tutte le rotte sono marcate come
amministrative e usano quindi il tema di amministrazione del sito, l'aula
compresa.

Perché abbia effetto, i ruoli che partecipano alle sedute devono avere il
permesso **«Visualizza il tema di amministrazione»**; senza, restano sul tema
del sito.

La conformità all'accessibilità dipende allora dal tema di amministrazione
adottato, e resta una condizione da verificare: la dichiarazione di
accessibilità che l'istituzione pubblica ogni anno comprende espressamente le
intranet. Fra i temi di amministrazione, Claro è l'unico soggetto
all'accessibility gate di Drupal core.

## Aula virtuale

`/psiphos/seduta/{id}/aula` — unica pagina per tutti i ruoli, con il banco di
presidenza visibile solo a chi presiede.

La sequenza è vincolata dal fatto che le presenze si registrano solo a seduta
aperta: si apre la seduta, gli aventi diritto entrano, **poi** si verifica il
quorum costitutivo. Finché non è raggiunto, nessun punto può essere messo ai
voti. È l'appello, tradotto in macchina a stati.

La pagina si aggiorna da sola interrogando `/aula/stato` ogni cinque secondi.
La risposta porta una firma che cambia solo quando muta qualcosa di
sostanziale: i contatori si aggiornano in pagina, la scheda di voto viene
ricostruita soltanto quando serve, mai mentre qualcuno la sta compilando.

L'inattività del §3.4 è assenza di **contatto**, non assenza di interazione.
Una seduta collegiale dura ore e per quasi tutto quel tempo chi partecipa
ascolta: segue la videoconferenza e non tocca l'aula, che è uno strumento di
voto e non il luogo della discussione. Pretendere un'interazione per restare
presenti farebbe decadere il collegio intero nel mezzo del dibattito.

Il segnale di abbandono è che il collegamento cessi. La pagina dell'aula si
fa viva da sé, anche in secondo piano — a ritmo ridotto — e la presenza si
mantiene; quando la pagina viene chiusa, il dispositivo si spegne o la rete
cade, i segnali smettono di arrivare e la presenza decade. La disconnessione
dal sito, che è una dichiarazione esplicita, fa uscire dall'aula subito.

**Limite noto:** una pagina lasciata aperta su un dispositivo abbandonato
continua a segnalare presenza. È il corrispettivo della giacca sulla sedia in
una sala riunioni. Il presidio è procedurale: il banco di presidenza mostra
quante schede sono state depositate e quante ne mancano, e chi non vota si
riconosce.

## Tracciature tecniche

Il §2 chiede due cose distinte: evidenze documentali e tracciature tecniche.
Il verbale è la prima e documenta l'**esito**; il registro delle tracciature è
la seconda e documenta il **procedimento**.

Le annotazioni sono concatenate — ciascuna incorpora l'impronta della
precedente — così una tracciatura rimossa o alterata non passa inosservata.
Qui la concatenazione è ammessa, al contrario di quanto vale per l'urna: una
tracciatura è per definizione una cronologia, e conservarne l'ordine non
rivela nulla sui voti, che nel registro non compaiono mai.

La catena è **per seduta**. Una catena unica renderebbe impossibile rimuovere
le tracciature di una seduta alla scadenza dei termini senza spezzare la
verificabilità di tutte le altre.

Le annotazioni sono agganciate al salvataggio delle entità, non ai comandi
del banco di presidenza: qualunque strada porti al cambio di stato, la
tracciatura viene scritta. Un registro che dipendesse dal percorso seguito
documenterebbe solo i percorsi previsti.

`voto.depositato` registra che un avente diritto ha votato — nulla di più di
quanto già fa il registro dei votanti. `voto.rifiutato` registra il motivo del
rifiuto, mai la scheda respinta: annotare che cosa qualcuno aveva provato a
votare significherebbe conservare un voto associato a un'identità.

### Conservazione

Le tracciature di una seduta vengono rimosse dal cron dopo il termine
configurato (dieci anni per impostazione predefinita), e solo se la seduta è
già verbalizzata — il verbale sigillato resta come evidenza documentale. Al
loro posto rimane un'annotazione di troncamento che dichiara quante ne sono
state rimosse: una catena che comincia dal nulla è indistinguibile da una a
cui è stato tolto l'inizio.

### Diagnostica

`/admin/reports/status` riporta lo stato effettivo di cinque presidi: livello
di autenticazione, gestione delle sessioni, formato di conservazione,
archiviazione riservata dei verbali e integrità delle catene. Serve al §9, che
impone alle scuole di accertare preventivamente la coerenza della soluzione ai
requisiti: perché quell'accertamento sia possibile, lo stato dei presidi deve
essere leggibile senza aprire il codice.

## Verbale e conservazione

**L'esportazione si conserva, non si rigenera.** Al sigillo la struttura della
seduta viene serializzata una volta sola e i byte restano sul verbale.
L'impronta è lo SHA-256 di *quei* byte, e il PDF è generato da quegli stessi
byte: documento ed esportazione non possono divergere neanche in linea di
principio.

È una scelta di sostanza, non di efficienza. Ricostruire la struttura a ogni
richiesta — com'era in una versione precedente — rende l'impronta dipendente
dal codice che la ricostruisce e dai dati vivi da cui la ricava: bastava una
traduzione rivista, un cognome corretto dopo un matrimonio o un aggiornamento
del modulo perché un verbale intatto smettesse di verificare. Uno strumento di
integrità che grida «manomesso» quando nessuno ha manomesso nulla insegna a
ignorare l'allarme, e il §7 chiede autenticità e integrità **nel tempo**:
un documento che si rigenera non è conservabile per definizione.

Il verbale sigillato non è modificabile in alcuna parte, nemmeno da un
amministratore. Se risulta errato la strada è un verbale di rettifica, che
lascia traccia di entrambi.

Due impronte SHA-256, perché due sono le cose da garantire:

- **impronta del contenuto** — sull'esportazione conservata, ricalcolabile da
  chiunque con un qualunque strumento, anche fuori dal sito;
- **impronta del documento** — sul file consegnato alla conservazione.

`/psiphos/verbale/{id}/verifica` risponde a tre domande, e la distinzione
conta:

| Domanda | Che cosa dice | Peso |
|---|---|---|
| **Esportazione conservata** | i byte sigillati sono ancora quelli | è la verifica che vale davanti a chi controlla |
| **Documento conservato** | il PDF è quello prodotto al sigillo | idem |
| **Corrispondenza** | la banca dati racconta ancora la stessa seduta | segnale di secondo livello |

Una mancata corrispondenza **non è di per sé una manomissione**: un cognome
rettificato o una traduzione rivista bastano a produrla, e il documento
sigillato non recepisce quelle modifiche né deve recepirle. Il valore del
verbale resta attestato dalle prime due; la terza dice che i dati vivi si sono
mossi, e le tracciature dicono se qualcuno li ha mossi.

### Formato di conservazione

Le Linee guida AgID prescrivono il PDF/A. Il generatore di PDF non lo produce:
la conversione in **PDF/A-2B** è affidata a Ghostscript, configurabile in
`/admin/config/psiphos`. Se Ghostscript non è disponibile sul server il
verbale viene sigillato ugualmente in PDF ordinario e il formato effettivo
resta registrato sul verbale — un dato che deve risultare agli atti, perché
un documento non conforme al formato di conservazione va trattato
diversamente dal conservatore.

I verbali sono salvati in `private://psiphos/verbali`: contengono nominativi,
presenze e, per il voto palese, le scelte espresse.

## Carta intestata

Verbali ed estratti di delibera portano la stessa intestazione, da un solo
template incluso da entrambi: escono dalla stessa segreteria e devono
presentarsi allo stesso modo.

| Dato | Da dove |
|---|---|
| Denominazione dell'istituto | nome del sito (`system.site.name`) |
| Indirizzo, telefono, C.F., email, PEC | luogo con **sede legale** spuntata |
| Indirizzo del sito | richiesta in corso |

La denominazione **non** viene dal titolo del luogo: quello è l'edificio —
«sede centrale», «plesso Marconi» — e solo per coincidenza in qualche
installazione porta il nome dell'istituto.

L'intestazione è **congelata nel documento al sigillo**: una delibera
protocollata porta i recapiti che l'istituto aveva quel giorno, non quelli di
oggi. Ricavarli ogni volta dai dati vivi riscriverebbe la carta intestata di
atti già adottati e ne romperebbe l'impronta.

Se nessun luogo è marcato sede legale, o se il modulo gira fuori da Ouitoulía,
resta la sola denominazione: un'intestazione incompleta è un documento
perfettibile, un documento che non si produce è un problema.

## Estratti di delibera

Le scuole tengono le delibere separate dal verbale: il verbale documenta la
seduta, l'estratto documenta il singolo atto e circola da solo verso l'albo,
l'Amministrazione Trasparente e gli uffici. Ogni votazione conclusa produce
perciò un proprio documento, nella forma in uso negli atti collegiali:

```
              Istituto Comprensivo Statale "66 MARTIRI"
                  Via Olevano, 81 – Grugliasco (TO)
            Tel. 011/78.60.77 – C.F.: 95565960010
                    toic86200p@istruzione.it
   toic86200p@pec.istruzione.it – www.ic66martirigrugliasco.edu.it

Delibera n. 35 — 30/06/2026
PIANO ANNUALE PER L'INCLUSIONE 2025-2026

COLLEGIO DEI DOCENTI

Visto il DPR 275/1999 Regolamento dell'Autonomia
Visto il D.lgs. 66/2017
Tenuto conto della proposta del GLI del 12/06/2026

Approva il Piano Annuale per l'Inclusione (PAI) 2025/2026,      ← scritto
allegato alla presente delibera.

Il Collegio dei docenti approva all'unanimità con la            ← composto
seguente votazione:

   Aventi diritto   172
   Presenti         172
   Votanti          169
   Favorevoli       169
   Contrari           0
   Astenuti           0
```

Il segretario scrive **numero**, **oggetto**, **premesse** e **dispositivo**.
Proclamazione e prospetto li compone il sistema leggendo l'urna: è il punto in
cui una trascrizione a mano renderebbe l'atto difettoso senza che nessuno se
ne accorga fino al contenzioso. L'unanimità è riconosciuta dal conteggio, non
dichiarata a mano; il verbo segue la struttura della scheda, perché su una
scheda di approvazione l'organo approva e su una scheda a scelta proclama una
designazione, e sono due atti diversi.

**Quando si redige.** Il testo dell'atto compare già nel modulo della delibera,
in un blocco a parte, perché premesse e dispositivo si conoscono quasi sempre
prima del voto: fanno parte della proposta messa ai voti. Chi non li ha ancora
li lascia vuoti e li scrive a seduta conclusa.

Dopo l'apertura dell'urna la delibera è congelata — quesito, scheda e
maggioranza non cambiano più — ma il testo dell'atto resta redigibile fino al
sigillo. Per questo la redazione dell'atto ha un proprio modulo e un proprio
controllo di accesso, distinti da quelli della delibera: è l'unica scrittura
che deve restare possibile su una votazione già svolta.

**Quando si sigilla.** Insieme al verbale, nello stesso atto: sono lo stesso
momento di chiusura e non possono avvenire separatamente, altrimenti verbale
e delibere potrebbero divergere. Ne segue che **un atto incompleto impedisce
il sigillo del verbale**, e la pagina del verbale dice quali delibere manchino
e di che cosa.

**Che cosa non produce un estratto.** Le votazioni annullate: restano agli
atti nel verbale ma il §8 le vuole prive di effetti, e far circolare come atto
ciò che non ne produce sarebbe fuorviante.

**Impronte.** L'estratto conserva la propria esportazione come il verbale, e
ne porta due impronte proprie — sull'esportazione e sul file — così chi lo
riceve può verificarlo senza disporre del verbale. Non è però un
documento sciolto: dichiara l'identificativo del verbale da cui è tratto, la
sua impronta e il sigillo dell'urna da cui l'esito è uscito. Un estratto
scambiato con un altro si riconosce da questi tre riferimenti.

Gli estratti sono salvati in `private://psiphos/delibere`, separati dai
verbali: chi cerca una delibera negli archivi la cerca fra le delibere.

### Cosa il verbale riporta, e cosa no

Sul voto palese il registro riporta chi ha votato e come, come impone il §4.2.
Sul voto segreto riporta chi ha partecipato al voto e il conteggio
complessivo, mai la scelta individuale: il dato da cui ricavarla non esiste.

## Come è garantita la segretezza del voto

Il §4.3 dell'allegato tecnico chiede una separazione strutturale non
reversibile fra identità e voto, inaccessibile anche agli amministratori di
sistema, e metadati che non consentano la re-identificazione «neanche
indirettamente». Le misure adottate:

| Misura | Requisito |
|---|---|
| Due tabelle senza colonne in comune oltre alla delibera: `psiphos_attestazione` registra chi ha votato, `psiphos_urna` cosa è stato votato | separazione strutturale |
| Tabelle semplici e non entità Drupal: niente uuid, niente marche di creazione e modifica, nessun hook che altri moduli possano intercettare per registrare altrove | non accessibilità |
| Nessuna marca temporale sulla scheda: senza l'ora di deposito, l'ora di attestazione del votante non ha nulla con cui essere accostata | trattamento dei metadati |
| Identificativo di scheda casuale su 62 bit come chiave primaria: InnoDB ordina fisicamente le righe per chiave primaria, quindi l'ordine di memorizzazione non conserva l'ordine di deposito | trattamento dei metadati |
| Preferenze ridotte a forma canonica ordinata: l'ordine in cui il votante ha spuntato le caselle non viene conservato | trattamento dei metadati |
| Sigillo SHA-256 sull'insieme **ordinato** delle schede | verificabilità dell'esito |
| Unicità del voto imposta dalla chiave primaria composta `(delibera, utente)`, non da un controllo applicativo | §4.1, univocità |

Le schede non sono concatenate in una catena di hash, che pure sarebbe la
scelta abituale per garantire integrità: una catena impone un ordine, e
l'ordine è esattamente il metadato che il §4.3 vieta di conservare. Il
sigillo sull'insieme ordinato ottiene lo stesso risultato — rileva schede
aggiunte, rimosse o alterate — senza introdurre alcuna sequenza.

### Limite noto

Finché l'urna è aperta, chi abbia accesso diretto al motore di database può
osservare le due tabelle crescere e tentare correlazioni statistiche fra
attestazioni e schede. Le misure sopra rendono l'attacco poco praticabile ma
non impossibile. La contromisura resta organizzativa: accesso al database
riservato, registrato e verificabile, come previsto dal §5. Va dichiarato
nella DPIA anziché taciuto.

## Strutture di scheda

Chi predispone la delibera sceglie la struttura della scheda prima di aprire
la votazione:

- **approvazione** — favorevole, contrario, astenuto. Voci fisse.
- **scelta singola** — opzioni definite dal proponente più la scheda bianca.
  Una preferenza per votante. Per designazioni ed elezioni.
- **scelta multipla** — come sopra, con più preferenze fino a un massimo che
  deve restare inferiore al numero di opzioni.

Struttura, opzioni, numero di preferenze, tipo di voto e regola di maggioranza
si bloccano all'apertura dell'urna e non sono più modificabili.

L'urna conserva la chiave tecnica della voce (`opzione_1`, `scheda_bianca`),
mai il testo dell'opzione: è breve, non rivela nulla di per sé e resta stabile
perché le opzioni sono bloccate. Lo scrutinio è una sola mappa
`voce => numero di voti` per tutte le strutture, così verbale ed esportazione
leggono sempre lo stesso dato.
