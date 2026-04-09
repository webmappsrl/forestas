# Sardegna Sentieri — Mappatura Import (stato attuale)

**Data:** 2026-04-09  
**Fonte dati:** `https://www.sardegnasentieri.it` (API Drupal JSON)  
**Aggiorna:** `2026-04-02-sardegnasentieri-fields-mapping-design.md`

---

## Legenda

- **Variabile sorgente** — percorso nella risposta API Drupal
- **Destinazione** — colonna o path in `properties` nel DB forestas
- **Note** — considerazioni e decisioni prese durante l'implementazione

---

## EcTrack

Flusso: `sardegnasentieri:import` → `ImportSardegnaSentieriTrackJob` → `SardegnaSentieriImportService::importTrackFromResponse()` → `TrackPropertiesData::fromApiResponse()` + sync methods.

| Variabile sorgente API | DTO intermedio | Destinazione DB | Note |
|---|---|---|---|
| `properties.id` | `ApiTrackResponse::$id` | `properties->sardegnasentieri_id` | ID esterno univoco. Usato come chiave di lookup per sync incrementale (`firstWhere(..., sardegnasentieri_id)`). Readonly in Nova. |
| `properties.name` | `ApiTrackResponse::$name` | `name` (jsonb translatable) | Campo nativo wm-package. Array `{it, en, fr, es, de}`. |
| `properties.description` | `ApiTrackResponse::$description` | `properties->description` (jsonb translatable) | Campo nativo wm-package. |
| `properties.excerpt` | `ApiTrackResponse::$excerpt` | `properties->excerpt` (jsonb translatable) | Campo nativo wm-package. |
| `properties.ref` (codice_cai) | `ApiTrackResponse::$codice_cai` | `properties->ref` | Campo nativo wm-package per codice CAI. Priorità su `forestas->codice_cai`. |
| `properties.codice` | `ApiTrackResponse::$codice` | `properties->forestas->codice` | Codice interno sardegnasentieri. Nessun campo nativo corrispondente. |
| `properties.lunghezza` | `ApiTrackResponse::$lunghezza` | `properties->manual_data->distance` | ManualTrackData wm-package. Distanza in metri. |
| `properties.dislivello_totale` | `ApiTrackResponse::$dislivello_totale` | `properties->manual_data->ascent` | **Dubbio aperto [D1]:** non è chiaro se è solo salita, solo discesa o somma. Mappato provvisoriamente ad `ascent`. |
| `properties.durata` | `ApiTrackResponse::$durata` | `properties->manual_data->duration_forward` + `duration_backward` | Un solo campo API applicato a entrambe le direzioni. |
| `properties.ele_min` | `ApiTrackResponse::$ele_min` | `properties->manual_data->ele_min` | Le colonne native `ele_min/max` su EcTrack sono riservate al DEM; i valori sardegnasentieri vanno in `manual_data`. |
| `properties.ele_max` | `ApiTrackResponse::$ele_max` | `properties->manual_data->ele_max` | Stesso ragionamento di `ele_min`. |
| `properties.created_at` | `ApiTrackResponse::$created_at` | `properties->forestas->created_at` | Data creazione nodo Drupal. Readonly in Nova. |
| `properties.updated_at` | `ApiTrackResponse::$updated_at` | `properties->forestas->updated_at` | Usato per sync incrementale: se uguale al valore in DB, il job non viene dispatchato. Readonly in Nova. |
| `properties.data_rilievo` | `ApiTrackResponse::$data_rilievo` | `properties->forestas->data_rilievo` | Data del rilievo sul campo. |
| `properties.url` | `ApiTrackResponse::$url` | `properties->forestas->url` | URL vecchio sardegnasentieri. Utile per redirect 301 futuri. Readonly in Nova. |
| `properties.info_utili` | `ApiTrackResponse::$info_utili` | `properties->forestas->info_utili` | JSON grezzo `{it: "HTML", en: "HTML"}`. Spatie non supporta nesting >1 livello, impossibile usare Translatable standard. Visualizzato come Tiptap readonly in Nova. |
| `properties.roadbook` | `ApiTrackResponse::$roadbook` | `properties->forestas->roadbook` | Stesso schema di `info_utili`. |
| `properties.allegati` | `ApiTrackResponse::$allegati` | `properties->forestas->allegati` | Array di URL PDF/documenti. Storato come array associativo `indice→URL`. **TODO:** valutare migrazione URL su storage interno. |
| `properties.video` | `ApiTrackResponse::$video` | `properties->forestas->video` | Array di URL YouTube (solo ~7 track valorizzati). Campo URL, non upload. |
| `properties.gpx` | `ApiTrackResponse::$gpx` | geometry `MULTILINESTRING Z` su EcTrack + `properties->forestas->gpx` | URL GPX scaricato, parsato con SimpleXML, convertito in WKT `MULTILINESTRING Z (lon lat ele, ...)`. Il file non viene conservato permanentemente. Se assente, fallback a geometry JSON API. |
| `properties.partenza` | `ApiTrackResponse::$partenza` | `properties->from` + `ecPois()->sync()` | ID sardegnasentieri del POI di partenza. Risolto al nome per il campo `from`. Incluso nella sync `ecPois()`. |
| `properties.arrivo` | `ApiTrackResponse::$arrivo` | `properties->to` + `ecPois()->sync()` | ID sardegnasentieri del POI di arrivo. Risolto al nome per il campo `to`. Incluso nella sync `ecPois()`. |
| `properties.poi_correlati` | `ApiTrackResponse::$poi_correlati` | `ecPois()->sync()` | Ordine sync: partenza, poi_correlati[], arrivo. POI non ancora importati vengono ignorati e ricollegati al prossimo sync. |
| `properties.type` | `ApiTrackResponse::$type` | `TaxonomyActivity` (identifier `sardegnasentieri:type:{type}`) | Crea/assegna termine Activity per ogni valore: `sentiero` e `itinerario`. Identifier usato: `sardegnasentieri:type:sentiero` / `sardegnasentieri:type:itinerario`. |
| `properties.immagine_principale` | `SardegnaSentieriImageManifest` | Media collection `default` via `ImportSardegnaSentieriTrackMediaJob` | Campi API: `url` (chiave idempotente), `autore`, `credits`. Dispatchato separatamente sul job media. |
| `properties.galleria_immagini` | `SardegnaSentieriImageManifest` | Media collection `default` via `ImportSardegnaSentieriTrackMediaJob` | Stesso manifest di `immagine_principale`. No duplicati (dedup per URL). |
| `properties.ente_istituzione_societa` | `ApiTrackResponse::$ente_istituzione_societa` | Modello `Ente` + tabella `enteables` | Vedi sezione Ente. Campi: `soggetto_gestore`, `soggetto_manutentore`, `soggetto_rilevatore`, `riferimento_operatori_guid[]`. |
| `properties.complesso_forestale` | `ApiTrackResponse::$complesso_forestale` | Modello `Ente` + `enteables` con ruolo `complesso_forestale` | Nodo Drupal distinto. Stesso meccanismo degli altri enti. |
| `taxonomies.tipologia_sentieri` | `ApiTaxonomiesData::$tipologia_sentieri` | `TaxonomyActivity` via `taxonomyActivities()->sync()` | Resolved via `resolveActivityIdsForVocabulary()` per vocabulary `tipologia_sentieri`. |
| `taxonomies.stato_di_validazione` | `ApiTaxonomiesData::$stato_di_validazione` | `stato_validazione` (enum `StatoValidazione`) | Primo elemento dell'array. Enum mappato da API ID. |
| `taxonomies.tipologia_di_avvertenze` | `ApiTaxonomiesData::$tipologia_di_avvertenze` | `TaxonomyWarning` via `taxonomyWarnings()->sync()` | Resolved via `resolveWarningIds()`. |
| `taxonomies.zona_geografica` | `ApiTaxonomiesData::$zona_geografica` | `properties->forestas->zona_geografica` (raw) | **Non collegato a TaxonomyWhere.** Storato raw in attesa di implementazione import TaxonomyWhere con geometrie. |
| `taxonomies.categorie_fruibilita_sentieri` | `ApiTaxonomiesData::$categorie_fruibilita_sentieri` | **Non importato** | **Dubbio aperto [D2]:** dovrebbe mappare la difficoltà del percorso. In sospeso. |
| geometry (GPX) | — | `geometry MULTILINESTRING Z` | Source primaria. Se GPX assente (2 track su ~787), fallback a geometry GeoJSON API. |

