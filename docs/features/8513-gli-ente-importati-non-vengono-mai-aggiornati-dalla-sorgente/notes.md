> Ticket: oc:8513

# Notes — Gli Ente importati non vengono mai aggiornati dalla sorgente

## Deviazioni dal piano

- **Task 4, batch in due tempi**: invece di estrarre `makeImportBatch()`, `dispatchImportBatch()`
  riceve i job degli enti e li concatena con un `entiBatch()` nuovo; il comportamento è quello del
  piano.
- **Task 4, test**: «senza enti cambiati accoda solo POI e sentieri» è diventato «non riaccoda un
  ente non cambiato» (stesse asserzioni); aggiunti «senza POI e sentieri cambiati accoda solo gli
  enti», «riaccoda un ente marcato sparito che è tornato in Drupal» e «non marca nulla se la lista
  degli enti è vuota» — quest'ultimo era nel piano e mancava, trovato dalla review.
- **Task 5, verifica locale**: fermata dal dev a 305 sentieri su 766, giudicati sufficienti
  (collegamenti 479 prima e dopo, tutti e cinque i ruoli; 976 POI; 93 enti con il `changed`).
- **Fuori dai moduli dichiarati**: `tests/Pest.php` (parametro `$enti` della fonte finta).

## Bug trovati

- **Il `--reset` dell'import non svuotava `enteables`.** La tabella non ha chiavi esterne verso
  POI e sentieri, quindi il `TRUNCATE ... CASCADE` non la raggiungeva: nel DB locale c'erano 23
  collegamenti orfani su 1690. Ora il reset la svuota.
- **Un POI a cui in Drupal tolgono tutti gli enti teneva i vecchi collegamenti**: `syncEntiPoi`
  usciva senza cancellare se non trovava enti. Ora li cancella, come per i sentieri.

## Decisioni

- **POI e sentieri con un ente mancante si rileggono al giro dopo** (bloccante della review): il nodo
  si marca con `properties.forestas.enti_mancanti` e il comando lo riaccoda anche con lo stesso
  `changed`; il segno si toglie quando li trova tutti. Prima il collegamento, in produzione, tornava
  solo al cambio successivo del nodo in Drupal.

- Un ente senza tipo in Drupal perde in Forestas anche il tipo messo a mano: è lo specchio di
  Drupal, con il test «svuota i campi anche di un ente senza tipo in Drupal» (prima della
  correzione il ramo del tipo non mappato non salvava nessuna modifica).

- Gli enti si importano dalla lista completa di Drupal (93 nodi `ente_istituzione_societa`), non
  solo quando un sentiero o un POI li richiama (scrum del 30/10, «come le track»).
- Gli enti spariti da Drupal si marcano con `deleted_from_source`, come POI e sentieri; un ente
  marcato e ricomparso si riaccoda anche con lo stesso `changed`, o non perderebbe il marchio.
- Drupal resta l'unica fonte: nessuna protezione dei campi modificati in Nova e nessuna nota in
  Nova (richiesta del dev).
- Prima il batch degli enti, poi in catena quello di POI e sentieri, anche con `--only`.
- Il `--reset` non cancella gli enti: li riscrive tutti e svuota `enteables`. La cancellazione è
  stata scartata perché cambierebbe gli id ogni notte e riscaricherebbe le immagini.
- Un'immagine dell'ente che non si scarica resta un avviso nel log, come per sentieri e POI.

## Follow-up

- Rendere ritentabili le immagini che non si scaricano, per sentieri, POI ed enti insieme: oggi
  una volta salvato il `changed` non si ritentano finché il nodo non cambia in Drupal.
- I dati degli enti che l'import non scrive ancora (galleria, collegamenti, servizi) sono già nel
  record completo della copia.
