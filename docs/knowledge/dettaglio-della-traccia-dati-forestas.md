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
  "videos": [
    {"url": "https://www.youtube.com/watch?v=…", "title": null},
    {"url": "https://…/media/74822/video.mp4", "title": "Il percorso"}
  ],
  "attachments": [{"url": "https://…/media/74275/carta.pdf", "title": "Carta percorsi Baratz (anno 2012)"}],
  "registry_code": "Z-NU-B-401",
  "survey_date": "2022-08-04",
  "inclusivity": {"physical": 40, "aids": 30, "digital": 20, "score": 90, "class": "A1",
                  "independent_use": "…", "assistance": "…"},
  "organizations": {"manager": [{"id": 29, "slug": "comunita-montana-…", "name": {"it": "Comunità Montana…", "en": "…"}}],
                    "maintainer": [{"id": 29, "name": {…}}, {"id": 17, "name": {…}}],
                    "surveyor": [{"id": 29, "name": {…}}]},
  "nearby_tracks": [{"id": 48, "ref": "Z-NU-B-405", "name": {"it": "Scala 'e Pradu - Gasole (B 405)"},
                     "route_type": {"identifier": "route-type-trail", "name": {"it": "Sentiero"}},
                     "feature_image": {"id": 76228, "url": "…", "thumbnail": "…", "sizes": {…}, "caption": null, …},
                     "distance": null, "duration_forward": null}],
  "downloads": {"geojson": "https://…/api/ec/track/download/326.geojson",
                "gpx": "https://…/api/ec/track/download/326.gpx",
                "kml": "https://…/api/ec/track/download/326.kml"}
}
```

| Chiave | Fonte |
|---|---|
| `url`, `created_at`, `updated_at` | `properties.forestas` salvato dall'import (pagina e date di Drupal) |
| `useful_info` | `properties.forestas.info_utili` (traducibile, lista di testi per lingua) |
| `videos` | `properties.forestas.video` (YouTube, `title` nullo) e la collection `videos` della media library |
| `attachments` | collection `attachments` della media library; `title` = `custom_properties.sardegnasentieri_title`, `null` se manca |
| `registry_code`, `survey_date` | colonne `forestas_registry_code`, `forestas_survey_date` |
| `inclusivity` | colonne `forestas_inclusivity_*`, punteggio e classe da `InclusivityScore` (solo con tutti e tre i valori), `independent_use` e `assistance` scritti a mano in Nova |
| `organizations` | relazione `enti()` (`enteables`), raggruppata per `ruolo`: `manager` (gestore), `maintainer` (manutentore), `surveyor` (rilevatore), `operator` (operatore), `forest_complex` (complesso forestale). Ogni ente ha `id`, `slug` (l'indirizzo della scheda, `/enti/<slug>`) e `name` in tutte le lingue, e compare in ogni gruppo in cui ha un ruolo; i gruppi vuoti non escono, un ruolo non previsto esce con il nome del DB. Il resto della scheda dell'ente lo dà l'API degli enti, che i ruoli non li ha perché dipendono dalla traccia. La chiave non è `entities` perché sono enti (comuni, complessi forestali, istituzioni, partner, associazioni), non «entità» |
| `nearby_tracks` | i «Sentieri correlati» della scheda: relazione `relatedTracks()` (`ec_track_related_tracks`, scritta da `CalculateNearbyTracksJob`). Ogni elemento ha quello che mostra la card del carosello: `id`, `ref` (`properties.ref`), nome, `route_type` (termine di «Tipo percorso»), `feature_image` (la prima foto, stessi campi di quella del dettaglio), `distance` e `duration_forward` con la precedenza manuale > OSM > DEM del dettaglio. Un dato assente è `null`; la distanza fra i due sentieri non è salvata |
| `downloads` | rotte `default.api.ec.track.download.geojson`, `.gpx`, `.kml` dello shard |

**Le card dei correlati si aggiornano da sole quando cambia la traccia che mostrano**, fuori
dall'import: `RefreshRelatedTrackCardsJob` riscrive i file delle sole tracce che hanno quella
cambiata fra i correlati (`ec_track_related_tracks`). Parte solo se cambia un dato della card:
- nome, `ref`, lunghezza o tempo (`RelatedTrackCardObserver`, confronto prima/dopo con
  `RelatedTrackCards::signature()`), e alla cancellazione, prima che le righe spariscano in cascata;
- la prima foto della collection `default` o i suoi crediti (`RelatedTrackMediaObserver`); le
  altre foto no;
- un termine di «Tipo percorso» attaccato o tolto (`RelatedTrackRouteTypeObserver`).

Il job è unico per traccia finché non parte: un salvataggio in Nova che cambia più cose ne accoda
uno. L'import non lo accoda: salva le tracce senza eventi, scarica le foto dentro
`RelatedTrackCards::withoutRefresh()` e a fine giro rigenera tutti i file.

I `related_pois` del dettaglio (chiave del package, da `ec_poi_ec_track`) sono i POI entro 1 km dalla
traccia, dal più vicino, calcolati a fine import: vedi `dettaglio-del-poi-dati-forestas.md`.

**Il roadbook sta nei Blocchi Dettaglio**, non in `forestas` (oc:8959): `properties.config_detail` (campo
Nova «Detail Blocks» del package) ha un blocco `info` con una riga per tappa, titolo «Tappa N» e il
paragrafo di Drupal come testo, come lo mostra sardegnasentieri.it. Lo scrive l'import
(`App\Support\RoadbookBlock`) dai paragrafi propri di ogni lingua (`roadbook_stages` della fonte): titolo
e testo di una tappa escono solo nelle lingue in cui ha un testo. È uno specchio di Drupal: l'import lo
riscrive quando rilegge la traccia, gli altri blocchi aggiunti in Nova restano. Il blocco si riconosce
dai titoli («Tappa N» o la traduzione), perché il package al salvataggio in Nova tiene solo `box_type` e
`items`; un blocco creato a mano con righe «Tappa N» sarebbe preso per il roadbook. Esce nel file su AWS
come `config_detail`.

Didascalia, autore e credits delle foto non stanno in `forestas`: escono con le foto
(`feature_image`, `image_gallery`), dal package (`wm-package/docs/knowledge/media-e-avatar.md`).

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
- **Crediti delle foto con le foto, dal package** (scrum del 2026-11-11, 13:54): «image credits
  sparisce e viene assorbita da feature image e image gallery», nel package perché «non è una cosa
  custom». L'import scrive `caption`, `author`, `credits` nelle custom properties del media.
- **Enti solo con id e ruoli** (scrum del 2026-11-11, 13:54): il resto lo dà l'API degli enti, non
  pubblica perché ci sono dati personali; i ruoli restano qui perché dipendono dalla traccia.
- **I sentieri vicini sono i sentieri correlati** (scrum del 2026-11-11, 13:54): su Drupal i
  correlati si inserivano a mano, e il calcolo per vicinanza dà quasi lo stesso risultato. Ogni
  elemento porta i dati della card del carosello del wireframe («Sarcerei – Lanusei (C 104)»,
  «3 ore 45 minuti · 11 km 300 m»). Tempo e lunghezza sono `null` sui sentieri importati finché non
  gira il DEM (oc:8641).
- **File rigenerati a fine calcolo dei correlati** (oc:8959): `CalculateNearbyTracksJob` accoda i
  blocchi (`CalculateNearbyTracksChunkJob`) in un batch e accoda `RegenerateTaxonomyWhereOutputsJob`
  nel `finally`. Prima la catena dell'import rigenerava i file dopo il primo blocco, e le tracce dei
  blocchi seguenti uscivano senza correlati fino al giro dopo. Il batch chiude giusto solo se
  `retry_after` della coda redis è più lungo del timeout dei job (`config/queue.php`, 660 s): con
  90 s un blocco lento veniva preso due volte e contato due volte, e il batch chiudeva prima che
  l'ultimo blocco scrivesse.
- **Chiavi in inglese** (oc:8959): come il resto del contratto (`taxonomies`, `LABELS`); nessun
  consumatore leggeva ancora le chiavi italiane.
- **Via gli id di Drupal e le chiavi interne** (oc:8959, scrum del 2026-11-11): `source_id`,
  `source_relations`, `zona_geografica`, `gpx` (il file Drupal, che sparirà: il GPX lo genera lo
  shard, oc:8953), `skip_dem_jobs`, `classificazioni_anomale`, `enti_mancanti`,
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
- **`forestas.image_credits`, mappa per id del media** (oc:8959, superata): nel file su AWS diventava
  una lista senza id, perché `JsonResource::filter()` rinumera gli array con chiavi tutte numeriche;
  e allo scrum del 2026-11-11 si è deciso di portare i crediti con le foto.
- **Enti con id, nome e ruoli** (oc:8959, superata): il nome lo dà l'API degli enti.
- **`forestas.roadbook`, lista di paragrafi per lingua** (oc:8959, superata): tolto da DB, dettaglio e
  Nova; il roadbook è nei Blocchi Dettaglio (scrum del 2026-11-10). La lista ripeteva in ogni lingua i
  paragrafi delle precedenti, come le vecchie API.
