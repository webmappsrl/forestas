# Dettaglio del POI: dati di Forestas

## Come funziona oggi

`api/ec/poi/{id}` risponde da `App\Http\Controllers\Api\EcPoiDetailController`, registrato in
`AppServiceProvider` con lo stesso URI e lo stesso nome della rotta del package
(`default.api.ec.poi.json`): la rotta registrata dopo prende il posto di quella del package e ne tiene la
posizione fra le altre rotte dei POI. Il package carica sempre `Wm\WmPackage\Models\EcPoi`
(`EditorialContentController`), quindi lo shard non ha altro punto in cui aggiungere i suoi dati. Le
altre rotte (`{id}.geojson`, download, `api/v2/ec/poi/{id}`) e `pois.geojson` restano del package e
portano il `forestas` salvato.

La risposta parte da `getGeojson()` del package e aggiunge `name`, `taxonomies` (stessa forma di
`pois.geojson`) e `properties.forestas` costruito in uscita da `App\Support\ForestasPoiDetail`; toglie
`out_source_feature_id`, `sardegnasentieri_id` e `layers`.

**Il nid di Drupal esce ovunque come `forestas.sardegnasentieri_id`, come nelle tracce** (oc:8959,
decisione del dev): è la chiave stabile con cui il WordPress aggancia segnalazioni e recensioni,
perché l'id dello shard su dev e UAT cambia a ogni reset notturno. Nel DB l'import lo salva in
`properties.out_source_feature_id`, la chiave del package (i POI più vecchi in
`properties.sardegnasentieri_id`), e da oc:8959 anche in `properties.forestas.sardegnasentieri_id`:
- nel dettaglio, `ForestasPoiDetail` lo legge da `out_source_feature_id`, o dal vecchio nome;
- in `pois.geojson`, che il package scrive con il `forestas` salvato così com'è e senza un punto in
  cui lo shard possa aggiungere dati, arriva dal `forestas` salvato, quindi solo dopo che l'import
  ha riscritto il POI (dev e UAT: reset notturno; altrove `sardegnasentieri:import --force`). Lì
  esce anche `out_source_feature_id`, fuori da `forestas`, perché il package mette nel file tutte le
  `properties` del POI.

```json
"forestas": {
  "sardegnasentieri_id": "137",
  "url": "https://www.sardegnasentieri.it/da-vedere/nuraghe-serbissi-osini",
  "code": "C-521A Nuraghe", "created_at": "…", "updated_at": "…",
  "directions": {"it": "…"},
  "videos": [{"url": "…", "title": null}], "attachments": [{"url": "…", "title": "…"}],
  "organizations": {"operator": [{"id": 31, "slug": "…", "name": {…}}]},
  "related_pois": [{"id": 168, "name": {…}, "feature_image": {…}, "addr_complete": "Gairo (OG)"}],
  "nearby_tracks": [{"id": 609, "ref": null, "name": {…}, "route_type": {…}, "feature_image": {…},
                     "distance": null, "duration_forward": null, "distance_from_poi": 28.2}],
  "nearby_tracks_count": 7,
  "nearby_tracks_max_distance_meters": 1000
}
```

| Chiave | Fonte |
|---|---|
| `url`, `code`, `created_at`, `updated_at` | `properties.forestas` salvato (`codice` → `code`) |
| `directions` | `properties.forestas.come_arrivare` (traducibile; `null` legacy gestito da `App\Models\EcPoi`) |
| `videos`, `attachments`, `organizations` | come nel dettaglio della traccia (`ForestasDetailParts`); gli enti dei POI stanno in `enteables` con il tipo `Wm\WmPackage\Models\EcPoi`, come li scrive l'import (`EcPoi::enti()`) |
| `related_pois` | `ec_poi_related_pois` (POI correlati di Drupal, reciproci), con la card dei POI del sito: `id`, `name`, `feature_image`, `addr_complete`, `elevation` solo se la geometria ha la quota. Oggi la quota è 0 su tutti i POI: quella di Drupal sta nel testo della località («Tuones (1025 m)») |
| `nearby_tracks` | `ec_poi_ec_track` (`EcPoi::nearbyTracks()`), dal più vicino, al massimo 10: card delle tracce (`ForestasDetailParts::trackCard`) più `distance_from_poi`, in metri dal tratto più vicino, calcolata nella query |
| `nearby_tracks_count` | quanti percorsi collegati in tutto |
| `nearby_tracks_max_distance_meters` | la distanza con cui sono calcolati i `nearby_tracks` (`apps.ec_poi_nearby_ec_tracks_max_distance_meters`, `PoiNearbyTracksDistance::forApp()`), sempre presente per i POI dell'app di import, assente per le altre app: il sito ci scrive «Percorsi entro … km» |

Le chiavi vuote non escono. Il `properties.forestas` salvato non cambia.

**`ec_poi_ec_track` si calcola per vicinanza.** `CalculatePoiTracksChunkJob` scrive le tracce entro la
distanza dell'App del POI, la colonna `apps.ec_poi_nearby_ec_tracks_max_distance_meters` (default
1000 m, mai 0), con `order` uguale alla distanza in metri. Ogni blocco riscrive solo i suoi POI in una transazione, perché il dettaglio legge la tabella dal
vivo; senza eventi, perché l'observer del pivot del package (`EcPoiEcTrackObserver`) aggiungerebbe i POI
ai layer delle tracce, mentre per questa app i POI dei layer li decide
`RecalculateSardegnaSentieriLayersJob`. La tabella è la stessa riga vista dai due lati: dà anche i
`related_pois` del dettaglio delle tracce, che quindi sono i POI entro la stessa distanza, dal più
vicino.

