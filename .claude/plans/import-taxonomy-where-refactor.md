# Plan: ImportTaxonomyWhere Refactor

## Goal

Refactor `ImportTaxonomyWhereFromOsmfeatures` in un'action unificata `ImportTaxonomyWhere` che:

1. È rinominata in `ImportTaxonomyWhere`
2. Supporta sia OSMFeatures (admin levels) sia OSM2CAI (settori CAI)
3. Mostra sorgente + admin_level nel testo delle opzioni del select
4. Permette la selezione dell'app quando esistono più app (solo per OSMFeatures)
5. Evita duplicati — aggiorna solo se il record API è più recente di quello memorizzato
6. È spostata dalla resource `App` alla resource `TaxonomyWhere`
7. **Schema refactor**: `osmfeatures_id` e `admin_level` diventano campi dentro `properties` JSONB

---

## Schema Target

La tabella `taxonomy_wheres` avrà solo:

| Colonna     | Tipo                     | Note                          |
|-------------|--------------------------|-------------------------------|
| `id`        | bigint PK                |                               |
| `name`      | text                     | translatable (jsonb via cast) |
| `geometry`  | geography(multipolygon)  | nullable                      |
| `properties`| jsonb                    | tutto il resto qui            |
| `created_at`| timestamp                |                               |
| `updated_at`| timestamp                |                               |

### Struttura `properties` per sorgente OSMFeatures
```json
{
  "osmfeatures_id": "R302072",
  "admin_level": 6,
  "source": "osmfeatures",
  "source_updated_at": "2024-12-19T13:02:10.000000Z"
}
```

### Struttura `properties` per sorgente OSM2CAI
```json
{
  "osm2cai_id": 44,
  "source": "osm2cai",
  "source_updated_at": "2024-12-19T13:02:10.000000Z"
}
```

---

## Phase 0: Documentation Discovery (DONE)

### File coinvolti e impatto

| File | Riferimenti da aggiornare |
|------|--------------------------|
| `wm-package/database/migrations/create_taxonomy_wheres_table.php.stub` | Rimuove colonne `osmfeatures_id`, `admin_level` |
| `database/migrations/2026_03_24_121810_create_taxonomy_wheres_table.php` | Idem (published migration) |
| `wm-package/src/Models/TaxonomyWhere.php` | fillable, casts, docblock |
| `wm-package/src/Nova/TaxonomyWhere.php` | Fields: osmfeatures_id → properties, admin_level → properties |
| `wm-package/src/Nova/Actions/ImportTaxonomyWhereFromOsmfeatures.php` | **Sostituito** da ImportTaxonomyWhere |
| `wm-package/src/Nova/Actions/SyncTracksTaxonomyWhereAction.php` | SQL: `tw.osmfeatures_id` → `tw.properties->>'osmfeatures_id'`, `tw.admin_level` → `(tw.properties->>'admin_level')::int` |
| `wm-package/src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php` | `$taxonomyWhere->osmfeatures_id` → helper method/properties array |
| `app/Nova/App.php` | Rimuove vecchia action |

### OSM2CAI API (confermato dalle immagini)
- List: `GET https://osm2cai.cai.it/api/v3/sectors/list`
  - Response: `[{"id": 7, "updated_at": "2024-12-19T13:02:10.000000Z", "name": {"it": "ZSUD1"}}, ...]`
- Detail: `GET https://osm2cai.cai.it/api/v3/sectors/{id}`
  - Response: GeoJSON Feature con geometry MultiPolygon

---

## Phase 1: Aggiorna Migrazioni (stub + published)

### Stub (wm-package)

**Modifica:** `wm-package/database/migrations/create_taxonomy_wheres_table.php.stub`

Rimuovere le righe:
```php
$table->string('osmfeatures_id')->unique()->nullable();
$table->integer('admin_level')->nullable();
```

La colonna `properties` (jsonb nullable) esiste già — rimane invariata.

### Published migration (app)