---

## EcPoi

Flusso: `sardegnasentieri:import` → `ImportSardegnaSentieriPoiJob` → `SardegnaSentieriImportService::importPoiFromResponse()` → `PoiPropertiesData::fromApiResponse()` + sync methods.

| Variabile sorgente API | DTO intermedio | Destinazione DB | Note |
|---|---|---|---|
| `properties.id` | `ApiPoiResponse::$id` | `properties->out_source_feature_id` | ID esterno univoco. Lookup: `whereRaw("properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?")`. Readonly in Nova. |
| `properties.name` | `ApiPoiResponse::$name` | `name` (jsonb translatable) | Campo nativo wm-package. Se API restituisce stringa invece di array, normalizzato a `{it: stringa}`. |
| `properties.description` | `ApiPoiResponse::$description` | `properties->description` (jsonb translatable) | Campo nativo wm-package. |
| `properties.addr_locality` | `ApiPoiResponse::$addr_locality` | `properties->addr_complete` | Campo nativo wm-package per indirizzo. `optionalString()`: null se non stringa (Drupal a volte invia array). |
| `properties.codice` | `ApiPoiResponse::$codice` | `properties->forestas->codice` | **Dubbio aperto [D3]:** significato da approfondire con il cliente. |
| `properties.collegamenti` | `ApiPoiResponse::$collegamenti` | `properties->forestas->collegamenti` | Lista URL associati al POI. Array associativo `indice→URL`. |
| `properties.come_arrivare` | `ApiPoiResponse::$come_arrivare` | `properties->forestas->come_arrivare` | JSON `{it: "HTML", en: "HTML"}`. **Normalizzazione applicata in `ApiPoiResponse::fromJson()`:** Drupal inviava `{it: ["<p>...</p>"]}` (array), normalizzato a stringa con `implode('')`. Visualizzato come Tiptap readonly in Nova. |
| `properties.url` | `ApiPoiResponse::$url` | `properties->forestas->url` | URL vecchio sardegnasentieri. Readonly in Nova. |
| `properties.updated_at` | `ApiPoiResponse::$updated_at` | `properties->forestas->updated_at` | Usato per sync incrementale. Readonly in Nova. |
| `properties.allegati` | `ApiPoiResponse::$allegati` | `properties->forestas->allegati` | Array URL PDF. **TODO:** valutare migrazione su storage interno. |
| `properties.video` | `ApiPoiResponse::$video` | `properties->forestas->video` | Dall'analisi: nessun POI ha valori validi. Non valorizzato in pratica. |
| `properties.poi_correlati` | `ApiPoiResponse::$poi_correlati` | `relatedPois()->sync()` via tabella `ec_poi_related_pois` | Relazione custom POI→POI (BelongsToMany self-referencing su `App\Models\EcPoi`). POI non ancora importati ignorati. |
| `properties.immagine_principale` | `SardegnaSentieriImageManifest` | Media collection `default` via `ImportSardegnaSentieriPoiMediaJob` | Campi API: `url`, `autore`, `credits`. |
| `properties.galleria` | `SardegnaSentieriImageManifest` | Media collection `default` via `ImportSardegnaSentieriPoiMediaJob` | Dedup per URL. |
| `properties.ente_istituzione_societa` | `ApiPoiResponse::$ente_istituzione_societa` | Modello `Ente` + tabella `enteables` | Campi: `soggetto_gestore`, `soggetto_manutentore`, `soggetto_rilevatore`, `riferimento_operatori_guid[]`. Stessa logica EcTrack. |
| `taxonomies.tipologia_poi` | `ApiTaxonomiesData::$tipologia_poi` | `TaxonomyPoiType` via `taxonomyPoiTypes()->sync()` | Resolved via `resolvePoiTypeIds($id, 'tipologia_poi')`. |
| `taxonomies.servizi` | `ApiTaxonomiesData::$servizi` | `TaxonomyPoiType` via `taxonomyPoiTypes()->sync()` | Mergiato con `tipologia_poi` nella stessa sync. Resolved via `resolvePoiTypeIds($id, 'servizi')`. **Nota:** in origine pensato come Activity, poi spostato su PoiType perché semanticamente descrive il tipo di luogo/servizio. |
| `taxonomies.zona_geografica` | `ApiTaxonomiesData::$zona_geografica` | `properties->forestas->zona_geografica` (raw) | Non collegato a TaxonomyWhere. In sospeso. |
| `taxonomies.categorie_fruibilita_sentieri` | `ApiTaxonomiesData::$categorie_fruibilita_sentieri` | **Non importato** | In sospeso come per Track. |
| geometry | `ApiPoiResponse::$coordinates` | `geometry POINT(lon lat)` | Obbligatoria — l'import lancia `RuntimeException` se assente. |

