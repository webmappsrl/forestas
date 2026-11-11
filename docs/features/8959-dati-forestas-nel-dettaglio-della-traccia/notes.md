> Ticket: oc:8959

# Notes — dati di Forestas nel dettaglio della traccia

Secondo pezzo di oc:8959 lato forestas. Il primo (tassonomie, `LABELS`) è nel package:
`wm-package/docs/features/8959-api-del-dettaglio-tracce-e-poi-per-wordpress-e-frontend-forestas/`.
Il formato è descritto in `docs/knowledge/dettaglio-della-traccia-dati-forestas.md`.

## Decisioni (col dev, 2026-11-11)

- **Perimetro**: registro e inclusività dentro `forestas`, allegati e video con l'indirizzo dello
  shard, crediti delle foto (`image_credits`), enti con i ruoli (`entities`; l'API degli enti la fa
  Rubens), sentieri vicini (`nearby_tracks`), link per scaricare (`downloads`), pulizia delle chiavi
  interne e degli id di Drupal (anche `properties.sardegnasentieri_id`). Fuori: dati tecnici
  (`manual_data`/DEM, oc:8641), POI vicini e POI.
- **Crediti delle foto in `forestas.image_credits`**: `MediaResource` del package riscrive
  `feature_image` e `image_gallery` con campi fissi; il frontend li abbina per id del media.
- **Forma di `entities`, `nearby_tracks`, `downloads`**: approvata dal dev così com'è «per ora».
- **Roadbook**: resta com'è; diventerà un blocco del dettaglio con l'import, in un pezzo successivo
  (scrum del 2026-11-10).
- **`url`**: resta per ora (pagina Drupal del sentiero); il dev deciderà se toglierlo.
- **In uscita, non nel DB**: il `properties.forestas` salvato resta com'è.
- **Chiavi tutte in inglese**: `info_utili` → `useful_info`, `allegati` → `attachments`, `video` e
  i video caricati → `videos` (stessa forma `{url, title}` degli allegati). L'artifact di analisi
  usava i nomi italiani, ma nessun consumatore li legge ancora.
- **Nessuna modifica al wm-package**, su richiesta del dev.

## `EC_TRACK_MODEL`

Su locale, dev e UAT era `Wm\WmPackage\Models\EcTrack`: il file su AWS lo scriveva il modello del
package e l'override di forestas non sarebbe stato chiamato (già oggi `geojsonFlatProperties()` non
arrivava nel file). Il default del package è `App\Models\EcTrack`, ed è quello di camminiditalia,
maphub e carg (verificato via SSH, sola lettura). In locale la riga è commentata (copia del `.env` in
scratchpad della sessione). **Su dev e UAT va tolta al deploy**, poi `config:clear` e riavvio di
Horizon.

Conseguenze verificate in locale:
- relazioni già salvate come `App\Models\EcTrack` (media 3.648, termini 3.487, attività 1.824,
  layer 1.574, enti 1.330): nessuna migrazione;
- l'indice riceve `inclusivity_score`/`inclusivity_class` (mapping dinamico);
- **legami traccia-layer**: `LayerableObserver` ora riconosce le tracce (prima il confronto con il
  modello del package era sempre falso) e `PBFGeneratorService::regeneratePbfsForLayer()` trova le
  tracce del layer invece di rigenerare i PBF di tutta l'app. Un detach+attach accoda 1
  `UpdateLayerGeometryJob` (unico) e 1 `GenerateOptimizedPBFChainJob` per legame, non unico:
  salvare in Nova un layer automatico con molte tracce accoda una catena PBF per traccia, come su
  camminiditalia. L'import notturno scrive i legami senza eventi e non è toccato. Rischio noto,
  non corretto qui (sarebbe nel package);
- `$fillable` di forestas al posto di quello del package: nessuno scrive `access_*`/`accessibility_*`
  con assegnazione di massa.

## Deviazioni e altro

- `geojsonFlatProperties()` tolto: i dati sono in `forestas` e nessuno leggeva le chiavi piatte.
  `ForestasColumnsTest` aggiornato.
- `tests/TestCase.php`: `Bus::fake` di `UpdateEcTrackAwsJob`, `UpdateAppConfigJob`,
  `BuildAppPoisGeojsonJob`, `RegenerateTaxonomyWhereOutputsJob` (seguito del bump del package con
  oc:8959: quattro test fallivano scrivendo verso S3).
- GPX e KML di `downloads` rispondono 500 finché oc:8953 (correzione delle rotte) non è in develop;
  GeoJSON risponde 200.
- I titoli di allegati e video sono quelli salvati dall'import in `sardegnasentieri_title`: negli
  esempi locali sono il nome del file.
- Le API di ripiego del package (`/api/ec/track/{id}` senza file, `multiple`) usano la classe del
  package e portano il `forestas` salvato.

## Follow-up

- Togliere `EC_TRACK_MODEL` da `.env` di dev e UAT al deploy; rigenerare file e indice.
- Pezzi successivi: dati tecnici, POI vicini, POI da `pois.geojson`, roadbook come blocchi.
- Decidere se togliere `url` (pagina Drupal del sentiero).
