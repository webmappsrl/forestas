# L'import è asincrono, e cosa ne consegue

Riguarda `sardegnasentieri:import` e tutto ciò che deve accadere *dopo* un'importazione: il
ricalcolo delle anomalie del Catasto, e qualunque lavoro futuro che dipenda dall'archivio
completo.

## Come funziona oggi

**Il comando non importa: accoda.** `ImportSardegnaSentieriCommand` interroga le liste, prepara
un job per ogni POI e per ogni tracciato, li dispatcha e ritorna `SUCCESS`. Quando il comando
termina, il database è ancora vuoto: a scrivere sono i job, che Horizon smaltisce nei minuti
successivi. Su 1736 elementi il comando chiude in una ventina di secondi, mentre l'importazione vera ne
impiega cinque (misura locale, 21/09/2026).

**Niente va quindi agganciato alla fine del comando.** Con `--reset` i job vengono raggruppati in
un batch (`Bus::batch`, nome `sardegnasentieri import`) e il ricalcolo delle anomalie parte dalla
callback del batch, cioè quando l'ultimo job ha finito di scrivere.

**Prima gli enti, poi POI e tracciati.** Gli enti (gestori, manutentori, rilevatori, operatori,
complessi forestali) hanno un batch loro, `sardegnasentieri enti`, e il batch di POI e tracciati
parte in catena dopo (`Bus::chain`), anche con `--only`. I job di POI e tracciati collegano solo
enti già importati: con i due batch in parallelo, dopo un `--reset` un tracciato il cui gestore non
c'era ancora perdeva il collegamento, e al giro dopo, non cambiato, non veniva riletto (oc:8513).
Un ente mancante, perché il suo job è fallito, fa saltare il suo collegamento (avviso nel log
`import`) e marca il POI o il tracciato con `properties.forestas.enti_mancanti`: il comando lo
rimette in coda al giro dopo anche se in Drupal non è cambiato, e il segno si toglie quando trova
tutti i suoi enti. Senza il segno, in produzione il collegamento tornerebbe solo al prossimo cambio
del nodo in Drupal. Senza enti cambiati il batch di POI e tracciati parte da
solo.

**Il ricalcolo tollera una quota di job falliti.** La decisione sta in
`ImportSardegnaSentieriCommand::shouldNormalize()`: sotto il 2% di job caduti le anomalie si
ricalcolano, sopra no. Sotto soglia si accetta un archivio quasi completo; sopra ci si ferma, perché le anomalie
sono relazioni fra tracciati e su un archivio gravemente incompleto non ne escono di meno, ne
escono di sbagliate. In entrambi i casi il canale di log `import` registra quanti sono.

**Le aree catastali invece si ricalcolano a ogni batch, senza soglia.** La callback `finally`
accoda per prima la catena sync delle aree → reindex Elasticsearch, sulla coda
`sardegnasentieri-import`; il dettaglio è nel docblock di `dispatchImportBatch()`. Non parte solo
quando non c'è un batch, cioè quando non c'è nessun job da importare.

**I job di import hanno una coda tutta loro, `sardegnasentieri-import`.** Ogni job che completa ne
accoda altri — conversione delle immagini, aggiornamento delle relazioni — e su una coda condivisa
quei job si accumulano davanti a chi deve ancora girare. Il supervisor Horizon che la lavora è
dichiarato in `config/horizon.php`, per tutti gli ambienti compreso `production`.

**La creazione delle tassonomie regge la concorrenza.** L'attività derivata dal `type` del
tracciato si crea con `insertOrIgnore` seguito da una rilettura: decine di job importano insieme
tracciati dello stesso tipo, e chi arriva secondo si prende la riga scritta dal primo invece di
fallire.

**Le tassonomie si leggono una volta per tutti i job.** `getTaxonomyTerms()` usa
`Cache::remember()` con validità un'ora: la cache d'istanza del service dura quanto il job che la
contiene, e con migliaia di job significava altrettante richieste agli stessi tre o quattro
vocabolari.

## Perché così

- **Il ricalcolo appartiene al comando che tronca, non allo scheduler** (oc:8607): stava in un
  `->then()` di `routes/console.php`, che parte al ritorno del comando — cioè quando i job non
  hanno ancora scritto. Il normalize leggeva un archivio appena troncato, scriveva zero anomalie e
  usciva con successo: nessun errore da nessuna parte, solo una lista vuota indistinguibile da un
  catasto senza anomalie. Su collaudo lo stato era 767 tracciati e zero anomalie (21/09/2026).
