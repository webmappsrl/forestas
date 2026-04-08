# Design: Sardegna Sentieri — Field Mapping Completo

**Data:** 2026-04-02 (aggiornato 2026-04-08 — revisione umana applicata + implementazione Tasks 1-5 completata)
**Scope:** Mapping completo di tutti i campi importati da sardegnasentieri.it verso forestas (787 track, 926 POI campionati deterministicamente).

---

## Regola generale di mapping

- Se il campo ha un corrispettivo **nativo nel wm-package** → mappato al campo nativo
- Se il campo **non ha corrispondenza** nel wm-package → va in `properties->forestas->*`
- I campi `properties` primo livello sono riservati a campi wm-package (es. `description`, `excerpt`)
- Campi translatable multi-livello (es. `roadbook`) non supportati da Spatie oltre un livello → storati come JSON grezzo in `properties->forestas->*`

Legenda colonna **Tipo**:

- `wm-package` — campo o relazione gestita dal wm-package
- `forestas` — estensione specifica di forestas

---

## Mapping TRACK — tutti i campi


| Campo sorgente (API sardegnasentieri)      | Count | Tipo       | Destinazione                                                           | Note                                                                                                                                                             | Verifica (ID locale)                                                                                  |
| ------------------------------------------ | ----- | ---------- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `properties.id`                            | 787   | forestas   | `properties->sardegnasentieri_id`                                      | ID esterno univoco per sync incrementale. Readonly in Nova.                                                                                                      | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "Sardegna Sentieri ID" = `2611` |
| `properties.name`                          | 787   | wm-package | `name` translatable                                                    | Campo nativo wm-package.                                                                                                                                         | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.description`                   | 787   | wm-package | `properties->description` translatable                                 | Campo nativo wm-package.                                                                                                                                         | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.excerpt`                       | 787   | wm-package | `properties->excerpt` translatable                                     | Campo nativo wm-package.                                                                                                                                         | EcTrack *Chiesa de La Solitudine - Redentore*                                                         |
| `properties.lunghezza`                     | 787   | wm-package | `manual_data->distance`                                                | ManualTrackData wm-package.                                                                                                                                      | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.dislivello_totale`             | 787   | wm-package | `manual_data->ascent`                                                  | Vedi sezione **Dubbi aperti** [D1].                                                                                                                              | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.durata`                        | 787   | wm-package | `manual_data->duration_forward` + `duration_backward`                  | Un solo campo API, applicato ad entrambe le direzioni.                                                                                                           | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.ele_min`                       | 620   | wm-package | `manual_data->ele_min`                                                 | Le colonne native `ele_min/max` su EcTrack sono riservate al DEM; i valori sardegnasentieri vanno in `manual_data`.                                              | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.ele_max`                       | 620   | wm-package | `manual_data->ele_max`                                                 | Stesso ragionamento di `ele_min`.                                                                                                                                | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.type`                          | 787   | wm-package | `TaxonomyActivity`                                                     | L'import crea/assegna un termine Activity per ogni valore: `sentiero` (621 track) e `itinerario` (166 track). `properties->forestas->type` rimosso.              | TaxonomyActivity ID **47** (`itinerario`), **48** (`sentiero`)                                        |
| `properties.immagine_principale`           | 787   | wm-package | EcMedia collection `default` via `ImportSardegnaSentieriTrackMediaJob` | Campi API: `url`, `autore`, `credits`. Il Job viene dispatchato da `ImportSardegnaSentieriTrackJob` con manifest ordinato (principale + galleria, no duplicati). | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → Media                                          |
| `properties.galleria_immagini`             | ~300  | wm-package | EcMedia collection `default` via `ImportSardegnaSentieriTrackMediaJob` | Stesso manifest di `immagine_principale`.                                                                                                                        | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → Media                                          |
| `properties.created_at`                    | 787   | forestas   | `properties->forestas->created_at`                                     | Data di creazione del record su Drupal. Readonly in Nova.                                                                                                        | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "Creato il"                     |
| `properties.updated_at`                    | 787   | forestas   | `properties->forestas->updated_at`                                     | Usato per sync incrementale. Readonly in Nova.                                                                                                                   | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "Aggiornato il"                 |
| `properties.data_rilievo`                  | ~400  | forestas   | `properties->forestas->data_rilievo`                                   | Data del rilievo sul campo.                                                                                                                                      | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.url`                           | 787   | forestas   | `properties->forestas->url`                                            | URL vecchio sardegnasentieri (classi: `/sentiero/{slug}`, `/itinerario/{slug}`, `/node/{nid}`, ecc.). Utile per redirect 301 futuri. Readonly in Nova.           | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "URL"                           |
| `properties.codice`                        | ~400  | forestas   | `properties->forestas->codice`                                         | Codice interno sardegnasentieri.                                                                                                                                 | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.codice_cai`                    | ~200  | wm-package | `properties->ref`                                                      | Codice CAI = campo nativo wm-package `ref`.                                                                                                                      | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → campo "Ref" = `Z-SS-G-503-E`                   |
| `properties.info_utili`                    | 413   | forestas   | `properties->forestas->info_utili`                                     | JSON grezzo `{it: [HTML], en: [HTML]}` — Spatie non supporta nesting >1 livello, impossibile usare campo Translatable standard. Visualizzato come JSON in Nova.  | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "Info utili"                    |
| `properties.roadbook`                      | 317   | forestas   | `properties->forestas->roadbook`                                       | Stesso schema di `info_utili` — visualizzato come JSON in Nova.                                                                                                  | EcTrack *Farcana - Janna Bentosa (G 125)* → tab Forestas → "Roadbook"                                 |
| `properties.allegati`                      | ~200  | forestas   | `properties->forestas->allegati`                                       | Array di URL PDF/documenti, storato come array associativo `indice→URL`. **TODO:** valutare migrazione URL su storage interno (shard) — da sviluppare.           | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.video`                         | ~100  | forestas   | `properties->forestas->video`                                          | Array di URL YouTube (solo 7 track valorizzati, tutti YouTube). Campo URL, non upload.                                                                           | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)*                                                  |
| `properties.gpx`                           | 785   | forestas   | `properties->forestas->gpx` + geometry EcTrack                         | L'URL GPX viene scaricato, parsato e convertito in `MULTILINESTRING Z`. Il file non viene conservato permanentemente.                                            | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → geometry + tab Forestas → "GPX"                |
| `properties.partenza`                      | 787   | wm-package | `from` su EcTrack + relazione `ecPois()`                               | ID sardegnasentieri del POI di partenza. Risolto al nome del POI per `from`.                                                                                     | EcTrack *Perdas Artas (T 501A)* → campo "From" = `Bacu Perdas Artas (Punto di partenza T-501A)`       |
| `properties.arrivo`                        | 787   | wm-package | `to` su EcTrack + relazione `ecPois()`                                 | ID sardegnasentieri del POI di arrivo. Risolto al nome del POI per `to`.                                                                                         | EcTrack *Perdas Artas (T 501A)* → campo "To"                                                          |
| `properties.poi_correlati`                 | 344   | wm-package | `ecPois()->sync()`                                                     | Ordine sync: partenza, poi_correlati[], arrivo. POI non ancora importati vengono ignorati e collegati al prossimo sync.                                          | EcTrack *Perdas Artas (T 501A)* → relazione EcPoi → 3 POI                                             |
| `properties.ente_istituzione_societa`      | 581   | forestas   | → modello `Ente` (vedere sezione dedicata)                             | Contiene `soggetto_gestore`, `soggetto_manutentore`, `soggetto_rilevatore`, `riferimento_operatori_guid[]`.                                                      | EcTrack *Chiesa de La Solitudine - Redentore (G 101)* → `enteables`                                   |
| `properties.complesso_forestale`           | 100   | forestas   | → modello `Ente` con ruolo `complesso_forestale`                       | Nodo Drupal ente (es. node/100 = "Complesso forestale Marganai").                                                                                                | EcTrack *Chiesa de La Solitudine - Redentore (G 101)* → `enteables` ruolo `complesso_forestale`       |
| `taxonomies.tipologia_sentieri`            | 787   | wm-package | `TaxonomyActivity`                                                     | Tassonomia principale tipo percorso.                                                                                                                             | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → TaxonomyActivity                               |
| `taxonomies.categorie_fruibilita_sentieri` | 787   | —          | **Non importato**                                                      | Dovrebbe mappare con il concetto di difficoltà — in attesa di indicazioni. Vedi sezione **Dubbi aperti** [D2].                                                   | —                                                                                                     |
| `taxonomies.stato_di_validazione`          | 787   | wm-package | `stato_validazione` su EcTrack                                         | Enum `StatoValidazione`.                                                                                                                                         | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → stato = `in_revisione_validazione`             |
| `taxonomies.tipologia_di_avvertenze`       | 207   | forestas   | `TaxonomyWarning` (forestas)                                           | 12 tipi (fulmini, incendi, neve, valanghe, cani aggressivi, ecc.).                                                                                               | EcTrack *Sentiero del lago del basso Flumendosa (Orroli) - C 611* → TaxonomyWarning → 5 warning       |
| `taxonomies.zona_geografica`               | ~600  | wm-package | `TaxonomyWhere` con geometrie                                          | Da implementare: import termini con geometrie nel DB. Attualmente storato raw in `forestas->zona_geografica`.                                                    | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → tab Forestas → "Zona geografica"               |
| geometry (GPX)                             | 785   | wm-package | geometry `MULTILINESTRING Z` su EcTrack                                | Source primaria.                                                                                                                                                 | EcTrack *S'Ena e Lottori - Sentiero G 504 (G 503 E)* → mappa                                          |
| geometry API (fallback)                    | 2     | wm-package | geometry `MULTILINESTRING Z` su EcTrack                                | Usato solo se GPX assente (2 track su 787).                                                                                                                      | —                                                                                                     |


