# Plan: Taxonomy Where — OSMFeatures Admin Areas

## Goal

Introduce a proper **TaxonomyWhere** relational taxonomy backed by OSMFeatures admin areas.

- Admins importano admin areas da OSMFeatures (bbox dell'App + admin_level a scelta)
- Le geometrie vengono salvate localmente in PostGIS — il matching avviene con `ST_Intersects` senza dipendenze API a runtime
- La taxonomy appare in Nova sotto "Taxonomies"
- I Layer possono selezionare TaxonomyWhere (insieme a TaxonomyActivity)
- Logica: solo activity → filtra per activity; solo where → filtra per area; entrambe → AND

---

## Architecture Overview

### Key Design Decisions

1. **New relational model** — `TaxonomyWhere` diventa una taxonomy relazionale completa con tabella `taxonomy_wheres`, pivot `taxonomy_whereables`, e colonna `geometry` (PostGIS multipolygon).

2. **Track matching via ST_Intersects** — invece di leggere `properties->taxonomy_where` JSON (che dipende da job pregressi), `assignTracksByTaxonomy()` usa una query PostGIS diretta: `ST_Intersects(ec_tracks.geometry, taxonomy_wheres.geometry)`. Funziona su tutte le track, anche appena importate, senza dipendenze da job esterni.

3. **Logica di filtro:**
   - Solo TaxonomyActivity → comportamento invariato (JOIN su `taxonomy_activityables`)
   - Solo TaxonomyWhere → `ST_Intersects` con le geometrie dei where selezionati
   - Entrambe → AND: track deve matchare activity **e** intersecare l'area geografica
   - Nessuna → sync vuoto

4. **Import** — Nova Action sull'App: scarica da OSMFeatures `/api/v2/features/admin-areas/list` con bbox + admin_level scelto dall'utente, salva `osmfeatures_id`, `name`, `admin_level` e la **geometria GeoJSON** in `taxonomy_wheres.geometry`.

5. **`properties->taxonomy_where`** sulle track rimane invariato (serve per Elasticsearch/API esterna) ma non è più usato per il layer sync.

---

## Files to Create / Modify

### wm-package (`/Users/bongiu/Documents/geobox2/forestas/wm-package`)

| File | Action |
|------|--------|
| `database/migrations/create_taxonomy_wheres_table.php.stub` | **Create** — include colonna `geometry` PostGIS |
| `database/migrations/create_taxonomy_whereables_table.php.stub` | **Create** |
| `database/migrations/z_add_foreign_keys_to_taxonomy_whereables_table.php.stub` | **Create** |
| `src/Models/TaxonomyWhere.php` | **Modify** — fillable, geometry cast, relazioni layers/ecTracks |
| `src/Models/TaxonomyWhereable.php` | **Create** — MorphPivot |
| `src/Models/Layer.php` | **Modify** — add `taxonomyWheres()` relation |
| `src/Services/Models/LayerService.php` | **Modify** — `assignTracksByTaxonomy()` con ST_Intersects |
| `src/Nova/TaxonomyWhere.php` | **Create** — Nova resource |
| `src/Nova/Actions/ImportTaxonomyWhereFromOsmfeatures.php` | **Create** — Nova action (crea record + dispatcha job) |
| `src/Nova/Actions/RetryTaxonomyWhereGeometryFetch.php` | **Create** — Nova action per rilanciare job su record senza geometry |
| `src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php` | **Create** — Job con 3 retry, scarica geometry dal detail endpoint |
| `src/Http/Clients/OsmfeaturesClient.php` | **Modify** — add `getAdminAreasList()` che restituisce anche geometry |
| `src/Providers/WmPackageServiceProvider.php` | **Modify** — registra TaxonomyWhere Nova resource + migrations |

### forestas project

| File | Action |
|------|--------|
| `app/Nova/TaxonomyWhere.php` | **Create** — extends WmPackage TaxonomyWhere |
| `app/Nova/NovaServiceProvider.php` | **Modify** — add TaxonomyWhere al menu Taxonomies |

---

## Phase 0: Documentation Discovery ✅ (complete)

**Findings:**

- `TaxonomyWhere` model esiste ma è uno stub senza migrazioni, pivot, né Nova resource.
- `TaxonomyActivity` è il pattern da seguire: migration stub, pivot stub, MorphPivot model, Nova resource.
- Le track hanno già `properties->taxonomy_where` JSON (keyed by osmfeatures_id), ma questo viene popolato da un job asincrono — **non affidabile** come fonte per il layer sync.
- `LayerService::assignTracksByTaxonomy()` a riga 303 usa JOIN raw su `taxonomy_activityables`.
- `OsmfeaturesClient` non ha metodo pubblico per `/api/v2/features/admin-areas/list`.
- `App.map_bbox` è stringa `[minLon,minLat,maxLon,maxLat]`.
- Il DB ha già PostGIS attivo (usato per `ec_tracks.geometry`).

**Pattern files:**
- Migrations: `create_taxonomy_activities_table.php.stub` + `create_taxonomy_activityables_table.php.stub`
- Model: `TaxonomyActivity.php` + `TaxonomyActivityable.php`
- Nova: `src/Nova/TaxonomyActivity.php`

---

## Phase 1: Database Migrations (wm-package)

**What to implement:**

1. **`create_taxonomy_wheres_table.php.stub`**
   - Colonne: `id`, `name` (text), `osmfeatures_id` (string, unique, nullable), `admin_level` (integer, nullable), `geometry` (geography/multipolygon, nullable), `properties` (jsonb, nullable), timestamps
   - Usare `$table->multiPolygon('geometry')->nullable()` o `DB::statement("ALTER TABLE taxonomy_wheres ADD COLUMN geometry geometry(MultiPolygon,4326)")`

2. **`create_taxonomy_whereables_table.php.stub`**
   - Colonne: `id`, `taxonomy_where_id` (integer, FK), `taxonomy_whereable_type` (string), `taxonomy_whereable_id` (integer)

3. **`z_add_foreign_keys_to_taxonomy_whereables_table.php.stub`**
   - FK: `taxonomy_whereables.taxonomy_where_id → taxonomy_wheres.id`

**Verification:**
- `php artisan migrate` gira pulito
- `\DB::select("SELECT column_name FROM information_schema.columns WHERE table_name='taxonomy_wheres'")` mostra `geometry`

**Anti-pattern guards:**
- NON aggiungere `duration_forward`/`duration_backward` al pivot
- La colonna geometry deve essere nullable (le TaxonomyWhere senza geometria non possono fare ST_Intersects ma non devono bloccare il sistema)

---

## Phase 2: Model Layer (wm-package)

**What to implement:**

1. **`TaxonomyWhereable`** (nuovo, copia da `TaxonomyActivityable.php`):
   - `protected $table = 'taxonomy_whereables'`
   - Relazioni: `model(): MorphTo`, `taxonomyWhere(): BelongsTo`

2. **`TaxonomyWhere`** (modifica stub esistente):
   - `$fillable = ['name', 'osmfeatures_id', 'admin_level', 'geometry', 'properties']`
   - Cast geometry (usare il cast già usato in EcTrack per geometry)
   - `layers(): MorphToMany` via `taxonomy_whereables`
   - `ecTracks(): MorphToMany` via `taxonomy_whereables`

3. **`Layer`** (modifica):
   - `taxonomyWheres(): MorphToMany` — stesso pattern di `taxonomyActivities()`

**Verification:**
- `Layer::find(1)->taxonomyWheres` → collection vuota senza errori
- `TaxonomyWhere::first()->layers` funziona

---

## Phase 3: OsmfeaturesClient — Admin Areas List con Geometry (wm-package)

**Flusso a due step:**

1. `GET /api/v2/features/admin-areas/list?bbox=...&admin_level=...&tags=name` → lista di osmfeatures IDs (paginata, 1000/page)
2. Per ogni ID: `GET /api/v2/features/admin-areas/{osmfeatures_id}` → dettaglio completo con geometry GeoJSON

**Modify `src/Http/Clients/OsmfeaturesClient.php`:**

```php
/**
 * Step 1: fetch all osmfeatures IDs for a bbox + admin_level (paginated).
 */
public function getAdminAreasIds(string $bbox, int $adminLevel): array
{
    $ids = [];
    $page = 1;
    do {
        $response = Http::get($this->getHost() . '/api/v2/features/admin-areas/list', [
            'bbox'        => $bbox,
            'admin_level' => $adminLevel,
            'tags'        => 'name',
            'page'        => $page,
        ]);
        $data = $response->json('data', []);
        foreach ($data as $item) {
            $ids[] = $item['id'];
        }
        $page++;
    } while (count($data) === 1000);

    return $ids;
}

/**
 * Step 2: fetch full detail (name, admin_level, geometry) for a single osmfeatures ID.
 * Returns ['osmfeatures_id', 'name', 'admin_level', 'geometry' (GeoJSON string)]
 */
public function getAdminAreaDetail(string $osmfeaturesId): array
{
    $response = Http::get($this->getHost() . '/api/v2/features/admin-areas/' . $osmfeaturesId);
    $data = $response->json();

    return [
        'osmfeatures_id' => $osmfeaturesId,
        'name'           => $data['properties']['name'] ?? $osmfeaturesId,
        'admin_level'    => $data['properties']['admin_level'] ?? null,
        'geometry'       => isset($data['geometry']) ? json_encode($data['geometry']) : null,
    ];
}
```

**Verification:**
- `getAdminAreasIds('[8.9,39.1,9.3,39.4]', 8)` restituisce array di ID
- `getAdminAreaDetail('R123456')` restituisce geometry non null

---

## Phase 4: Nova Action + Job — Import TaxonomyWhere (wm-package)

### 4a — Nova Action: `ImportTaxonomyWhereFromOsmfeatures.php`

**File:** `src/Nova/Actions/ImportTaxonomyWhereFromOsmfeatures.php`

**Flusso:**
1. Chiama `getAdminAreasIds($bbox, $adminLevel)` → lista di `{id, name}` dal list endpoint (veloce, sincrono)
2. Per ogni item: `TaxonomyWhere::updateOrCreate(['osmfeatures_id' => $id], ['name' => $name, 'admin_level' => $adminLevel])` — crea subito il record **senza geometry**
3. Per ogni record creato/trovato: dispatcha `FetchTaxonomyWhereGeometryJob::dispatch($taxonomyWhere->id)`
4. Restituisce `Action::message("Creati {$count} record. Le geometrie vengono scaricate in background.")`

**Fields:**
- `Select` per `admin_level` (4/6/8/9/10 con label)

**Anti-pattern guards:**
- NON bloccare l'action aspettando le geometrie
- Upsert su `osmfeatures_id` → rieseguibile senza duplicati, ridispatcha i job per chi non ha ancora geometry

---

### 4b — Job: `FetchTaxonomyWhereGeometryJob.php`

**File:** `src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php`

**What to implement:**
```php
class FetchTaxonomyWhereGeometryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // secondi tra i retry

    public function __construct(public int $taxonomyWhereId) {}

    public function handle(OsmfeaturesClient $client): void
    {
        $taxonomyWhere = TaxonomyWhere::findOrFail($this->taxonomyWhereId);
        $detail = $client->getAdminAreaDetail($taxonomyWhere->osmfeatures_id);

        if (empty($detail['geometry'])) {
            // Geometry non disponibile — non fare fail, lascia geometry null
            Log::warning('TaxonomyWhere geometry not available', ['id' => $this->taxonomyWhereId]);
            return;
        }

        $taxonomyWhere->geometry = $detail['geometry']; // cast GeoJSON → PostGIS
        $taxonomyWhere->save();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchTaxonomyWhereGeometryJob failed', [
            'taxonomy_where_id' => $this->taxonomyWhereId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

**Retry policy:** `$tries = 3`, `$backoff = 60s` — dopo 3 fallimenti il job finisce in `failed_jobs`, il record rimane senza geometry (visibile in Nova).

---

### 4c — Nova Action: `RetryGeometryFetch.php`

**File:** `src/Nova/Actions/RetryTaxonomyWhereGeometryFetch.php`

- Target: TaxonomyWhere index (azione su record selezionati)
- Logic: per ogni record selezionato dispatcha `FetchTaxonomyWhereGeometryJob::dispatch($record->id)`
- Visibile solo su record con `geometry IS NULL` (filtro `onlyOnIndex` + `canSee` che controlla se l'utente ha selezionato record senza geometry, oppure più semplicemente sempre disponibile)
- Restituisce `Action::message('Job di recupero geometry rilancati.')`

---

## Phase 5: Nova Resources (wm-package + forestas)

**wm-package: `src/Nova/TaxonomyWhere.php`** (copia da `TaxonomyActivity.php`):
- Label: 'Taxonomy Where' / 'Taxonomies Where'
- Fields:
  - ID, name (Text), osmfeatures_id (Text, readonly), admin_level (Number, readonly)
  - **`Boolean::make('Geometry', fn() => !is_null($this->geometry))->onlyOnIndex()`** — flag visivo ✅/❌ nella lista
- Actions: `RetryTaxonomyWhereGeometryFetch` disponibile sull'index
- Relationship panel: MorphToMany Layers

**forestas: `app/Nova/TaxonomyWhere.php`**:
```php
namespace App\Nova;
use Wm\WmPackage\Nova\TaxonomyWhere as WmTaxonomyWhere;
class TaxonomyWhere extends WmTaxonomyWhere {}
```

**Menu** in `NovaServiceProvider` (forestas):
- Aggiungi `MenuItem::resource(TaxonomyWhere::class)` nella sezione Taxonomies

**Layer Nova resource** (`src/Nova/Layer.php`):
- Aggiungi campo MorphToMany TaxonomyWhere accanto a TaxonomyActivity

**Verification:**
- TaxonomyWhere visibile nel menu Nova sotto Taxonomies
- Layer edit mostra il campo TaxonomyWhere

---

## Phase 6: Layer Track Filtering — ST_Intersects (wm-package)

**Modify `LayerService::assignTracksByTaxonomy()`** a `src/Services/Models/LayerService.php:303`:

**Nuova logica completa:**

```php
public function assignTracksByTaxonomy(Layer $layer): void
{
    if (! $layer->isAutoTrackMode()) {
        return;
    }

    $layerTaxonomyIds = $layer->taxonomyActivities->pluck('id')->toArray();
    $layerWhereIds    = $layer->taxonomyWheres->pluck('id')->filter()->toArray();

    $layerAppIds = [
        $layer->app_id,
        ...$layer->associatedApps->pluck('id')->toArray(),
    ];

    if (empty($layerAppIds) || (empty($layerTaxonomyIds) && empty($layerWhereIds))) {
        $layer->ecTracks()->sync([]);
        return;
    }

    $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
    $trackTable = (new $ecTrackModelClass)->getTable();
    $trackMorphTypes = array_values(array_unique([
        $ecTrackModelClass, EcTrack::class, 'App\\Models\\EcTrack',
    ]));

    // Base query: app filter + geometry not null
    $query = DB::table($trackTable)
        ->whereIn("{$trackTable}.app_id", $layerAppIds)
        ->whereNotNull("{$trackTable}.geometry");

    // Filter by TaxonomyActivity (se presenti)
    if (! empty($layerTaxonomyIds)) {
        $query->join('taxonomy_activityables',
            'taxonomy_activityables.taxonomy_activityable_id', '=', "{$trackTable}.id")
            ->whereIn('taxonomy_activityables.taxonomy_activityable_type', $trackMorphTypes)
            ->whereIn('taxonomy_activityables.taxonomy_activity_id', $layerTaxonomyIds);
    }

    // Filter by TaxonomyWhere via ST_Intersects (se presenti)
    if (! empty($layerWhereIds)) {
        $query->whereExists(function ($sub) use ($layerWhereIds, $trackTable) {
            $sub->select(DB::raw(1))
                ->from('taxonomy_wheres')
                ->whereIn('taxonomy_wheres.id', $layerWhereIds)
                ->whereNotNull('taxonomy_wheres.geometry')
                ->whereRaw("ST_Intersects({$trackTable}.geometry, taxonomy_wheres.geometry)");
        });
    }

    $trackIds = $query->distinct()->pluck("{$trackTable}.id")->toArray();

    $now = now();
    $syncPayload = array_fill_keys($trackIds, ['created_at' => $now, 'updated_at' => $now]);
    $layer->ecTracks()->sync($syncPayload);

    Log::info('Track sincronizzate automaticamente al layer', [
        'layer_id'       => $layer->id,
        'track_count'    => count($trackIds),
        'taxonomy_ids'   => $layerTaxonomyIds,
        'where_ids'      => $layerWhereIds,
    ]);
}
```

**Logic matrix:**
| Situazione | Comportamento |
|---|---|
| Solo TaxonomyActivity | JOIN su activityables (invariato) |
| Solo TaxonomyWhere | ST_Intersects con geometrie where |
| Entrambe | AND — activity JOIN + ST_Intersects |
| Nessuna | sync vuoto |

**Verification:**
- Test: EcTrack con geometria dentro Cagliari + Layer con TaxonomyWhere "Cagliari" → track assegnata
- Test: EcTrack fuori dall'area → NON assegnata
- Test: Layer con solo TaxonomyActivity (no where) → comportamento invariato rispetto a prima

---

## Phase 7: Final Verification

- [ ] `vendor/bin/pest` passes
- [ ] `vendor/bin/phpstan analyse` clean (o baseline aggiornata)
- [ ] `php artisan migrate` gira pulito
- [ ] `php artisan vendor:publish --tag=wm-package-migrations --force`
- [ ] Nova: TaxonomyWhere visibile sotto Taxonomies
- [ ] Nova: App resource mostra action "Import Taxonomy Where"
- [ ] Nova: Layer edit mostra campo TaxonomyWhere
- [ ] Test manuale: import admin_level=8 su App Sardegna → record creati con geometry
- [ ] Test manuale: layer con TaxonomyWhere "Cagliari" → sync corretto

---

## Open Questions ✅ (tutte risolte)

1. ✅ Layer con ONLY taxonomyWhere → filtra track per area. Esempio: layer "Cagliari" mostra tutte le track nell'area.
2. ✅ Admin level: Select utente. Opzioni 4/6/8/9/10.
3. ✅ TaxonomyWhere globale (condivisa tra app).
4. ✅ Matching via ST_Intersects su geometria locale — no dipendenza da `properties->taxonomy_where` JSON per il layer sync.