- **La cache condivisa delle tassonomie** (oc:8607): erano quelle richieste, non i singoli POI, a
  far cadere l'import in timeout. Su 27 job falliti, `/ss/tassonomia/tipologia_poi` compariva 16
  volte, più di qualunque singolo elemento; gli stessi indirizzi aperti dal browser rispondevano
  senza problemi. Con la cache i job falliti sono passati da 26 a zero (misure locali,
  21/09/2026).
- **I ritentativi distanziati** (oc:8607): i job avevano già `$tries = 3` ma nessun `backoff`, e i
  tre tentativi cadevano tutti nella stessa finestra di pochi secondi. Ora sono cinque, a
  30/120/300/600 secondi.
- **La coda dedicata** (oc:8607): con i job di import sulla `default`, un solo job ritentato
  rientrava dietro il post-processing accumulato nel frattempo. Su collaudo il 22/09/2026 il job
  del tracciato 5496 era in posizione 1679 su 5125 — davanti a lui 4346 conversioni immagine e 768
  aggiornamenti di relazioni — e il batch delle 06:00 era ancora aperto tre ore dopo, con le
  anomalie a zero per tutta la mattina. Su collaudo `supervisor-default` ha due soli processi, il
  che allunga ulteriormente la fila.
- **`insertOrIgnore` invece del `catch`** (oc:8607): assorbire la violazione di unicità e rileggere
  sembra la via breve, ma sotto una transazione Postgres la aborta e la rilettura fallisce con
  essa — il test lo mostra, perché `RefreshDatabase` avvolge ogni test in una transazione. Evitare
  il conflitto a monte funziona in entrambi i casi.
- **La soglia invece della tolleranza zero** (oc:8607): con 1736 chiamate a ogni reset qualche
  timeout è la norma, e rinunciare al ricalcolo per quello avrebbe lasciato la lista vuota — cioè
  il difetto stesso che si stava correggendo.
- **Le aree fuori dalle guardie** (oc:8880): la soglia esiste perché anomalie e registro,
  calcolati su un archivio incompleto, escono sbagliati. Le aree si ricalcolano dalla geometria di
  ogni traccia e non hanno questo problema. Dietro la soglia, un reset con il 3% di job falliti
  avrebbe lasciato tutte le tracce senza aree fino al giorno dopo.
- **Il reindex in catena** (oc:8880): Elasticsearch legge `taxonomyWheres` da
  `properties.taxonomy_where`, che il sync scrive solo nel DB. In catena il reindex parte quando le
  aree sono già scritte.

## Come si verifica

Fra la chiusura del batch e la comparsa delle anomalie passano **alcuni minuti**: il normalize
legge tutte le geometrie. Controllare la tabella appena il batch risulta chiuso la trova vuota e
fa concludere, a torto, che il meccanismo non funzioni.

Il riscontro giusto è la riga nel canale `import`:

```
[sardegnasentieri:<runId>] Anomalie ricalcolate (job falliti: 0/1736).
```

Per le aree il riscontro è `[sardegnasentieri:<runId>] Sync aree catastali e reindex accodati.`
nel canale `import`, poi i due job in Horizon nell'ordine sync → reindex (in locale 32 s e 4 s su
780 tracce, 28/10/2026).

## Come ci siamo arrivati

- **Ricalcolo affidato a un `->then()` nello scheduler** (oc:8607, superata): sembrava il posto
  naturale — si tronca alle 06:00, si ricalcola subito dopo — ma si fondava sul presupposto che il
  comando finisse insieme all'importazione.
- **Rifiuto di ricalcolare su qualunque job fallito** (oc:8607, superata): prima versione della
  regola, abbandonata perché in esercizio non sarebbe quasi mai partita.
- **Job di import sulla coda `default`** (oc:8607, superata): finché l'import girava senza
  ritentativi non si notava nulla. Bastava un job caduto per rimandare di ore la chiusura del
  batch, e con essa il ricalcolo — il sintomo era identico a quello che oc:8607 aveva appena
  corretto, tanto che sembrava una regressione del fix.
- **Sync delle aree solo a mano** (oc:8880, superata): partiva dall'action Nova o da
  `wm:resync-taxonomy-where`. Con il reset quotidiano le tracce rinascevano ogni mattina senza aree:
  su collaudo, il 27/10/2026, 0 tracce su 762 avevano `taxonomy_where` (misura riportata nel
  ticket).
