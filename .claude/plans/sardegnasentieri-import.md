# Piano: Import POI e Track da Sardegna Sentieri API

## Contesto

Import schedulato (giornaliero) di EcPoi ed EcTrack dall'API pubblica di Sardegna Sentieri.
NON usa il pattern Geohub — è un import custom da API esterna.
Funziona anche come **sync** (aggiornamento incrementale basato su `updated_at`, marcatura `deleted_from_source` per item rimossi).

> **Piano vivo** — aggiornare questo file man mano che l'implementazione avanza o cambiano i requisiti.

---

## API endpoints

**POI:**
- List: `GET /ss/listpoi/?_format=json` → `{"id": "timestamp"}`
- Detail: `GET /ss/poi/{id}?_format=json` → GeoJSON Feature → `ApiPoiResponse`

**Track:**
- List: `GET /ss/list-tracks/?_format=json` → `{"id": "timestamp"}` (~700 track)
- Detail: `GET /ss/track/{id}?_format=json` → GeoJSON Feature → `ApiTrackResponse` (geometry sempre null, coordinate nei GPX)

**Taxonomy:**
- `GET /ss/tassonomia/tipologia_poi` → `TaxonomyPoiType`
- `GET /ss/tassonomia/categorie_fruibilita_sentieri` → `TaxonomyActivity`
- `GET /ss/tassonomia/tipologia_sentieri` → `TaxonomyActivity`
- `GET /ss/tassonomia/stato_di_validazione` → colonna `stato_validazione` (enum `StatoValidazione`, mappa hardcodata per ID)
- `GET /ss/tassonomia/servizi`, `tipologia_itinerari` → solo `properties->forestas` (nessun modello)

**Entità hardcoded:**
- App: `sku=it.webmapp.sardegnasentieri`, `name=Sardegna Sentieri`
- User: `email=forestas@webmapp.it`, `name=forestas`, ruolo `Editor`
- Entrambe create/assicurate da `ensurePrerequisites()` nel Command

---

## Architettura file implementati

```
app/
├── Console/Commands/
│   └── ImportSardegnaSentieriCommand.php     # sardegnasentieri:import {--force} {--only=}
│                                              # ensurePrerequisites() + markRemovedPois/Tracks()
├── Dto/
│   ├── Api/
│   │   ├── ApiPoiResponse.php               # risposta tipizzata GET /poi/{id}
│   │   ├── ApiTrackResponse.php             # risposta tipizzata GET /track/{id}
│   │   └── ApiTaxonomiesData.php            # sub-DTO taxonomies condiviso
│   └── Import/
│       ├── ForestasPoiData.php              # campi custom forestas POI
│       ├── ForestasTrackData.php            # campi custom forestas Track
│       ├── PoiPropertiesData.php            # extends EcPoiPropertiesData + forestas
│       └── TrackPropertiesData.php          # extends EcTrackPropertiesData + sardegnasentieri_id + forestas
├── Enums/
│   └── StatoValidazione.php                 # enum PHP 8.1, fromApiId() con mappa hardcodata
├── Http/Clients/
│   └── SardegnaSentieriClient.php           # getPoiDetail() → ApiPoiResponse
│                                            # getTrackDetail() → ApiTrackResponse
│                                            # GPX_TIMEOUT = 60s separato
├── Jobs/Import/
│   ├── ImportSardegnaSentieriPoiJob.php     # tries=3, timeout=120
│   └── ImportSardegnaSentieriTrackJob.php   # tries=3, timeout=300
├── Nova/
│   └── EcTrack.php                          # campo Select stato_validazione
└── Services/Import/
    └── SardegnaSentieriImportService.php    # import + taxonomy service unificati

wm-package/src/Dto/
├── EcPoiPropertiesData.php                  # base generica properties EcPoi
├── EcTrackPropertiesData.php                # base generica properties EcTrack
└── ManualTrackData.php                      # sub-DTO manual_data con merge() e fromArray()

database/
├── migrations/
│   └── *_add_stato_validazione_to_ec_tracks_table.php
└── seeders/
    └── SardegnaSentieriSeeder.php           # (opzionale, ensurePrerequisites lo sostituisce)

tests/Feature/Import/
└── SardegnaSentieriImportServiceTest.php
```

---

## Catena DTO (flusso dati tipizzato end-to-end)

```
JSON API
  └─→ ApiPoiResponse::fromJson()         (parsing + type-coercion)
        └─→ PoiPropertiesData::fromApiResponse()
              ├── EcPoiPropertiesData     (wm-package, campi standard)
              └── ForestasPoiData::fromApiResponse()   (campi custom)

JSON API
  └─→ ApiTrackResponse::fromJson()
        └─→ TrackPropertiesData::fromApiResponse($existing)
              ├── EcTrackPropertiesData   (wm-package)
              │   └── ManualTrackData::merge(existing, fromApi)
              ├── ForestasTrackData::fromApiResponse()
              └── sardegnasentieri_id
```

---

## Mapping Campi — EcPoi

| Campo API | Destinazione | Note |
|-----------|-------------|------|
| `properties.id` | `properties->out_source_feature_id` | Chiave per updateOrCreate |
| `properties.name` | `name` (nativo) | Translatable |
| `properties.description` | `properties->description` | Translatable |
| `geometry.coordinates` | `geometry` | WKT `POINT(lon lat)`, Z dal DEM via `setGeometryAttribute()` |
| `properties.addr_locality` | `properties->addr_complete` | |
| `properties.codice` | `properties->forestas->codice` | |
| `properties.collegamenti` | `properties->forestas->collegamenti` | |
| `properties.come_arrivare` | `properties->forestas->come_arrivare` | |
| `properties.url` | `properties->forestas->url` | |
| `properties.updated_at` | `properties->forestas->updated_at` | Per sync incrementale |
| `taxonomies.tipologia_poi` | `taxonomyPoiTypes()->sync()` | Via `identifier` |
| `taxonomies.zona_geografica` | `properties->forestas->zona_geografica` | Raw IDs |
| *(hardcoded)* | `app_id`, `user_id` | |

