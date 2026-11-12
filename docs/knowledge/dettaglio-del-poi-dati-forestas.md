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
`out_source_feature_id` (id del nodo Drupal) e `layers`.

```json
"forestas": {
  "url": "https://www.sardegnasentieri.it/da-vedere/nuraghe-serbissi-osini",
  "code": "C-521A Nuraghe", "created_at": "…", "updated_at": "…",
  "directions": {"it": "…"},
  "videos": [{"url": "…", "title": null}], "attachments": [{"url": "…", "title": "…"}],
  "organizations": {"operator": [{"id": 31, "slug": "…", "name": {…}}]},
  "related_pois": [{"id": 168, "name": {…}, "feature_image": {…}, "addr_complete": "Gairo (OG)"}],
  "nearby_tracks": [{"id": 609, "ref": null, "name": {…}, "route_type": {…}, "feature_image": {…},
                     "distance": null, "duration_forward": null, "distance_from_poi": 28.2}],
  "nearby_tracks_count": 7
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

Le chiavi vuote non escono. Il `properties.forestas` salvato non cambia.

**`ec_poi_ec_track` si calcola per vicinanza.** `CalculatePoiTracksChunkJob`, nel batch di
`CalculateNearbyTracksJob` a fine import, scrive le tracce entro
`sardegnasentieri.poi_tracks_radius_meters` (1 km) da ogni POI, con `order` uguale alla distanza in
metri. Ogni blocco riscrive solo i suoi POI in una transazione, perché il dettaglio legge la tabella dal
vivo; senza eventi, perché l'observer del pivot del package (`EcPoiEcTrackObserver`) aggiungerebbe i POI
ai layer delle tracce, mentre per questa app i POI dei layer li decide
`RecalculateSardegnaSentieriLayersJob`. La tabella è la stessa riga vista dai due lati: dà anche i
`related_pois` del dettaglio delle tracce, che quindi sono i POI entro 1 km, dal più vicino.

## Perché così

- **Rotta sostituita in forestas, non modifica al package** (oc:8959): il dev non vuole toccare il
  package per i dati di un solo shard; `ec_track_model` esiste per osm2cai, dove la traccia ha un altro
  modello, e non va replicata per i POI.
- **Percorsi del POI calcolati, come i sentieri correlati** (oc:8959, 12/11): correlati e vicini sono
  la stessa cosa. Raggio 1 km per ora (decisione del dev): 4.283 collegamenti in locale, 5,6 per
  traccia; 300 m darebbe la quantità di Drupal (2.452, 3,2 per traccia), 5 km 14.018.
- **Al massimo 10, con il conteggio a parte** (oc:8959): come la tavola 7 del wireframe.
- **POI correlati fra loro ancora da Drupal** (oc:8959): «per ora», nessun raggio deciso.

## Come ci siamo arrivati

- **Tabella nuova `ec_poi_nearby_tracks`** (oc:8959, scartata dal dev il 12/11).
- **Percorsi del POI = collegamenti di Drupal** (oc:8959, superata lo stesso giorno): calcolati per
  vicinanza dopo aver visto le distanze dei collegamenti della redazione.
- **Calcolo al momento della richiesta** (oc:8959, scartato): lo scrum del 29/10 ha deciso che il dato
  si salva.
