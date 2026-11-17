> Ticket: oc:8959

# Distanza dei percorsi vicini al POI configurabile

Quarto pezzo di oc:8959, dopo il dettaglio del POI
(`docs/features/8959-dati-forestas-nel-dettaglio-del-poi/`). Produce un altro merge di oc:8959 in
`develop`, d'accordo col dev. Il branch parte da `origin/develop`.

## Cosa cambia

Oggi i collegamenti fra POI e percorsi (`ec_poi_ec_track`) si calcolano con un raggio fisso di
1000 m, scritto in `config/sardegnasentieri.php` (`poi_tracks_radius_meters`). Da lì escono i
`nearby_tracks` del dettaglio del POI e i `related_pois` del dettaglio della traccia.

Dopo questo lavoro il raggio è una colonna della tabella `apps`,
`ec_poi_nearby_ec_tracks_max_distance_meters` (intero, default 1000), aggiunta da una migration di
forestas. Si modifica da Nova, nella tab `pois` della Resource `App` di forestas, con il campo
«Distanza massima dei percorsi vicini al POI (m)».

Il dettaglio del POI espone il valore usato nel calcolo, in
`properties.forestas.nearby_tracks_max_distance_meters`, accanto a `nearby_tracks` e
`nearby_tracks_count`. La pagina del POI del sito WordPress lo usa per scrivere «Percorsi entro
1 km» al posto del «5 km» fisso del wireframe.

Quando il valore cambia, un observer di forestas sul salvataggio dell'App accoda un job che
ricalcola solo i collegamenti dei POI: un batch di `CalculatePoiTracksChunkJob` per tutti i POI e,
a batch finito, la stessa rigenerazione di `CalculateNearbyTracksJob` (file delle tracce, indice,
`pois.geojson`). I sentieri correlati fra tracce non si toccano.

Il package non cambia, e `config.json` non contiene il raggio.

## Perché