---

## Ente

Flusso: `syncEnti()` / `syncEntiPoi()` → `resolveOrImportEnte()` → `importOrUpdateEnte()` → `GET /node/{id}?_format=json`.

| Variabile sorgente API (nodo Drupal) | Destinazione DB | Note |
|---|---|---|
| `title[0].value` | `name` (jsonb translatable `it`+`en`) | Unico valore, replicato su entrambe le lingue. |
| `body[0].value` | `description` (jsonb translatable `it`+`en`) | HTML stripped via `strip_tags()`. |
| `field_contatti[0].value` | `contatti` (jsonb translatable) | Storato come HTML (non stripped). |
| `field_pagina_web[0].uri` | `pagina_web` (string) | URL sito web dell'ente. |
| `field_tipo_ente[0].target_id` | `tipo_ente` (string enum `TipoEnte`) | Risolto via `TipoEnte::fromDrupalId()`. Se ID non mappato: risolve il termine via API, salva come slug stringa, logga warning. Mapping Drupal ID: 4699=`complesso-forestale`, 4700=`ente-partner`, 4701=`altre-pubbliche-istituzioni`, 4702=`privato-associazione`, 4703=`comune`. |
| `field_immagine_principale[0].url` | Media collection `default` | Importato via `SardegnaSentieriMediaSyncService::syncImportedImages()`. Richiede `registerMediaCollections()` su `Ente` (fix 2026-04-09). |
| `field_geolocalizzazione[0].lat/lon` | `geometry POINT(lon lat)` | Opzionale — se assente, geometry non impostata. |
| ID nodo (path param) | `sardegnasentieri_id` (string unique) | Chiave di lookup per evitare duplicati. |

