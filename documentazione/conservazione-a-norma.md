# Conservazione a norma dei verbali e delle deliberazioni

Documento operativo per l'adozione di Psíphos. È l'unico dei cinque passi che
**non si esaurisce prima della prima seduta**: la conservazione è un processo
che accompagna ogni seduta successiva.

## 1. Che cosa non è conservazione

Tre equivoci ricorrenti, che conviene sciogliere prima di ogni altra cosa.

**Le copie di sicurezza non sono conservazione.** Servono a rimettere in piedi
un sistema caduto, e conservano l'ultimo stato utile. La conservazione serve ad
assicurare che un documento sia leggibile, integro e riconducibile al suo autore
**fra vent'anni**, quando il sistema che lo ha prodotto non esisterà più.

**Il sito non è un sistema di conservazione.** Psíphos custodisce verbali ed
estratti fra i file riservati, e li custodisce bene: formato PDF/A, impronte
verificabili, immodificabilità. Ma sta dentro un'applicazione che verrà
aggiornata, migrata, un giorno dismessa.

**Il fornitore dell'hosting non è un conservatore.** Ospita, non conserva. Sono
due contratti, due responsabilità, due discipline.

## 2. Che cos'è

Il Codice dell'amministrazione digitale (d.lgs. 82/2005, artt. 43 e 44) e le
*Linee guida AgID sulla formazione, gestione e conservazione dei documenti
informatici* impongono alle pubbliche amministrazioni — e le scuole lo sono — di
conservare i documenti informatici con un processo che assicuri nel tempo
**autenticità, integrità, leggibilità e reperibilità**.

Il processo si compone di:

- un **responsabile della conservazione**, figura interna all'amministrazione;
- un **manuale di conservazione**, che descrive come il processo si svolge;
- un **sistema di conservazione**, gestito di norma da un **conservatore**
  esterno iscritto nel marketplace AgID;
- un **pacchetto di versamento**, con cui i documenti entrano nel sistema.

## 3. I ruoli

| Ruolo | Chi | Può essere esternalizzato? |
|---|---|---|
| **Titolare dell'oggetto di conservazione** | l'istituzione scolastica | no |
| **Responsabile della gestione documentale** | figura interna, di norma il DSGA | no |
| **Responsabile della conservazione** | **figura interna** all'istituzione | **no**: le attività operative si delegano, il ruolo no |
| **Conservatore** | soggetto esterno, di norma iscritto nel marketplace AgID | sì, è il suo mestiere |

**Il punto che le scuole sbagliano più spesso** è credere che affidare la
conservazione a un fornitore esaurisca l'adempimento. Non è così: il
responsabile della conservazione resta un dipendente dell'istituzione, nominato
con atto formale, che sottoscrive il manuale e risponde del processo. Al
conservatore si affidano le **attività**, non la **responsabilità**.

## 4. La scuola ha probabilmente già tutto

Prima di procurarsi qualcosa, conviene verificare.

Quasi tutte le istituzioni scolastiche usano un applicativo di **protocollo
informatico e gestione documentale** — Argo, Axios, Spaggiari, Infoschool e
simili — e quei contratti comprendono di norma la conservazione presso un
conservatore accreditato.

Se è così, la scuola:

- ha già un **conservatore**;
- ha già un **manuale di conservazione**, spesso fornito in bozza dal medesimo
  fornitore e adottato dall'istituto;
- ha già nominato un **responsabile della conservazione**.

In questo caso non serve nulla di nuovo: **serve far entrare in quel canale
anche i verbali degli organi collegiali**, che spesso non vi entrano perché
storicamente erano cartacei.

### Le tre domande da porre al fornitore del protocollo

1. Il nostro contratto comprende la conservazione a norma presso un conservatore
   accreditato? Quale?
2. Come si versa un documento informatico prodotto **fuori** dal vostro
   applicativo — per esempio un verbale in PDF/A con i suoi metadati?
3. Quali metadati richiede il vostro pacchetto di versamento, e in quale
   formato?

La terza domanda è quella che conta: la risposta determina il lavoro residuo.

La lettera precompilata — «Scarica il modulo precompilato» in
`/admin/reports/psiphos/documentazione` — le riporta già sulla carta intestata
dell'istituto, e come quella al fornitore dell'hosting chiede un riscontro su
ciascun punto entro un termine da indicare.

## 5. Che cosa Psíphos consegna già pronto

| Elemento | Dove |
|---|---|
| Verbale in **PDF/A-2B** | `/psiphos/verbale/{id}/documento` |
| Estratto di ciascuna deliberazione in PDF/A-2B | `/psiphos/delibera/{id}/documento` |
| Esportazione strutturata del verbale, con le impronte | `/psiphos/verbale/{id}/esporta` |
| Esportazione strutturata di ciascun atto | `/psiphos/delibera/{id}/esporta` |
| Tracciature della seduta | `/psiphos/seduta/{id}/tracciature` |