## Mapping Campi — EcTrack

| Campo API | Destinazione | Note |
|-----------|-------------|------|
| `properties.id` | `properties->sardegnasentieri_id` | Chiave per updateOrCreate via `whereRaw` |
| `properties.name` | `name` (nativo) | Translatable |
| `properties.description` | `properties->description` | Translatable |
| `properties.excerpt` | `properties->excerpt` | Translatable |
| GPX (url in `properties.gpx`) | `geometry` | `MULTILINESTRING Z`, Z dall'elevazione GPX |
| `properties.lunghezza` | `properties->manual_data->distance` | |
| `properties.dislivello_totale` | `properties->manual_data->ascent` | |
| `properties.durata` | `properties->manual_data->duration_forward/backward` | |
| `properties.type/allegati/video/gpx/url/updated_at` | `properties->forestas->*` | |
| `properties.partenza/arrivo` | `ecPois()->sync()` | Via `out_source_feature_id` |
| `taxonomies.categorie_fruibilita_sentieri` | `taxonomyActivities()->sync()` | |
| `taxonomies.tipologia_sentieri` | `taxonomyActivities()->sync()` | Merged con sopra |
| `taxonomies.stato_di_validazione[0]` | `stato_validazione` (colonna nativa) | Enum, mappa per ID |
| `taxonomies.zona_geografica` | `properties->forestas->zona_geografica` | |
| *(hardcoded)* | `app_id`, `user_id` | |
| *(always true)* | `properties->forestas->skip_dem_jobs` | Evita ricalcolo DEM |

---

## Note implementative critiche

- **Sync vuoto**: `sync([])` viene chiamato SEMPRE (anche su array vuoto) per rimuovere relazioni stale
- **GPX namespace**: `preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $gpxContent, 1)` prima di `simplexml_load_string()`
- **Doppia query eliminata**: `first()` una sola volta, `properties` letti dal modello
- **deleted_from_source**: item non più nell'API vengono marcati con `properties->forestas->deleted_from_source = true` (NON eliminati)
- **GPX_TIMEOUT = 60s** separato dal TIMEOUT generico (30s)
- **ManualTrackData::merge()**: i valori esistenti (override manuali utente) hanno precedenza sui valori API

---

## Phase 0 — COMPLETATA
Documentazione e anti-pattern identificati.

## Phase 1 — COMPLETATA
- `app/Enums/StatoValidazione.php`
- Migration `stato_validazione` su `ec_tracks`
- `app/Nova/EcTrack.php` con Select stato_validazione

## Phase 2 — COMPLETATA
- `SardegnaSentieriSeeder` (ensurePrerequisites nel Command sostituisce il seeder)
- `SardegnaSentieriClient` con DTO tipizzati in output
- `SardegnaSentieriImportService` unificato (ex 3 service separati)

## Phase 3 — COMPLETATA
- `SardegnaSentieriImportService::importPoi()`
- Sync tassonomie POI

## Phase 4 — IN CORSO / DA FARE
- EcMedia per POI (`immagine_principale`, `galleria`) — relazione da verificare nel modello

## Phase 5 — COMPLETATA
- `SardegnaSentieriImportService::importTrack()`
- GPX parsing con namespace fix
- Sync relazioni (ecPois, taxonomyActivities)

## Phase 6 — DA FARE
- EcMedia per Track (`immagine_principale`, `galleria_immagini`)

## Phase 7 — COMPLETATA
- `ImportSardegnaSentieriCommand` con `--force` e `--only=`
- Scheduler in `routes/console.php` (`dailyAt('03:00')`)
- `markRemovedPois()` / `markRemovedTracks()` per sync bidirezionale

## Phase 8 — DA FARE
- `ResetSardegnaSentieriCommand` per reimport da zero

## Phase 9 — DA FARE
- PHPStan + verifica finale end-to-end

---

## Refactoring applicati (decisioni architetturali)

1. **3 service → 1** (`SardegnaSentieriImportService`) — elimina duplicazione getAppId/getUserId/extractTranslatable
2. **DTO readonly** per mapping tipizzato end-to-end:
   - `wm-package/src/Dto/` — DTO generici riusabili tra progetti
   - `app/Dto/Api/` — risposta API tipizzata (input)
   - `app/Dto/Import/` — properties DB tipizzate (output), estendono i DTO wm-package
3. **Ereditarietà DTO**: `PoiPropertiesData extends EcPoiPropertiesData` aggiunge solo `forestas`; ogni progetto può fare lo stesso con i propri campi custom

---

## Verifica

```bash
vendor/bin/phpstan analyse app/Dto/ app/Http/Clients/ app/Services/Import/ app/Console/Commands/ app/Jobs/Import/ app/Enums/

vendor/bin/pest tests/Feature/Import/

# Import parziale
php artisan sardegnasentieri:import --only=pois
php artisan sardegnasentieri:import --only=tracks

# Reimport completo
php artisan sardegnasentieri:import --force

# Verifica scheduler
php artisan schedule:list
```