**Relazione con EcTrack/EcPoi:** tabella pivot polimorfica `enteables` con colonna `ruolo`.

| Campo API (da `ente_istituzione_societa`) | Ruolo in `enteables` |
|---|---|
| `soggetto_gestore` | `gestore` |
| `soggetto_manutentore` | `manutentore` |
| `soggetto_rilevatore` | `rilevatore` |
| `riferimento_operatori_guid[]` | `operatore` (multiplo) |
| `complesso_forestale` (solo EcTrack) | `complesso_forestale` |

---

## TaxonomyPoiType

Flusso: `importPoiTaxonomies()` → `GET /ss/tassonomia/{vocabulary}?_format=json` per vocabulary `tipologia_poi` e `servizi`.

| Variabile sorgente API | Destinazione DB | Note |
|---|---|---|
| `geohub_identifier` (se presente) | `identifier` | Priority: se il termine ha `geohub_identifier`, viene usato come identifier. Nome impostato uguale all'identifier. |
| `name` (fallback) | `identifier = 'name:{slug}'` | Se `geohub_identifier` assente, identifier costruito come `name:{normalizeKey(name)}`. |
| `name` | `name` (string/jsonb) | Nome del termine. |
| `description` | `description` | Descrizione del termine. |
| API term ID (key nella risposta) | `properties->source_id` | ID Drupal del termine (`tid`). Usato per identificare i termini importati nel reset. |
| vocabulary name (`tipologia_poi`/`servizi`) | `properties->vocabulary` | Origine del termine. Usato per il filtro Nova `TaxonomyVocabularyFilter`. |
| — | `icon` | Risolto da `resolveIconNameByIdentifier()`: cerca in `icons.json` (GlobalFileHelper), fallback a `ICON_FALLBACK_MAP` hardcoded. Impostato solo se `icon` è vuoto. |

---

## TaxonomyActivity

Flusso: `importTrackTaxonomies()` → vocabulary `tipologia_sentieri`. Nessun vocabulary per POI attualmente (`POI_ACTIVITY_VOCABULARIES = []`).

Aggiunta via `syncTrackType()`: termini `sardegnasentieri:type:sentiero` e `sardegnasentieri:type:itinerario`.