---

## Mapping POI — tutti i campi


| Campo sorgente (API sardegnasentieri) | Count | Tipo       | Destinazione                                                         | Note                                                                                                                                                     | Verifica (ID locale)                                                                          |
| ------------------------------------- | ----- | ---------- | -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `properties.id`                       | 926   | wm-package | `properties->out_source_feature_id`                                  | ID esterno univoco. Readonly in Nova.                                                                                                                    | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Sardegna Sentieri ID" = `5301`              |
| `properties.name`                     | 926   | wm-package | `name` translatable                                                  | Campo nativo wm-package.                                                                                                                                 | EcPoi *Fonte sacra Su Tempiesu*                                                               |
| `properties.description`              | 926   | wm-package | `properties->description` translatable                               | Campo nativo wm-package.                                                                                                                                 | EcPoi *Fonte sacra Su Tempiesu*                                                               |
| `properties.immagine_principale`      | 926   | wm-package | EcMedia collection `default` via `ImportSardegnaSentieriPoiMediaJob` | Il Job viene dispatchato da `ImportSardegnaSentieriPoiJob` con manifest ordinato.                                                                        | EcPoi *Fonte sacra Su Tempiesu* → Media                                                       |
| `properties.galleria`                 | ~300  | wm-package | EcMedia collection `default` via `ImportSardegnaSentieriPoiMediaJob` | Stesso manifest di `immagine_principale`.                                                                                                                | EcPoi *Fonte sacra Su Tempiesu* → Media                                                       |
| `properties.addr_locality`            | ~500  | wm-package | `addr_complete` via `EcPoiPropertiesData`                            | Campo nativo wm-package.                                                                                                                                 | EcPoi *Fonte sacra Su Tempiesu*                                                               |
| `properties.codice`                   | ~300  | forestas   | `properties->forestas->codice`                                       | Da approfondire con cliente — vedi **Dubbi aperti** [D3].                                                                                                | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Codice"                                     |
| `properties.collegamenti`             | ~200  | forestas   | `properties->forestas->collegamenti`                                 | Lista di URL associati al POI. Array associativo `indice→URL`.                                                                                           | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Collegamenti"                               |
| `properties.come_arrivare`            | 298   | forestas   | `properties->forestas->come_arrivare`                                | JSON grezzo `{it: [HTML], en: [HTML]}` — stessa limitazione Spatie di `info_utili`/`roadbook`. Visualizzato come JSON in Nova.                           | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Come arrivare"                              |
| `properties.url`                      | 926   | forestas   | `properties->forestas->url`                                          | URL vecchio sardegnasentieri (`/da-vedere/{slug}` o `/node/{nid}`). Utile per redirect 301 futuri.                                                       | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "URL"                                        |
| `properties.updated_at`               | 926   | forestas   | `properties->forestas->updated_at`                                   | Ultimo aggiornamento su Drupal.                                                                                                                          | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Aggiornato il"                              |
| `properties.allegati`                 | ~200  | forestas   | `properties->forestas->allegati`                                     | Stesso schema track. **TODO:** valutare migrazione su shard.                                                                                             | EcPoi *Fonte sacra Su Tempiesu*                                                               |
| `properties.video`                    | —     | forestas   | `properties->forestas->video`                                        | Dall'analisi: nessun POI ha valori validi. Non valorizzato.                                                                                              | —                                                                                             |
| `properties.poi_correlati`            | 229   | forestas   | relazione custom POI→POI via `ec_poi_related_pois`                   | `EcPoi` locale estende `WmEcPoi` con relazione `relatedPois()` (BelongsToMany self-referencing). Sincronizzata da `syncRelatedPoisForPoi()` nel service. | Tabella `ec_poi_related_pois` creata 2026-04-08 — dati popolati al prossimo import            |
| `properties.ente_istituzione_societa` | 33    | forestas   | → modello `Ente` (stessa logica track)                               | Sincronizzato da `syncEntiPoi()` nel service.                                                                                                            | EcPoi *Punto di partenza del sentiero 216 presso la foresta demaniale Pantaleo* → `enteables` |
| `taxonomies.tipologia_poi`            | ~400  | wm-package | `TaxonomyPoiType`                                                    | Via `identifier = geohub_identifier`.                                                                                                                    | EcPoi *Fonte sacra Su Tempiesu* → TaxonomyPoiType                                             |
| `taxonomies.servizi`                  | ~400  | wm-package | `TaxonomyActivity`                                                   | Via `identifier = geohub_identifier`. TaxonomyActivity non è esclusiva delle track.                                                                      | EcPoi *Fonte sacra Su Tempiesu* → TaxonomyActivity                                            |
| `taxonomies.zona_geografica`          | ~600  | wm-package | `TaxonomyWhere` con geometrie                                        | Stesso discorso track — da implementare.                                                                                                                 | EcPoi *Fonte sacra Su Tempiesu* → tab Forestas → "Zona geografica"                            |
| geometry                              | 926   | wm-package | geometry `POINT(lon lat)` su EcPoi                                   | Obbligatoria — import fallisce se assente.                                                                                                               | EcPoi *Fonte sacra Su Tempiesu* → mappa                                                       |


