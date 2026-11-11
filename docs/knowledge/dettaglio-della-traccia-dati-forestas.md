# Dettaglio della traccia: dati di Forestas

## Come funziona oggi

Il dettaglio pubblico della traccia (file `tracks/{id}.json` su AWS, che il WordPress legge) ha in
`properties.forestas` un oggetto costruito in uscita da `App\Support\ForestasTrackDetail`, chiamato
da `App\Models\EcTrack::getDetailGeojson()`. Le tassonomie stanno invece in
`properties.taxonomies` e le loro etichette in `LABELS` del `config.json`: sono del package, vedi
`wm-package/docs/knowledge/tassonomie-nel-dettaglio-di-tracce-e-poi.md`.

```json
"forestas": {
  "url": "https://www.sardegnasentieri.it/sentieri/salita-sul-monte-corrasi-b-401",
  "created_at": "2013-07-02 18:12:40",
  "updated_at": "2024-03-20 15:58:28",
  "useful_info": {"it": ["…"], "en": ["…"]},
  "roadbook": {"it": ["…"], "en": ["…"]},
  "videos": [
    {"url": "https://www.youtube.com/watch?v=…", "title": null},
    {"url": "https://…/media/74822/video.mp4", "title": "Il percorso"}
  ],
  "attachments": [{"url": "https://…/media/74275/carta.pdf", "title": "Carta percorsi Baratz (anno 2012)"}],
  "registry_code": "Z-NU-B-401",
  "survey_date": "2022-08-04",
  "inclusivity": {"physical": 40, "aids": 30, "digital": 20, "score": 90, "class": "A1",
                  "independent_use": "…", "assistance": "…"},
  "image_credits": {"69506": {"caption": "Vista dall'alto…", "author": "Matteo Cara", "credits": "Comunità Montana…"}},
  "entities": [{"id": 29, "name": {"it": "Comunità Montana…"}, "roles": ["gestore", "manutentore", "rilevatore"]}],
  "nearby_tracks": [{"id": 325, "ref": "Z-NU-T-431B", "name": {"it": "Strada Zaccarè - Pardoniga (T 431B)"}}],
  "downloads": {"geojson": "https://…/api/ec/track/download/326.geojson",
                "gpx": "https://…/api/ec/track/download/326.gpx",
                "kml": "https://…/api/ec/track/download/326.kml"}
}
```

| Chiave | Fonte |
|---|---|
| `url`, `created_at`, `updated_at` | `properties.forestas` salvato dall'import (pagina e date di Drupal) |
| `useful_info`, `roadbook` | `properties.forestas.info_utili` e `roadbook` (traducibili, lista di testi per lingua) |
| `videos` | `properties.forestas.video` (YouTube, `title` nullo) e la collection `videos` della media library |
| `attachments` | collection `attachments` della media library; `title` = `custom_properties.sardegnasentieri_title`, `null` se manca |
| `registry_code`, `survey_date` | colonne `forestas_registry_code`, `forestas_survey_date` |
| `inclusivity` | colonne `forestas_inclusivity_*`, punteggio e classe da `InclusivityScore` (solo con tutti e tre i valori), `independent_use` e `assistance` scritti a mano in Nova |
| `image_credits` | `custom_properties.sardegnasentieri_caption`, `_autore`, `_credits` dei media della traccia, per id del media; solo i campi valorizzati, solo le foto che ne hanno |
| `entities` | relazione `enti()` (`enteables`): un elemento per ente, con tutti i suoi `ruolo` in `roles`; il resto della scheda dell'ente lo dà l'API degli enti |
| `nearby_tracks` | relazione `relatedTracks()` (`ec_track_related_tracks`, scritta da `CalculateNearbyTracksJob`): `id`, `properties.ref`, nome; la distanza non è salvata |
| `downloads` | rotte `default.api.ec.track.download.geojson`, `.gpx`, `.kml` dello shard |

Le chiavi vuote non escono; `downloads` c'è sempre, quindi una traccia senza altri dati ha solo
quella. Allegati, video e link per scaricare hanno l'indirizzo dello shard, non di Drupal. Dal
dettaglio è tolto anche `properties.sardegnasentieri_id`, l'id del nodo Drupal: resta nel DB per
import e Nova. **Il `properties.forestas` salvato nel DB non cambia**: lo usano import,
Nova e `ResolveSardegnaSentieriRelationsJob` (`source_relations`).

**Funziona solo con `EC_TRACK_MODEL` sul modello di forestas.** Il file su AWS lo scrive
`UpdateEcTrackAwsJob` del package, che ricarica la traccia con `config('wm-package.ec_track_model')`:
se punta a `Wm\WmPackage\Models\EcTrack`, l'override di forestas non viene mai chiamato. Il default
del package è `App\Models\EcTrack`, come su camminiditalia e maphub; la regola è in
`.claude/rules/deploy-e-configurazione.md`. Le due API di ripiego del package
(`/api/ec/track/{id}` quando il file manca, `multiple`) usano la classe del package e portano il
`forestas` salvato.

## Perché così

- **In uscita, non nel DB** (oc:8959): import, Nova e job leggono le chiavi salvate; il file
  pubblico deve avere solo ciò che il frontend usa.
- **Dentro `forestas`, non piatte** (oc:8959): «le tassonomie le deve fare il VM package, tutto il
  resto lo deve fare Forestas», con la parte custom «dove ci va dentro Forestas tutta la roba di
  Forestas» (Giuseppe, scrum del 2026-11-10). Le chiavi piatte `forestas_*` non sono mai state
  discusse in call.
- **Crediti delle foto in `forestas.image_credits`, non nelle foto** (oc:8959): `feature_image` e
  `image_gallery` li riscrive `MediaResource` del package con campi fissi; il frontend li abbina
  per id del media.
- **Chiavi in inglese** (oc:8959): come il resto del contratto (`taxonomies`, `LABELS`); nessun
  consumatore leggeva ancora le chiavi italiane.
- **Via gli id di Drupal e le chiavi interne** (oc:8959, scrum del 2026-11-11): `source_id`,
  `source_relations`, `zona_geografica`, `gpx` (il file Drupal, che sparirà: il GPX lo genera lo
  shard, oc:8953), `skip_dem_jobs`, `classification`, `anomalies`, `enti_mancanti`,
  `deleted_from_source`; fuori da `forestas`, `sardegnasentieri_id`.
- **I POI vicini non ci sono** (oc:8959): i POI del dettaglio sono un pezzo a parte, dai dati di
  `pois.geojson`.
- **`EC_TRACK_MODEL` al modello di forestas** (oc:8959): è il default del package e degli altri
  shard; con il modello del package anche `geojsonFlatProperties()` e `toSearchableArray()` di
  forestas non arrivavano mai a file e indice.

## Come ci siamo arrivati

- **Colonne piatte in `properties` con `geojsonFlatProperties()`** (oc:8910, superata con oc:8959):
  non comparivano nel file su AWS, perché il job usava il modello del package, e nessun frontend le
  leggeva.
- **Aggancio configurabile nel package** (oc:8959, scartato): sarebbe servita una modifica a
  wm-package per un bisogno di un solo shard.
- **Correggere il file dopo che il package l'ha scritto** (oc:8959, scartato): doppia scrittura per
  traccia e file grezzo in silenzio se l'ascoltatore fallisce.