| Variabile sorgente API | Destinazione DB | Note |
|---|---|---|
| `geohub_identifier` / `name` | `identifier` | Stesso schema di TaxonomyPoiType. |
| `name` | `name` | Nome del termine. |
| `description` | `description` | Descrizione. |
| API term ID | `properties->source_id` | ID Drupal. |
| vocabulary name (`tipologia_sentieri`) | `properties->vocabulary` | Origine. |
| `properties.type` (da EcTrack) | `identifier = 'sardegnasentieri:type:{type}'`, `name = ucfirst(type)` | Crea termine on-the-fly in `syncTrackType()`. Non ha `source_id`. |
| — | `icon` | Stessa logica TaxonomyPoiType. `ICON_FALLBACK_MAP` include `sardegnasentieri:type:sentiero` → `txn-hiking` e `sardegnasentieri:type:itinerario` → `txn-trail`. |

---

## TaxonomyWarning

Flusso: `importWarningTaxonomies()` → `GET /ss/tassonomia/tipologia_di_avvertenze?_format=json`.

| Variabile sorgente API | Destinazione DB | Note |
|---|---|---|
| API term ID (key) | `identifier = 'sardegnasentieri:warning:{id}'` | Pattern fisso — non usa `geohub_identifier`. |
| `name` | `name` (jsonb translatable) | Se array → usato direttamente. Se stringa → `{it: stringa}`. |
| API term ID | `properties->source_id` | ID Drupal. |
| `'tipologia_di_avvertenze'` (costante) | `properties->vocabulary` | Sempre uguale per questa tassonomia. |

---

## Media (immagini)

Flusso: `ImportSardegnaSentieriPoiMediaJob` / `ImportSardegnaSentieriTrackMediaJob` → `SardegnaSentieriMediaSyncService::syncImportedImages()`.

| Campo API immagine | Destinazione in `custom_properties` Media | Note |
|---|---|---|
| `url` | `sardegnasentieri_source_url` | Chiave idempotente per deduplicazione: se un media con questo URL esiste già, non viene reimportato. |
| `autore` | `sardegnasentieri_autore` | Autore della foto. |
| `credits` | `sardegnasentieri_credits` | Crediti fotografici. |
| `titolo` | — | **Non fornito dall'API.** Campo vuoto editabile manualmente in Nova. |
| `caption` | — | **Non fornito dall'API.** Campo vuoto editabile manualmente in Nova. |

**Ordine manifest:** immagine principale (order=0) → galleria (order=1..N). I duplicati (stessa URL) vengono ignorati.

---

## Dubbi aperti

| Ref | Descrizione |
|---|---|
| [D1] | `dislivello_totale` — non è chiaro se è solo salita, solo discesa o somma. Mappato provvisoriamente ad `ascent`. In attesa di risposta dal cliente. |
| [D2] | `categorie_fruibilita_sentieri` — dovrebbe mappare la difficoltà del percorso ma mancano indicazioni su come gestirla nel sistema. Non importato. |
| [D3] | `codice` POI — significato da approfondire con il cliente. |
| [D4] | `zona_geografica` — storato raw in `forestas->zona_geografica`. L'import di TaxonomyWhere con geometrie non è implementato. |

---

## Note implementative rilevanti

- **Sync incrementale:** il comando confronta `updated_at` API vs `properties->forestas->updated_at` DB. Se uguale, il job non viene dispatchato. `--force` ignora questo check.
- **Lookup POI/Track:** EcTrack cercato per `sardegnasentieri_id`, EcPoi per `out_source_feature_id` OR `sardegnasentieri_id` (doppio campo per retrocompatibilità).
- **`manual_data` su EcTrack:** i valori numerici (distanza, dislivello, durata) sono in `properties->manual_data` per non sovrascrivere i valori calcolati dal DEM in `ele_min`, `ele_max`.
- **`optionalString()` in `ApiPoiResponse`:** Drupal a volte invia campi come array invece di stringa. Il metodo restituisce `null` se il valore non è stringa pura.
- **`come_arrivare` normalizzazione:** Drupal inviava `{it: ["<p>...</p>"]}` (valore come array). Normalizzato in `ApiPoiResponse::fromJson()` con `implode('')` per ogni locale.
- **`MediaObserver` fix (2026-04-09):** aggiunto check `instanceof GeometryModel` in `setGeometryFromModel()`. Ente non estende `GeometryModel`, quindi prima andava in eccezione. Ora usa `setDefaultGeometry()` se il model non è un `GeometryModel`.
- **Reset command:** `sardegnasentieri:reset` elimina EcTrack, EcPoi, TaxonomyPoiType, TaxonomyActivity, TaxonomyWarning (identificati da `properties->source_id`), Ente, svuota le code Redis Horizon e azzera le sequence ID.