---

## Campi immagine API

L'API sardegnasentieri restituisce per ogni immagine (`immagine_principale` e items di `galleria`/`galleria_immagini`):


| Campo API | Salvato in `custom_properties` | Note                                                                 |
| --------- | ------------------------------ | -------------------------------------------------------------------- |
| `url`     | `sardegnasentieri_source_url`  | Usato come chiave idempotente per evitare duplicati.                 |
| `autore`  | `sardegnasentieri_autore`      |                                                                      |
| `credits` | `sardegnasentieri_credits`     |                                                                      |
| `titolo`  | —                              | **Non fornito dall'API.** Campo vuoto editabile manualmente in Nova. |
| `caption` | —                              | **Non fornito dall'API.** Campo vuoto editabile manualmente in Nova. |


---

## Modello Ente

### Architettura

`Ente extends Model` — **modello standalone** con tabella dedicata `entes`. Gli enti sono organizzazioni (Fo.Re.S.T.A.S., complessi forestali, ecc.), non POI geografici, quindi non estendono `EcPoi`.

Implementa `HasMedia` con `InteractsWithMedia` (spatie/laravel-medialibrary) per le immagini. Ha un accessor `app_id` che restituisce `IMPORT_APP_ID = 1` (richiesto dal `MediaObserver` del wm-package).