**Modifica:** `database/migrations/2026_03_24_121810_create_taxonomy_wheres_table.php`

Stessa modifica: rimuovere le due righe `osmfeatures_id` e `admin_level`.

### Migration di trasferimento dati (se la tabella ha già dati)

**Crea:** `database/migrations/YYYY_MM_DD_HHMMSS_migrate_taxonomy_wheres_columns_to_properties.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Se la tabella esiste con le vecchie colonne, migra i dati in properties
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        if (Schema::hasColumn('taxonomy_wheres', 'osmfeatures_id')) {
            // Sposta osmfeatures_id e admin_level in properties
            DB::statement("
                UPDATE taxonomy_wheres
                SET properties = COALESCE(properties, '{}')::jsonb
                    || jsonb_strip_nulls(jsonb_build_object(
                        'osmfeatures_id', osmfeatures_id,
                        'admin_level',    admin_level,
                        'source',        'osmfeatures'
                    ))
                WHERE osmfeatures_id IS NOT NULL
            ");

            Schema::table('taxonomy_wheres', function ($table) {
                $table->dropUnique(['osmfeatures_id']);
                $table->dropColumn(['osmfeatures_id', 'admin_level']);
            });
        }

        // Indice funzionale su properties->>'osmfeatures_id' per lookup veloci
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_taxonomy_wheres_osmfeatures_id
            ON taxonomy_wheres ((properties->>'osmfeatures_id'))
            WHERE properties->>'osmfeatures_id' IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('taxonomy_wheres', function ($table) {
            $table->string('osmfeatures_id')->unique()->nullable();
            $table->integer('admin_level')->nullable();
        });
    }
};
```

### Verification

```bash
docker exec -it php-forestas php artisan migrate
docker exec -it php-forestas php artisan tinker --execute="
\DB::select('SELECT id, properties FROM taxonomy_wheres LIMIT 3');
"
```

---

## Phase 2: Aggiorna TaxonomyWhere Model

**File:** `wm-package/src/Models/TaxonomyWhere.php`

Cambiano:
1. Docblock: rimuove `@property string|null $osmfeatures_id` e `@property int|null $admin_level`; aggiunge helper note
2. `$fillable`: rimuove `'osmfeatures_id'`, `'admin_level'`; lascia `['name', 'geometry', 'properties']`
3. `$casts`: aggiunge `'properties' => 'array'` (già nel parent Taxonomy? verifica)
4. Aggiunge due accessor comodi:

```php
public function getOsmfeaturesId(): ?string
{
    return $this->properties['osmfeatures_id'] ?? null;
}

public function getAdminLevel(): ?int
{
    $v = $this->properties['admin_level'] ?? null;
    return $v !== null ? (int) $v : null;
}

public function getSource(): ?string
{
    return $this->properties['source'] ?? null;
}
```

**Nota:** `$casts = ['properties' => 'array']` è già nel parent `Taxonomy` — verificare se già ereditato.

### Verification

```bash
docker exec -it php-forestas php artisan tinker --execute="
\$tw = \Wm\WmPackage\Models\TaxonomyWhere::first();
echo \$tw->getOsmfeaturesId();
"
```

---

## Phase 3: Aggiorna FetchTaxonomyWhereGeometryJob

**File:** `wm-package/src/Jobs/TaxonomyWhere/FetchTaxonomyWhereGeometryJob.php`

- Riga 27: `$taxonomyWhere->osmfeatures_id` → `$taxonomyWhere->getOsmfeaturesId()`
- Riga 32 (log): stesso cambio

Il job va eseguito **solo per record con source = osmfeatures**. Aggiungere guard:

```php
$osmfeaturesId = $taxonomyWhere->getOsmfeaturesId();

if (empty($osmfeaturesId)) {
    Log::warning('TaxonomyWhere non ha osmfeatures_id, skip geometry fetch', [
        'taxonomy_where_id' => $this->taxonomyWhereId,
    ]);
    return;
}

$detail = $client->getAdminAreaDetail($osmfeaturesId);
```

