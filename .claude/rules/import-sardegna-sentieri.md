---
paths:
  - "app/Services/Import/**"
  - "app/Console/Commands/**"
  - "app/Jobs/Import/**"
  - "app/Http/Clients/**"
  - "routes/console.php"
---

# Trappole: import da Sardegna Sentieri

Forestas usa un import custom da Sardegna Sentieri (piattaforma Drupal), **non** l'import standard
da GeoHub. Comando `sardegnasentieri:import`, service
`app/Services/Import/SardegnaSentieriImportService.php`, client
`app/Http/Clients/SardegnaSentieriClient.php`, job in `app/Jobs/Import/`.

- **Il flusso GeoHub del package (`ImportTaxonomyActivityJob`, `ImportTaxonomyJob`,
  `wm-geohub-import.php`) non è usato qui**: indagando un bug su tassonomie, POI o track si perde
  tempo a leggerlo — la causa sta in `SardegnaSentieriImportService` (oc:8489)
- **`--reset` fa `TRUNCATE ... RESTART IDENTITY CASCADE` globale** su `ec_tracks`, `ec_pois` e le
  tassonomie, non una cancellazione selettiva per `app_id`: su Forestas è innocuo perché l'app è
  una sola (`SardegnaSentieriImportService::IMPORT_APP_ID` = 1), diventerebbe un problema con più
  app nello stesso database (oc:8489)
- **Il troncamento notturno porta via anche ciò che non viene da Drupal**, per vincolo a cascata: la
  storia dei cambi di stato dei codici e le istanze di accatastamento. Oggi è **deliberato**:
  `--reset` tronca esplicitamente anche `trail_applications`, che altrimenti sopravvivrebbe ai
  codici che aveva riservato lasciando istanze orfane. Finché il reset notturno esiste, un'istanza
  presentata su collaudo non sopravvive alla notte, e i codici `reserved` che aveva prenotato non
  sono ricalcolabili da nessun comando. Quando il reset verrà spento, la sincronizzazione va
  cambiata in rimozione dei soli tracciati spariti alla fonte (oc:8489, oc:8607)
- **Chi fa il troncamento notturno lo decide `SARDEGNASENTIERI_DAILY_RESET`, non `APP_ENV`**: i
  server si chiamano `develop` e `production`, con il collaudo che gira come `production` perché una
  produzione vera non esiste ancora. Legarlo ai nomi degli ambienti farebbe cancellare i dati ogni
  notte alla produzione vera il giorno che nascerà (oc:8489)
- **L'import orario non cancella**: marca i record spariti alla fonte con
  `properties->forestas->deleted_from_source = true`. È il motivo per cui serviva comunque un
  troncamento per avere lo specchio esatto di Drupal, e anche la strada per farne a meno (oc:8489)
- **Il comando accoda e ritorna: quando termina, il database è ancora vuoto.** Nulla che dipenda
  dai dati importati va agganciato alla sua fine — va agganciato alla fine dei job. È il difetto
  che lasciava il Catasto senza anomalie dopo ogni reset:
  [docs/knowledge/import-asincrono-e-anomalie.md](../../docs/knowledge/import-asincrono-e-anomalie.md) (oc:8607)
- **I job di import stanno sulla coda `sardegnasentieri-import`, non su `default`.** Sulla `default`
  finivano dietro il post-processing che l'import stesso accoda — migliaia di conversioni immagine
  per ogni run — quindi un job ritentato rientrava in fondo e girava ore dopo, tenendo il batch
  aperto e il ricalcolo delle anomalie fermo con lui. La coda ha un supervisor suo in
  `config/horizon.php`: aggiungerne uno senza dichiarare il supervisor significa accodare job che
  nessuno lavora (oc:8607)
- **Due job che importano tracciati dello stesso tipo creano la stessa tassonomia insieme.**
  `sentiero` arriva da centinaia di tracciati, e un `firstOrNew` seguito da un `save` sono due
  passi: il secondo job viola l'unicità su `identifier` e muore. Le tassonomie si creano con
  `insertOrIgnore` più rilettura, non catturando l'eccezione — sotto transazione Postgres la
  aborta, e la rilettura fallirebbe con essa (oc:8607)
- **Dopo aver modificato un job, riavvia Horizon** (`docker restart horizon-<progetto>`): i worker
  tengono in memoria le classi vecchie, e una modifica appena scritta non ha effetto finché non
  ripartono (oc:8607)
- **In locale `--reset` si ferma sulla guardia del backup**, perché il disco `wmdumps` punta a un
  bucket S3 irraggiungibile dalla macchina: per provarlo si punta il disco al minio del container
  (`AWS_DUMPS_ENDPOINT`, `AWS_DUMPS_USE_PATH_STYLE_ENDPOINT` nel `.env`, non tracciato) e si crea
  il bucket `wmdumps` (oc:8607)
- **L'import scrive il codice del sentiero in `properties['ref']`** e il catasto lo legge da lì: il
  service non è parametrizzato, cambiare quella chiave richiede di aggiornare
  `WM_TRAIL_LEGACY_CODE_PROPERTY` — vedi [docs/knowledge/catasto-sentieri.md](../../docs/knowledge/catasto-sentieri.md) (oc:8489)
- **Il job del registro catastale non aspetta il normalize nel regime orario**: con `--reset`
  parte solo dopo un normalize riuscito, ma nell'import incrementale (senza `--reset`) parte
  **sempre**, a ogni giro: a fine batch, o subito dal comando se non c'è nessun job da accodare,
  perché lì non c'è un troncamento da ricalcolare prima — con un giro all'ora scarica il foglio
  Google ~24 volte al giorno, voluto dallo spec ma da tenere d'occhio. Con `--only`, e con
  `--reset` senza job, non parte:
  [docs/knowledge/registro-catastale.md](../../docs/knowledge/registro-catastale.md) (oc:8539)
- **`REGISTRO_CATASTALE_URL` va aggiunta a mano anche nel `.env` di ogni server**: non è coperta
  dal gate di `WM_TRAIL_REGISTRY_ENABLED`, e senza di essa il job del registro fallisce in lettura
  a ogni giro (oc:8539)
- **Un sentiero importato non ha lunghezza, dislivello, tempi né quote, ed è voluto**: l'import non
  scrive `manual_data` e non accoda il DEM. Non rimettere la mappatura dai campi di Drupal né
  accodare la catena DEM dall'import — con il reset notturno ricalcolerebbe tutto ogni giorno:
  [docs/knowledge/8641-import-sardegna-sentieri-non-scrive-manual-data.md](../../docs/knowledge/8641-import-sardegna-sentieri-non-scrive-manual-data.md) (oc:8641)
- **La copia Drupal non c'è per forza**: prima di scrivere codice su
  `DB::connection('sardegnasentieri')` verifica che il container `mysql-sardegnasentieri-dump`
  sia acceso e caricato — vedi
  [docs/knowledge/copia-database-drupal.md](../../docs/knowledge/copia-database-drupal.md) (oc:8705)
- **Uno script che legge la copia Drupal dall'host usa sempre `127.0.0.1:3307`**, in locale come su
  UAT: per lavorare dal locale sulla copia di UAT si spegne il container locale e si apre il tunnel
  sulla 3307, mai su un'altra porta — vedi
  [docs/howto/copia-database-drupal.md](../../docs/howto/copia-database-drupal.md) (oc:8706)