### Schema tabella `entes`


| Colonna               | Tipo              | Note                                             |
| --------------------- | ----------------- | ------------------------------------------------ |
| `id`                  | bigint PK         |                                                  |
| `sardegnasentieri_id` | string unique     | ID nodo Drupal                                   |
| `name`                | jsonb             | translatable (`it`, `en`)                        |
| `description`         | jsonb nullable    | translatable WYSIWYG (TipTap)                    |
| `contatti`            | jsonb nullable    | translatable WYSIWYG (TipTap)                    |
| `pagina_web`          | string nullable   |                                                  |
| `tipo_ente`           | string nullable   | slug enum `TipoEnte` (es. `complesso-forestale`) |
| `geometry`            | geometry nullable | punto geografico (PostGIS)                       |
| `properties`          | jsonb nullable    | campi aggiuntivi                                 |
| `timestamps`          |                   |                                                  |


### Dati importati da Drupal `/node/{id}?_format=json`


| Campo Drupal                         | Destinazione                                                                | Verifica (ID locale)                                                                                                                                                     |
| ------------------------------------ | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `title`                              | `name` (translatable `it`+`en`)                                             | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu* → `sardegnasentieri_id = 1108`                                                                                          |
| `field_contatti[0].value`            | `contatti` (translatable jsonb, `it`+`en`)                                  | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu*                                                                                                                         |
| `field_pagina_web[0].uri`            | `pagina_web`                                                                | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu*                                                                                                                         |
| `field_tipo_ente[0].target_id`       | `tipo_ente` — risolto via enum `TipoEnte` (Drupal ID → slug stringa)        | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu* → `tipo_ente = ente-partner`                                                                                            |
| `field_immagine_principale[0].url`   | media library collection `default` (via `SardegnaSentieriMediaSyncService`) | Nessun ente ha foto nell'import corrente (eseguito prima del fix `Model&HasMedia`). Verificare al prossimo import su enti con immagine nel nodo Drupal (es. nodo `102`). |
| `body[0].value`                      | `description` translatable jsonb — HTML stripped via `strip_tags()`         | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu*                                                                                                                         |
| `field_geolocalizzazione[0].lat/lon` | `geometry` (punto PostGIS)                                                  | Ente *Gal BMG: Barbagia Mandrolisai Gennargentu*                                                                                                                         |