---

## Phase 4: Aggiorna SyncTracksTaxonomyWhereAction SQL

**File:** `wm-package/src/Nova/Actions/SyncTracksTaxonomyWhereAction.php`

La query SQL usa direttamente `tw.osmfeatures_id` e `tw.admin_level` come colonne — ora sono dentro `properties`.

**Cambia:**
```sql
-- PRIMA:
jsonb_object_agg(
    tw.osmfeatures_id,
    jsonb_build_object('name', tw.name, 'admin_level', tw.admin_level)
)

-- DOPO:
jsonb_object_agg(
    COALESCE(tw.properties->>'osmfeatures_id', tw.properties->>'osm2cai_id'::text, tw.id::text),
    jsonb_build_object(
        'name', tw.name,
        'admin_level', (tw.properties->>'admin_level')::int,
        'source', tw.properties->>'source'
    )
)
```

---

## Phase 5: Aggiorna Nova TaxonomyWhere Resource Fields

**File:** `wm-package/src/Nova/TaxonomyWhere.php`

Sostituire i campi `osmfeatures_id` (readonly Text) e `admin_level` (readonly Number) con accesso via properties:

```php
Text::make('osmfeatures_id', fn() => $this->resource->getOsmfeaturesId())->readonly()->onlyOnDetail(),
Number::make('admin_level', fn() => $this->resource->getAdminLevel())->readonly()->onlyOnDetail(),
Text::make('source', fn() => $this->resource->getSource())->readonly()->onlyOnIndex(),
```

---

## Phase 6: Aggiorna OsmfeaturesClient — Restituisce `updated_at`

**File:** `wm-package/src/Http/Clients/OsmfeaturesClient.php:114-121`

Nel loop `foreach ($data as $item)` di `getAdminAreasIds()`, aggiungere `updated_at`:

```php
$items[] = [
    'id'         => $item['id'],
    'name'       => $name,
    'updated_at' => $item['updated_at'] ?? null,  // AGGIUNTO
];
```

---

## Phase 7: Crea `Osm2caiClient`

**Crea:** `wm-package/src/Http/Clients/Osm2caiClient.php`

```php
<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;
use Illuminate\Support\Facades\Http;

class Osm2caiClient
{
    protected string $host = 'https://osm2cai.cai.it';

    /**
     * @return array<array{id: int, updated_at: string, name: string}>
     */
    public function getSectorsList(?string $bbox = null): array
    {
        $params = [];
        if ($bbox !== null) {
            $params['bbox'] = $bbox;
        }

        $response = Http::get("{$this->host}/api/v3/sectors/list", $params);

        if (! $response->successful()) {
            throw new Exception("OSM2CAI sectors/list HTTP {$response->status()}: " . $response->body());
        }

        return array_map(fn($s) => [
            'id'         => $s['id'],
            'updated_at' => $s['updated_at'],
            'name'       => $s['name']['it'] ?? $s['name']['en'] ?? (string) $s['id'],
        ], $response->json() ?? []);
    }

    /**
     * Returns the MultiPolygon geometry as JSON string, or null if not available.
     */
    public function getSectorGeometry(int $id): ?string
    {
        $response = Http::get("{$this->host}/api/v3/sectors/{$id}");

        if (! $response->successful()) {
            throw new Exception("OSM2CAI sectors/{$id} HTTP {$response->status()}: " . $response->body());
        }

        $data = $response->json();

        return isset($data['geometry']) ? json_encode($data['geometry']) : null;
    }
}
```

---

## Phase 8: Crea `ImportTaxonomyWhere` Action

**Crea:** `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php`

Logica handle:
- Parsa `source_type` → branch osmfeatures o osm2cai
- Per osmfeatures: risolve app → bbox → chiama API → upsert via `properties->>'osmfeatures_id'`
- Per osm2cai: chiama lista settori → upsert via `properties->>'osm2cai_id'` (cast a stringa) → fetch geometria inline
- Staleness: se `source_updated_at` in properties del modello >= `updated_at` API → skip

