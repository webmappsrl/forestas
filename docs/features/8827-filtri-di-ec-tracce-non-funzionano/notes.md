> Ticket: oc:8827

# Notes — Tracce assegnate ai layer giusti a ogni import

## Divergenze dal piano, task per task

### Task 3 ricalcolo condizionato e protetto

Il piano chiedeva geometria e PBF accodati sempre, una volta per layer. La review del task ha
mostrato che il job gira a ogni batch, anche orario, e che `regeneratePbfsForLayer()` su un layer
vuoto rigenera i PBF dell'intera app. Quindi:

- `properties.layers` si allinea **sempre** (la funzione del package lavora per differenza e non
  salva nulla se è già giusto): così un retry dopo un errore ripara;
- geometria e PBF si accodano solo se è cambiata la composizione del layer o `properties.layers`;
- gli effetti dopo la scrittura del pivot stanno in un `try/catch` per layer con log sul canale
  `import`: un layer rotto non ferma gli altri né il reindex. La scrittura del pivot resta fuori:
  se fallisce, il job fallisce e riprova;
- i POI si assegnano solo ai layer con aree (`taxonomyWheres`); sui layer di tipologia il pivot
  dei POI si svuota e `properties.layers` dei POI si ripulisce a mano (`removeLayerFromPois()`),
  perché con il pivot vuoto la funzione del package ricadrebbe sul filtro per attività. Entrambe le
  cose solo se il layer ha i POI in modalità automatica;
- middleware `WithoutOverlapping` con `dontRelease()`: se un ricalcolo è già in corso questo si
  salta e la catena prosegue fino al reindex (`releaseAfter` con `tries = 2` poteva far fallire il
  job e fermare la catena);
- test in più rispetto al piano: POI delle aree, nessuna riaccodatura se nulla cambia, riparazione
  di `properties.layers`, errore su un layer, POI manuali, middleware.

### Task 5 messaggio del comando

Il piano diceva di chiedere di «lanciare prima l'import». L'import orario reimporta solo i
tracciati cambiati in Drupal, quindi non crea l'attività dei cammini: il messaggio e il docblock
indicano `sardegnasentieri:import --force` (o il reset notturno dove è attivo).

### Task 6 verifica sui dati reali

Suite completa e PHPStan eseguiti; la verifica dei conteggi (614/151/53/2) con la copia Drupal
locale non è stata fatta perché il container della copia non era acceso. Va fatta su UAT dopo il
deploy.

## Bug trovati

- **Sul reset le righe di `layerables` puntavano a record diversi**: su UAT Sentieri e Cammini
  avevano le stesse 597 righe del 9/9, e 10.350 righe di POI con quasi tutti i POI in ogni layer
  (causa del ticket).
- **Il `TRUNCATE taxonomy_activities ... CASCADE` cancellava anche i legami layer→attività**:
  i 4 layer di tipologia su UAT erano senza attività.

## Decisioni

- `compare-source` non si usa più (serviva solo al passaggio dalle API alla copia del DB): non è
  stato adeguato al nuovo campo `tipologia_itinerari`, che le API non hanno mai esposto.
- Il `sync()` del package riscrive i timestamp di tutte le righe di `layerables` a ogni giro:
  accettato e documentato nel job, evitarlo voleva dire duplicare la query del package.
- Le review dei task sono state fatte sul working tree, senza commit intermedi (regola del repo).
- Review con `wm-review-ticket` (30/10): nessun bloccante; applicati i cleanup 1-6 (iterazione dei
  POI con `lazyById()`, rispetto di `poi_mode`, middleware anti-sovrapposizione, reset con un solo
  commento e una costante per i tipi, costanti per prefisso e nome dei cammini, job spezzato in
  metodi).

## Follow-up

- Tassonomia dedicata «Tipologia percorso» al posto delle attività (ticket a parte, deciso nel
  dialogo).
- Debito: il job rifà in forestas quello che gli observer del package fanno riga per riga, e
  aggira il fallback del package sul pivot vuoto. La strada pulita è un metodo del package (es.
  `LayerService::recalculateAutoLayer()`).
- Se la rigenerazione PBF di un layer fallisce dopo che `properties.layers` è allineato, il giro
  dopo non la ripete: i PBF restano vecchi finché il layer non cambia (l'errore è nel log `import`).
- Su UAT: dopo il primo reset, guardare nel log `import` la durata del ricalcolo rispetto al
  timeout di 300 s.
- Due layer «Area G (Nord-EST)» su UAT (id 1 e 7).
- Le altre richieste del cliente del 28/10 sui filtri (aree «formalmente» nell'area, filtri
  Validazione/Percorribilità, colonne).