**Nota `tipo_ente`:** L'enum `TipoEnte` mappa i Drupal ID 4699–4703 ai casi `complesso-forestale`, `ente-partner`, `altre-pubbliche-istituzioni`, `privato-associazione`, `comune`. Se un ID non è mappato, si tenta la risoluzione via API con fallback a slug stringa e log warning.

### Relazione con Track e POI

Tabella pivot polimorfica `enteables`:


| Campo           | Tipo                          |
| --------------- | ----------------------------- |
| `id`            | bigint PK                     |
| `ente_id`       | FK → entes                    |
| `enteable_id`   | int (polimorfismo)            |
| `enteable_type` | string (`App\Models\EcTrack`) |
| `ruolo`         | string enum                   |


Vincolo unique su `(ente_id, enteable_id, enteable_type, ruolo)`.

**Ruoli:**

- `gestore` — da `soggetto_gestore`
- `manutentore` — da `soggetto_manutentore`
- `rilevatore` — da `soggetto_rilevatore`
- `operatore` — da `riferimento_operatori_guid[]` (può essere multiplo)
- `complesso_forestale` — da `complesso_forestale`

Il modello `Ente` espone relazioni separate per ruolo: `ecTracksGestore()`, `ecTracksManutentore()`, `ecTracksRilevatore()`, `ecTracksOperatore()`, `ecTracksComplessoForestale()` — oltre a `ecTracks()` che restituisce tutti.