Ogni documento porta i **metadati previsti dalle Linee guida**:

- identificativo univoco
- tipologia documentale
- data di chiusura
- modalità di formazione
- oggetto
- soggetto produttore
- impronta SHA-256 del contenuto e del file

Se sul server manca Ghostscript i documenti escono in **PDF ordinario** anziché
in PDF/A: la circostanza è registrata sul verbale stesso e va segnalata al
responsabile della conservazione, perché un documento non conforme al formato
prescritto si tratta diversamente.

## 6. Il limite: il pacchetto di versamento

Il modulo produce i documenti e i metadati. **Non produce il pacchetto di
versamento** nel formato del conservatore, e non potrebbe: ogni conservatore ha
il proprio, e la corrispondenza fra i metadati normativi e i campi richiesti va
concordata.

È il lavoro residuo, e va fatto **una volta sola**: concordata la
corrispondenza, ogni seduta successiva segue la stessa strada.

Nella maggior parte dei casi la strada è semplice: il verbale viene protocollato
in uscita o in entrata nell'applicativo di protocollo, e da lì segue il flusso
di conservazione già in essere per tutti gli altri documenti dell'istituto.

## 7. Che cosa si conserva, e per quanto

| Documento | Termine |
|---|---|
| Verbali degli organi collegiali | di norma **conservazione permanente**: sono documenti d'archivio dell'istituto |
| Estratti di deliberazione | come il verbale da cui sono tratti |
| Tracciature tecniche del procedimento | termine configurato nel modulo, predefinito dieci anni |

Il termine effettivo va verificato nel **piano di conservazione** dell'istituto,
che è il documento che stabilisce che cosa si conserva e per quanto.

La differenza fra le prime due righe e la terza è sostanziale: i verbali sono
atti, le tracciature sono evidenze tecniche del procedimento. Le prime si
conservano, le seconde si cancellano a scadenza — ed è il modulo a farlo, solo
su sedute già verbalizzate.

## 8. La procedura dopo ogni seduta

1. **Il segretario sigilla il verbale.** Gli estratti di delibera sono prodotti
   contestualmente.
2. **Si scaricano** il verbale in PDF/A, gli estratti e le esportazioni
   strutturate.
3. **Si protocolla** il verbale nell'applicativo di gestione documentale.
4. **Si versa** in conservazione secondo la procedura concordata al punto 6.
5. **Si conserva l'esportazione strutturata** insieme al PDF: è il file da cui
   l'impronta è ricalcolabile, ed è ciò che rende verificabile il documento anche
   quando il sito non esisterà più.

Il quinto punto è quello che si dimentica, ed è quello che dà valore a tutto il
resto. Il PDF prova che il documento esiste; **l'esportazione, con la sua
impronta, prova che è quello sigillato quel giorno**.

## 9. Verificare un documento conservato, anche fra dieci anni

Non serve il sito, non serve il modulo, non serve Drupal:

```
shasum -a 256 verbale-<identificativo>.json
```

Il risultato deve coincidere con l'**impronta del contenuto** stampata sul
verbale. È una verifica che chiunque può ripetere con uno strumento comune, e
questa è la ragione per cui l'esportazione va conservata accanto al PDF.

## 10. Se la scuola non ha un conservatore

Capita, soprattutto negli istituti piccoli. In tal caso:

1. Si verifica il **piano di conservazione** e il **manuale**: se non esistono,
   vanno adottati.
2. Si nomina il **responsabile della conservazione** con atto formale.
3. Si individua un **conservatore** iscritto nel marketplace AgID, oppure si
   verifica se il fornitore del protocollo possa erogare il servizio.
4. Nel frattempo i documenti **restano nel sito**, dove sono immodificabili e
   verificabili, ma **non sono conservati a norma**: la circostanza va
   verbalizzata e sanata.

Il punto 4 merita una precisazione. L'assenza di conservazione a norma **non
invalida le deliberazioni**: incide sulla tenuta documentale, non sulla validità
degli atti. Non è quindi una ragione per rinviare le sedute a distanza — è una
ragione per non rinviare l'adozione del processo di conservazione.

## Elenco di riscontro

- [ ] Verificato se il contratto di protocollo comprenda la conservazione
- [ ] Individuato il conservatore
- [ ] Nominato il responsabile della conservazione (figura interna)
- [ ] Adottato il manuale di conservazione
- [ ] Concordata la corrispondenza dei metadati con il conservatore
- [ ] Verificato che i documenti escano in PDF/A e non in PDF ordinario
- [ ] Stabilito chi scarica, protocolla e versa dopo ogni seduta
- [ ] Verificato che l'esportazione strutturata sia conservata insieme al PDF
- [ ] Verificato nel piano di conservazione il termine per i verbali

---

*Documento a corredo del modulo Psíphos. Attua il §7 dell'allegato tecnico alla
nota MIM prot. 3803 del 30/06/2026.*