Allo scrum del 2026-11-16 (call delle 08:24, fra 00:45 e 00:50) Giuseppe ha chiesto che il raggio
sia un parametro e non un valore scritto nel codice: se Forestas chiede 5 km o 10 km si cambia il
valore, senza un commit. Il frontend deve mostrare lo stesso raggio usato dal backend («nel
frontend deve spuntare 1 km… l'informazione deve essere identica»).

Giuseppe pensava a `config.json`. `config.json` però lo costruisce `AppConfigService` del package,
istanziato con `new` in `UpdateAppConfigJob.php:91` e `AppController.php:399,404`, senza un punto di
estensione per gli shard: per aggiungerci una chiave bisogna modificare il package, e il dev ha
scelto di non farlo. Il raggio va quindi nel dettaglio del POI, che forestas costruisce da sé
(`app/Support/ForestasPoiDetail.php`) e che la pagina del POI legge già: il valore che si legge è
sempre quello con cui sono stati calcolati quei `nearby_tracks`.

La colonna invece di una chiave in `apps.properties` è una scelta del dev: il nome dice cosa
contiene e l'unità di misura, e il valore ha un default nel database.

Il ricalcolo va accodato al salvataggio perché nessun altro punto lo fa in tempi certi. L'import
orario ricalcola solo se da Drupal arriva qualcosa da importare (`ImportSardegnaSentieriCommand.php:214-231`),
e il reset notturno c'è solo su UAT.

## Requisiti

- [ ] Una migration di forestas aggiunge ad `apps` la colonna
  `ec_poi_nearby_ec_tracks_max_distance_meters`: intero, not null, default 1000. La migration è
  reversibile (`down()` toglie la colonna).
- [ ] La Resource `App` di forestas (`app/Nova/App.php`) ridefinisce `pois_tab()`: i campi del
  package restano invariati e in fondo si aggiunge il campo `Number`
  «Distanza massima dei percorsi vicini al POI (m)», con validazione `required|integer|min:1`
  (nessun massimo, decisione del dev) e una help text che avvisa che salvarlo ricalcola i percorsi
  vicini di tutti i POI e riscrive i file delle tracce, e che per qualche minuto il dettaglio può
  mostrare il raggio nuovo con i percorsi vecchi. Il campo compare solo sull'App dell'import.
- [ ] `CalculatePoiTracksChunkJob` legge il raggio dalla colonna dell'App del POI con
  `PoiNearbyTracksDistance::forApp()` (1000 se manca o non è positivo, mai 0), non più da
  `config('sardegnasentieri.poi_tracks_radius_meters')`. La chiave in `config/sardegnasentieri.php`
  si toglie.
- [ ] Due esecuzioni di `CalculatePoiTracksChunkJob` sugli stessi POI si mettono in fila con il
  lock delle righe dei POI (`lockForUpdate`, in ordine di id), dentro la transazione che calcola,
  cancella e scrive: nessun blocco viene scartato, e l'ultimo a girare scrive con l'ultimo raggio
  salvato. Al posto di `WithoutOverlapping`, che non vedeva due blocchi dai confini diversi.
- [ ] Il dettaglio del POI (`ForestasPoiDetail`) espone
  `properties.forestas.nearby_tracks_max_distance_meters`, letto dalla stessa colonna, anche quando
  il POI non ha percorsi vicini; solo per i POI dell'App dell'import.
- [ ] Un job nuovo, `RecalculatePoiTracksForDistanceJob`, unico finché non parte, accoda in un
  batch i soli `CalculatePoiTracksChunkJob` dei POI dell'App dell'import
  (`CalculatePoiTracksChunkJob::forImportApp()`, gli stessi blocchi del batch di fine import) e, a
  batch finito, la stessa rigenerazione di `CalculateNearbyTracksJob::regenerateOutputs()`. Non
  svuota `ec_track_related_tracks`.
- [ ] Un observer di forestas sul modello `App` accoda `RecalculatePoiTracksForDistanceJob` quando
  `ec_poi_nearby_ec_tracks_max_distance_meters` è cambiata (`wasChanged`), solo per l'App
  dell'import (`SardegnaSentieriImportService::IMPORT_APP_ID`), e dopo il commit della transazione
  di Nova.
- [ ] Le stringhe nuove (label e help text del campo) sono in `lang/it.json` e `lang/en.json`.
- [ ] Test: il calcolo usa il raggio dell'App; il dettaglio espone il valore; l'observer accoda il
  job solo se il valore cambia; la migration ha il default 1000.
- [ ] Documentazione aggiornata: `docs/knowledge/dettaglio-del-poi-dati-forestas.md` (raggio,
  chiave nuova, ricalcolo al salvataggio).

## Rischi

- **Due ricalcoli sovrapposti perdevano dati.** Il padre `CalculateNearbyTracksJob` non è unico e
  svuota `ec_track_related_tracks` all'avvio; i blocchi con la stessa chiave di overlap vengono
  scartati (`dontRelease`). Due salvataggi ravvicinati, o un salvataggio durante il batch
  dell'import orario, lasciavano tracce senza correlati o POI col raggio vecchio, senza un
  ricalcolo che li sistemi in produzione. Mitigato: al cambio del raggio si ricalcolano solo i POI
  (`RecalculatePoiTracksForDistanceJob`, unico finché non parte), e i blocchi dei POI si mettono in
  fila col lock delle righe dei POI invece di essere scartati. Il lock evita anche le righe doppie
  che due blocchi dai confini diversi scrivevano insieme (la pivot non ha un vincolo unico).
- **Il lock tiene ferme le righe dei POI per la durata del blocco.** Mentre un blocco calcola, un
  salvataggio degli stessi POI (import, Nova) aspetta: secondi con 1 km, di più con raggi grandi.
- **Nessun limite superiore al raggio** (decisione del dev). In locale: 4.283 collegamenti a 1 km,
  14.018 a 5 km, 30.798 a 10 km; file delle tracce e indice crescono di conseguenza, e con raggi
  molto grandi i blocchi da 50 POI possono superare il timeout di 300 s. Il campo è `required`, così
  il campo vuoto dà un errore di validazione e non un errore SQL.
- **Finestra di disallineamento accettata.** Il dettaglio legge raggio e collegamenti dal vivo,
  mentre la pivot si riscrive a blocchi: fino alla fine del batch (minuti) un POI può mostrare il
  raggio nuovo con i percorsi del raggio vecchio. Un blocco fallito lascia i suoi POI disallineati
  fino al ricalcolo successivo, con l'errore nel log `import`. Salvare il raggio usato nella pivot
  costerebbe più della finestra che chiude.
- **Rollback:** prima il codice, poi la migration. Il codice nuovo senza la colonna legge `null` e
  ricade su 1000, ma il codice vecchio non legge la colonna: toglierla prima del codice non serve a
  nulla, e toglierla col codice nuovo ancora in esecuzione è comunque innocuo per il calcolo.

## Out of scope

- Il raggio in `config.json`: richiede una modifica al package, che il dev non vuole fare ora.
- `related_pois` del dettaglio del POI: restano i collegamenti scelti a mano su Drupal e
  mantengono il nome. Il dev chiederà a Giuseppe se vanno calcolati per vicinanza e rinominati
  `nearby_pois`.
- Il raggio dei sentieri correlati fra tracce (`sardegnasentieri.nearby_tracks_radius_meters`,
  50 m): resta in configurazione.
- La chiave `nearby_tracks_max_distance_meters` in `pois.geojson`.

## Moduli toccati

Tutto nel repo principale `forestas`; nessuna modifica a `wm-package` né a `wp-forestas`.

| File | Cosa |
|---|---|
| `database/migrations/<data>_add_ec_poi_nearby_ec_tracks_max_distance_meters_to_apps_table.php` | nuovo: colonna su `apps` |
| `app/Nova/App.php` | `pois_tab()` ridefinita con il campo nuovo |
| `app/Support/PoiNearbyTracksDistance.php` | nuovo: nome della colonna, default, `forApp()` |
| `app/Jobs/Import/CalculatePoiTracksChunkJob.php` | raggio letto dall'App, lock delle righe dei POI al posto di `WithoutOverlapping`, `forImportApp()` |
| `app/Jobs/Import/CalculateNearbyTracksJob.php` | blocchi dei POI da `CalculatePoiTracksChunkJob::forImportApp()` |
| `app/Jobs/Import/RecalculatePoiTracksForDistanceJob.php` | nuovo: batch dei soli POI più rigenerazione |
| `app/Models/EcPoi.php` | solo il docblock di `nearbyTracks()` |
| `config/sardegnasentieri.php` | tolta `poi_tracks_radius_meters` |
| `app/Support/ForestasPoiDetail.php` | chiave `nearby_tracks_max_distance_meters` |
| `app/Observers/AppNearbyTracksDistanceObserver.php` | nuovo: accoda `RecalculatePoiTracksForDistanceJob` al cambio del valore |
| `app/Providers/AppServiceProvider.php` | registrazione dell'observer |
| `lang/it.json`, `lang/en.json` | label e help text |
| `tests/Feature/Import/PoiTracksTest.php` e test del dettaglio del POI e dell'observer | test |
| `docs/knowledge/dettaglio-del-poi-dati-forestas.md`, `docs/knowledge/dettaglio-della-traccia-dati-forestas.md` | documentazione |