---

## TaxonomyWarning

- Modello in **forestas** (non wm-package) — specifico di questo progetto
- Importato da `https://www.sardegnasentieri.it/ss/tassonomia/tipologia_di_avvertenze?_format=json`
- 12 tipi: fulmini, incendi, neve, valanghe, cani aggressivi, ecc.
- Relazione con EcTrack via tabella `taxonomy_warningables` (polimorfica)
- Nova resource registrata nel menu Taxonomies

---

## Note architetturali

### Partenza/arrivo → from/to

I campi `partenza` e `arrivo` sono ID sardegnasentieri di POI. L'import:

1. Risolve l'ID al nome del POI corrispondente (cercando per `sardegnasentieri_id`)
2. Salva il nome in `from` e `to` su EcTrack (campi stringa nativi wm-package)
3. Include il POI nella relazione `ecPois()` (vedi ordine sotto)

### Ordine poi correlati track

`ecPois()->sync()` include nell'ordine:

1. POI `partenza`
2. POI `poi_correlati[]`
3. POI `arrivo`

### Geometry fallback

Se il GPX non è disponibile (2 track su 787), usare la geometry direttamente dalla risposta API (`geometry.coordinates` GeoJSON → `MULTILINESTRING Z`).

---

## Lacune implementative

Funzionalità previste nel design ma non ancora implementate nel codice:


| Lacuna                                             | Impatto                                                                                 |
| -------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `zona_geografica` → TaxonomyWhere non implementato | Storato raw in `forestas->zona_geografica`, non collegato a TaxonomyWhere con geometrie |


**Lacune risolte nei Tasks 1-5 (2026-04-08):**

- `created_at` → ora in `ForestasTrackData` e `properties->forestas->created_at`
- `codice_cai` → ora mappato a `properties->ref` (campo nativo wm-package)
- `type` → ora crea/assegna TaxonomyActivity (`sentiero`/`itinerario`); `forestas->type` rimosso
- `info_utili` e `roadbook` → ora visibili come Textarea JSON in Nova EcTrack tab Forestas
- `poi_correlati` POI → ora implementato con `App\Models\EcPoi` + tabella `ec_poi_related_pois` + `syncRelatedPoisForPoi()`
- `ente_istituzione_societa` per POI → ora implementato con `syncEntiPoi()`

---

## Domande del revisore — revisione 2026-04-08