**Lookup pattern (senza colonna dedicata):**
```php
// Per osmfeatures
$existing = TaxonomyWhere::whereRaw("properties->>'osmfeatures_id' = ?", [$item['id']])->first();

// Per osm2cai
$existing = TaxonomyWhere::whereRaw("(properties->>'osm2cai_id')::int = ?", [$sector['id']])->first();
```

**Upsert:**
```php
// Non si usa più updateOrCreate() con chiave di colonna dedicata
// Si aggiorna/crea manualmente tramite lookup + fill + save
if ($existing) {
    $existing->update(['name' => ..., 'properties' => [...merged properties...]]);
} else {
    TaxonomyWhere::create(['name' => ..., 'properties' => [...]]);
}
```

**Select options:**
```php
[
    'osmfeatures_4'  => 'OSMFeatures — Regione (L4)',
    'osmfeatures_6'  => 'OSMFeatures — Provincia (L6)',
    'osmfeatures_8'  => 'OSMFeatures — Comune (L8)',
    'osmfeatures_9'  => 'OSMFeatures — Municipio (L9)',
    'osmfeatures_10' => 'OSMFeatures — Quartiere (L10)',
    'osm2cai'        => 'OSM2CAI — Settori CAI',
]
```

**App select (solo se App::count() > 1):**
```php
$apps = App::all();
if ($apps->count() > 1) {
    $fields[] = Select::make('App', 'app_id')
        ->options($apps->pluck('name', 'id')->toArray())
        ->dependsOn('source_type', function (Select $field, NovaRequest $request, FormData $formData) {
            if ($formData->get('source_type') === 'osm2cai') {
                $field->hide();
            } else {
                $field->rules('required');
            }
        });
}
```

---

## Phase 9: Sposta Action su TaxonomyWhere Resource, Rimuovi da App

**Modifica:** `wm-package/src/Nova/TaxonomyWhere.php`
- Aggiunge `new ImportTaxonomyWhere` in `actions()`

**Modifica:** `app/Nova/App.php`
- Rimuove `use` e `new ImportTaxonomyWhereFromOsmfeatures`
- Se `actions()` diventa identico al parent, elimina l'override

**Elimina:** `wm-package/src/Nova/Actions/ImportTaxonomyWhereFromOsmfeatures.php`

---

## Phase 10: Verifica Finale

### Checklist

- [ ] Migrations girano senza errori; colonne `osmfeatures_id` e `admin_level` non esistono più
- [ ] Indice funzionale creato su `properties->>'osmfeatures_id'`
- [ ] `TaxonomyWhere::first()->getOsmfeaturesId()` restituisce valore corretto
- [ ] `FetchTaxonomyWhereGeometryJob` funziona leggendo da `properties`
- [ ] `SyncTracksTaxonomyWhereAction` SQL usa `properties->>'osmfeatures_id'`
- [ ] `ImportTaxonomyWhere` action visibile in resource TaxonomyWhere
- [ ] `ImportTaxonomyWhere` action NON visibile in resource App
- [ ] Select mostra 6 opzioni con sorgente + admin level
- [ ] Import OSMFeatures: salta record già aggiornati (staleness check)
- [ ] Import OSM2CAI: crea record con `osm2cai_id` in properties + geometria inline
- [ ] `vendor/bin/phpstan analyse` passa

### Anti-pattern da evitare

- NON usare `updateOrCreate` con chiave di colonna dedicata per osmfeatures_id (non esiste più)
- NON chiamare `FetchTaxonomyWhereGeometryJob` per record osm2cai (geometria è inline)
- NON scrivere SQL con `tw.osmfeatures_id` o `tw.admin_level` come colonne dirette
- NON confondere `updated_at` del modello (timestamp Laravel) con `source_updated_at` in properties (timestamp API)