I blocchi partono in due modi:
- a fine import, nel batch di `CalculateNearbyTracksJob` insieme ai sentieri correlati;
- quando in Nova cambia la distanza (tab `pois` dell'App, campo «Distanza massima dei percorsi vicini
  al POI (m)», `required|integer|min:1`, visibile solo sull'App dell'import):
  `AppNearbyTracksDistanceObserver` accoda dopo il commit `RecalculatePoiTracksForDistanceJob`,
  unico finché non parte, che mette in un batch i soli blocchi dei POI
  (`CalculatePoiTracksChunkJob::forImportApp()`) e alla fine fa la stessa rigenerazione (file delle
  tracce, indice, `pois.geojson`). Non tocca i sentieri correlati.

Due ricalcoli possono sovrapporsi (un cambio in Nova durante l'import), anche con blocchi dai confini
diversi. Ogni blocco, nella sua transazione, blocca prima le righe dei suoi POI (`lockForUpdate`, in
ordine di id), poi legge il raggio, calcola, cancella e scrive: un blocco che trova gli stessi POI
occupati aspetta e non viene scartato, l'ultimo a girare scrive con l'ultimo valore salvato, e la
cancellazione vede sempre le righe scritte dall'altro. Senza il lock le righe si duplicavano, perché
`ec_poi_ec_track` non ha un vincolo unico. Il prezzo: un salvataggio degli stessi POI (import, Nova)
aspetta la fine del blocco, secondi con 1 km.

Fino alla fine del batch un POI può mostrare la distanza nuova con i percorsi calcolati con la vecchia;
un blocco fallito lascia i suoi POI così fino al ricalcolo successivo, con l'errore nel log `import`.

## Perché così

- **Rotta sostituita in forestas, non modifica al package** (oc:8959): il dev non vuole toccare il
  package per i dati di un solo shard; `ec_track_model` esiste per osm2cai, dove la traccia ha un altro
  modello, e non va replicata per i POI.
- **Percorsi del POI calcolati, come i sentieri correlati** (oc:8959, 12/11): correlati e vicini sono
  la stessa cosa. Default 1 km (decisione del dev): 4.283 collegamenti in locale, 5,6 per traccia;
  300 m darebbe la quantità di Drupal (2.452, 3,2 per traccia), 5 km 14.018, 10 km 30.798.
- **Distanza modificabile in Nova, senza un commit** (oc:8959, scrum del 16/11): Giuseppe ha chiesto
  che sia un parametro e che il frontend mostri lo stesso valore del backend. È una colonna di `apps`
  e non una chiave di `properties`, per scelta del dev; nessun massimo, per scelta del dev.
- **Il raggio nel dettaglio del POI, non in `config.json`** (oc:8959): `config.json` lo scrive
  `AppConfigService` del package, istanziato con `new` (`UpdateAppConfigJob`, `AppController`) e senza
  un punto di estensione per lo shard; il dev non ha voluto toccare il package. Nel dettaglio il
  valore che si legge è quello con cui sono calcolati i `nearby_tracks` mostrati accanto.
- **Al cambio della distanza si ricalcolano solo i POI** (oc:8959): `CalculateNearbyTracksJob` svuota
  `ec_track_related_tracks` all'avvio e non è unico; due ricalcoli sovrapposti lasciavano tracce senza
  correlati. I sentieri correlati non dipendono da questa distanza.
- **Lock delle righe dei POI, non `WithoutOverlapping`** (oc:8959, review del 16/11): la chiave di
  `WithoutOverlapping` era il primo id del blocco, quindi non vedeva due blocchi dai confini
  diversi, e con `dontRelease` scartava il secondo, con `releaseAfter` consumava i tentativi di
  Horizon. Il lock mette in fila per POI, senza scartare né consumare tentativi.
- **Campo e chiave solo per l'App dell'import** (oc:8959, review del 16/11): è l'unica i cui POI
  sono collegati per vicinanza; altrove il valore non farebbe nulla.
- **Al massimo 10, con il conteggio a parte** (oc:8959): come la tavola 7 del wireframe.
- **POI correlati fra loro ancora da Drupal** (oc:8959): «per ora», nessun raggio deciso.

## Come ci siamo arrivati

- **Raggio fisso in `config/sardegnasentieri.php`** (`poi_tracks_radius_meters`, oc:8959, superato il
  16/11): cambiarlo richiedeva un commit, e il frontend non poteva conoscerlo.
- **Al cambio della distanza, `CalculateNearbyTracksJob` così com'è** (oc:8959, superata nella
  challenge del 16/11): vedi sopra, i ricalcoli sovrapposti.
- **Blocchi ritentati con `releaseAfter(360)`** (oc:8959, superata nella review del 16/11): vedi
  sopra, il lock delle righe.
- **Tabella nuova `ec_poi_nearby_tracks`** (oc:8959, scartata dal dev il 12/11).
- **Percorsi del POI = collegamenti di Drupal** (oc:8959, superata lo stesso giorno): calcolati per
  vicinanza dopo aver visto le distanze dei collegamenti della redazione.
- **Calcolo al momento della richiesta** (oc:8959, scartato): lo scrum del 29/10 ha deciso che il dato
  si salva.