| Ref     | Domanda del revisore                                                             | Risposta concordata                                                                                                                                                 |
| ------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [b]/[s] | `name` non deve essere modificabile da Nova                                      | Il riferimento è a `sardegnasentieri_id` (non `name`). Va reso readonly in Nova per EcTrack e EcPoi.                                                                |
| [c]     | I track con `type=itinerario` devono essere assegnati a una tassonomia specifica | L'import crea e assegna due termini TaxonomyActivity: `sentiero` e `itinerario`. `forestas->type` rimosso.                                                          |
| [d]/[t] | I campi immagine `titolo` e `caption` richiesti non sono forniti dall'API        | L'API fornisce solo `url`, `autore`, `credits`. `titolo` e `caption` non esistono nella sorgente — saranno campi vuoti editabili manualmente in Nova.               |
| [f]/[g] | `url` e `updated_at` non devono essere modificabili in Nova                      | Confermato: entrambi readonly in Nova.                                                                                                                              |
| [h]     | `difficolta` non è valorizzato in nessuna track                                  | Campo rimosso. La difficoltà è gestita tramite `taxonomies.tipologia_sentieri`.                                                                                     |
| [k]/[l] | `codice` e `codice_cai`: mappare al campo nativo `ref`?                          | `codice_cai` → `properties->ref` (campo nativo wm-package). `codice` → `properties->forestas->codice`.                                                              |
| [m]     | `complesso_forestale` viene gestito come `ente_istituzione_societa`?             | Sì — stesso pattern Ente con ruolo `complesso_forestale`.                                                                                                           |
| [n]     | `categorie_fruibilita_sentieri` mappa con difficoltà                             | Non importato per ora — in attesa di indicazioni sulla gestione difficoltà.                                                                                         |
| [o]     | `allegati`: gestire migrazione URL su shard                                      | In sospeso — da sviluppare.                                                                                                                                         |
| [p]     | `video`: campo upload o URL?                                                     | Campo URL. Dall'analisi: 7 track con video, tutti YouTube. Per i POI nessun valore valido.                                                                          |
| [q]     | `gpx` è già mappato con la geometry?                                             | Sì — il GPX viene scaricato, parsato e salvato come geometry `MULTILINESTRING Z`.                                                                                   |
| [r]     | Campi mancanti dalla tabella POI                                                 | `addr_locality`, `allegati`, `codice`, `collegamenti`, `galleria`, `updated_at`, `url` erano già nel DTO/codice. `galleria` mancava dall'implementazione mediaSync. |
| [v]     | `servizi` (Activity) è legata solo alle track?                                   | No — `taxonomyActivities()` esiste anche su EcPoi. Già implementato.                                                                                                |
| [w]/[x] | `poi_correlati` POI: relazione o TaxonomyPoiType?                                | Verificato via API: sono ID di altri POI. Vanno in una relazione custom POI→POI (non esiste nel wm-package).                                                        |


---

## Dubbi aperti


| Ref  | Dubbio                                                                                                                                                                                                                                                                    |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [D1] | `dislivello_totale` — attualmente mappato a `manual_data->ascent`. Il campo API si chiama `dislivello_totale`: è il dislivello positivo (salita), negativo (discesa) o la somma dei due? Il DEM produce `ascent` e `descent` separati. In attesa di risposta dal cliente. |
| [D2] | `categorie_fruibilita_sentieri` — dovrebbe mappare con la difficoltà del percorso ma mancano indicazioni su come la difficoltà va gestita nel sistema (dipende dall'attività associata). In sospeso.                                                                      |
| [D3] | `codice` POI — da approfondire con il cliente per capire il significato e se esiste un campo nativo più appropriato di `forestas->codice`.                                                                                                                                |


---

## Decisioni architetturali post-design

- **Ente non estende EcPoi** — Gli enti sono organizzazioni senza geometry obbligatoria. Tabella dedicata `entes` più appropriata.
- `**SardegnaSentieriMediaSyncService` generalizzato** — type hint cambiato da `GeometryModel` a `Model&HasMedia` per supportare `Ente` (che estende `Model` direttamente, non `GeometryModel`). Fix applicato il 2026-04-08.
- `**app_id` accessor su `Ente`** — necessario per il `MediaObserver` del wm-package.
- `**from`/`to` su `ec_tracks**` — colonne aggiunte via migrazione.
- `**tipo_ente` come enum stringa** — mapping Drupal ID → slug via `fromDrupalId()`. Fallback a slug stringa con log warning.
- `**description` e `contatti` come jsonb translatable** — `description` importato con `strip_tags()`. `contatti` storato come HTML.
- **Righe multiple in `enteables` per ruolo** — vincolo unique su `(ente_id, enteable_id, enteable_type, ruolo)`. Ogni ruolo esposto come relazione separata su `Ente`.
- `**codice_cai` → `properties->ref`** — mappato al campo nativo wm-package invece di `forestas->codice_cai`.
- `**type` → TaxonomyActivity** — due termini (`sentiero`/`itinerario`) creati in fase di import. `forestas->type` rimosso.
- `**field_immagine_principale` su nodo Ente** — campo diretto con URL (`field_immagine_principale[0].url`). Presente solo su alcuni nodi; se vuoto, nessuna immagine viene importata (comportamento atteso).

