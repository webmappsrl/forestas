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

**Il ricalcolo tollera una quota di job falliti.** La decisione sta in
`ImportSardegnaSentieriCommand::shouldNormalize()`: sotto il 2% di job caduti si ricalcola, sopra
no. Sotto soglia si accetta un archivio quasi completo; sopra ci si ferma, perché le anomalie
sono relazioni fra tracciati e su un archivio gravemente incompleto non ne escono di meno, ne
escono di sbagliate. In entrambi i casi il canale di log `import` registra quanti sono.

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
- **La soglia invece della tolleranza zero** (oc:8607): con 1736 chiamate a ogni reset qualche
  timeout è la norma, e rinunciare al ricalcolo per quello avrebbe lasciato la lista vuota — cioè
  il difetto stesso che si stava correggendo.

## Come si verifica

Fra la chiusura del batch e la comparsa delle anomalie passano **alcuni minuti**: il normalize
legge tutte le geometrie. Controllare la tabella appena il batch risulta chiuso la trova vuota e
fa concludere, a torto, che il meccanismo non funzioni.

Il riscontro giusto è la riga nel canale `import`:

```
[sardegnasentieri:<runId>] Anomalie ricalcolate (job falliti: 0/1736).
```

## Come ci siamo arrivati

- **Ricalcolo affidato a un `->then()` nello scheduler** (oc:8607, superata): sembrava il posto
  naturale — si tronca alle 06:00, si ricalcola subito dopo — ma si fondava sul presupposto che il
  comando finisse insieme all'importazione.
- **Rifiuto di ricalcolare su qualunque job fallito** (oc:8607, superata): prima versione della
  regola, abbandonata perché in esercizio non sarebbe quasi mai partita.
