> Ticket: oc:8513

# Gli Ente importati non vengono mai aggiornati dalla sorgente

## Cosa cambia

Gli enti (soggetti gestori, manutentori, rilevatori, operatori, complessi forestali) si importano
**come i sentieri e i POI**: l'import legge dalla copia del DB Drupal la lista di tutti i nodi
`ente_istituzione_societa` con il loro `changed`, la confronta con quello salvato e accoda un job per
ogni ente nuovo o cambiato. Gli enti spariti da Drupal vengono marcati, non cancellati.

L'import mette **prima gli enti, poi il resto**: il comando accoda un batch con i soli enti e, quando
finisce, il batch di POI e sentieri di oggi. Il job di un sentiero o di un POI collega solo enti che
esistono già, senza crearli.

Con `--reset` (il notturno su UAT) gli enti **non si cancellano**: si riscrivono tutti da Drupal,
come fa `--force`, mantenendo id e immagini; il reset svuota solo i collegamenti di `enteables`, che
POI e sentieri ricostruiscono. In produzione, senza reset, arrivano solo gli enti cambiati.

## Perché

Oggi un ente entra in Forestas solo la prima volta che un sentiero o un POI lo richiama, e poi non
viene più riletto: `resolveOrImportEnte()` restituisce l'id dell'ente esistente senza passare dalla
sorgente (`SardegnaSentieriImportService.php:917-938`). Una correzione fatta in Drupal a nome,
contatti, sito, tipo, posizione o immagine non arriva mai, nemmeno con `--force`, e il 09/09 i 70
enti sono stati sistemati a mano. Con Drupal destinato alla dismissione, ogni correzione fatta là
prima dello spegnimento va recepita.

Allo scrum del 30/10 Giuseppe Bonfanti: «gli enti non si aggiornano perché quando li ho importati
credevo che non cambiassero, invece vanno aggiornati».

Oggi l'import prende anche solo gli enti richiamati: nel DB locale sono 72, nella copia di Drupal 93.

## Requisiti

- [ ] La fonte espone la lista degli enti come quelle di sentieri e POI: `nid => changed` in ora
      italiana, di tutti i nodi `ente_istituzione_societa` (anche le bozze, come le API).
- [ ] L'ente salva il `changed` di Drupal in `properties->forestas->updated_at`; l'import non
      azzera più `properties`.
- [ ] Il comando accoda un job per ogni ente con `changed` diverso da quello salvato, o per tutti
      con `--force` e `--reset`, sulla coda `sardegnasentieri-import`.
- [ ] Il job dell'ente scrive nome, descrizione, contatti, pagina web, tipo, posizione e immagine
      da Drupal; un campo svuotato in Drupal si svuota anche in Forestas. Un errore di lettura o di
      scrittura dell'ente fa fallire il job (che si ritenta), non lo inghiotte. Un'immagine che non
      si scarica lascia solo l'avviso nel log, come per sentieri e POI.
- [ ] Gli enti non più presenti in Drupal sono marcati `properties->forestas->deleted_from_source =
      true`; un ente che ricompare torna senza marchio al suo import successivo.
- [ ] Il batch di POI e sentieri parte solo dopo la fine del batch degli enti; senza enti da
      importare parte subito. Anomalie, registro catastale e aree catastali restano agganciati alla
      fine del batch di POI e sentieri, come oggi.
- [ ] I job di POI e sentieri collegano solo enti esistenti; un riferimento a un ente assente si
      salta e si registra nel log `import`.
- [ ] `--reset` non cancella gli enti: li riscrive tutti (come `--force`) e svuota `enteables`
      prima di reimportare. Gli enti spariti da Drupal restano, marcati.
- [ ] `--only=pois` e `--only=tracks` importano comunque prima gli enti cambiati: sono il
      prerequisito dei collegamenti.
- [ ] Test: ente cambiato riscritto e uguale no; ente sparito marcato e smarcato; batch di POI e
      sentieri dopo quello degli enti; un sentiero collega il gestore dopo un reset.
- [ ] Verifica in locale dalla copia di UAT (`--force`): 93 enti con il `changed` salvato, i
      collegamenti di `enteables` dei sentieri uguali a prima, nessun job fallito.

## Rischi

- **Un sentiero perde un gestore o un operatore.** È il rischio vero del cambio: si confrontano i
  collegamenti di `enteables` prima e dopo l'import completo in locale.
- **Le modifiche fatte in Nova agli enti si perdono**: su UAT ogni notte con il reset, in
  produzione quando l'ente cambia in Drupal. È voluto: Drupal resta l'unica fonte finché è vivo.
- **Su UAT restano anche gli enti spariti da Drupal**, marcati e non cancellati: UAT non è una
  copia esatta di Drupal per gli enti, in cambio di id stabili e nessuna immagine riscaricata.
- **Le immagini degli enti si riconoscono dall'URL**: se Drupal cambia il file, la vecchia si
  cancella e la nuova si scarica (`SardegnaSentieriMediaSyncService`), come per i sentieri.
- **Un'immagine dell'ente che non si scarica non si ritenta** finché l'ente non cambia in Drupal
  (in produzione; su UAT la riprende il reset): è il comportamento di sentieri e POI, che usano lo
  stesso `SardegnaSentieriMediaSyncService`. Renderla ritentabile vale per tutti e tre, fuori da
  questo ticket.
- **Un batch degli enti che fallisce in parte** non deve bloccare POI e sentieri: il secondo batch
  parte comunque, e i collegamenti agli enti mancanti si saltano col log.

## Out of scope

- Proteggere in Nova i campi degli enti modificati a mano, o segnalare in Nova che vengono da
  Sardegna Sentieri.
- Usare i dati degli enti che l'import non scrive ancora (galleria, collegamenti, servizi):
  ticket successivo, come per gli altri dati del record completo.
- Spegnimento del reset notturno alla dismissione di Drupal.

## Moduli toccati

Tutto nel repo principale `forestas`; `wm-package` e `wp-forestas` non vengono toccati.

- `app/Services/Import/Source/SardegnaSentieriSource.php`, `SardegnaSentieriDatabaseSource.php`,
  `SardegnaSentieriApiSource.php` — lista degli enti
- `app/Services/Import/SardegnaSentieriImportService.php` — `importOrUpdateEnte()`, risoluzione
  degli enti nei collegamenti
- nuovo `app/Jobs/Import/ImportSardegnaSentieriEnteJob.php`
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — lista, confronto, marcatura, batch in
  due tempi, `--reset`
- `tests/Feature/Import/` — test degli enti
- `docs/knowledge/`, `.claude/rules/import-sardegna-sentieri.md` — enti nell'import
