# Plan: TaxonomyWhere Filters + CreateLayer Action

## Goal

1. **3 filtri** sulla resource Nova `TaxonomyWhere` per navigazione agevole
2. **Action `CreateLayerFromTaxonomyWhere`** che funziona in bulk: crea un `Layer` per ogni `TaxonomyWhere` selezionato, con name dalla taxonomy e relazione pivot automatica

---

## Phase 0: Documentation Discovery (DONE)

### Pattern filtri confermati

**Riferimento:** `wm-package/src/Nova/Filters/EcPoiRegionFilter.php`
- Estende `Laravel\Nova\Filters\Filter`
- `$component = 'select-filter'`
- `apply(Request $request, $query, $value)` — modifica il query builder
- `options(Request $request): array` — chiave = valore filtro, valore = label

**Riferimento:** `wm-package/src/Nova/Filters/HasMediaFilter.php`
- Stessa struttura, options booleane (`'true'/'false'`)

**Dove registrare i filtri:** `wm-package/src/Nova/TaxonomyWhere.php` — aggiungere metodo `filters(NovaRequest $request): array`

### Pattern action bulk confermato

**Riferimento:** `wm-package/src/Nova/Actions/SyncTracksTaxonomyWhereAction.php`
- Estende `Laravel\Nova\Actions\Action`
- `use InteractsWithQueue, Queueable`
- `handle(ActionFields $fields, Collection $models)` — `$models` sono i TaxonomyWhere selezionati
- Per standalone=false (default), l'action appare sul bulk select della lista

### Layer creation confermata

**Riferimento:** `wm-package/src/Models/Layer.php:50-58` (fillable)
```php
['name', 'properties', 'configuration', 'app_id', 'geometry', 'feature_collection', 'user_id']
```
- Boot hook `creating`: auto-assegna `app_id` se una sola app — `Layer.php:37-39`
- Relazione `taxonomyWheres()`: MorphToMany via `TaxonomyWhereable` — `Layer.php:103-107`
- Attach pivot: `$layer->taxonomyWheres()->attach($taxonomyWhere->id)`

### Schema properties TaxonomyWhere

Valori rilevanti dentro `properties`:
- `properties->>'source'` → `'osmfeatures'` | `'osm2cai'`
- `(properties->>'admin_level')::int` → `4, 6, 8, 9, 10` (solo osmfeatures)
- `geometry IS NULL/NOT NULL` → presenza geometria

### App select pattern

**Riferimento:** `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php:fields()`
```php
$apps = App::all();
if ($apps->count() > 1) {
    $fields[] = Select::make('App', 'app_id')->options(...)->rules('required');
}
```

---

## Phase 1: Crea i 3 Filtri

### File da creare

#### 1. `TaxonomyWhereSourceFilter.php`
**Crea:** `wm-package/src/Nova/Filters/TaxonomyWhereSourceFilter.php`

Filtra per sorgente (`osmfeatures` o `osm2cai`).
Query: `whereRaw("properties->>'source' = ?", [$value])`

```php
<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereSourceFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Sorgente');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->whereRaw("properties->>'source' = ?", [$value]);
    }

    public function options(Request $request): array
    {
        return [
            'OSMFeatures' => 'osmfeatures',
            'OSM2CAI'     => 'osm2cai',
        ];
    }
}
```

#### 2. `TaxonomyWhereAdminLevelFilter.php`
**Crea:** `wm-package/src/Nova/Filters/TaxonomyWhereAdminLevelFilter.php`

Filtra per admin level (solo significativo per osmfeatures).
Query: `whereRaw("(properties->>'admin_level')::int = ?", [$value])`

```php
<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereAdminLevelFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Admin Level');
    }

    public function apply(Request $request, $query, $value)
    {
        return $query->whereRaw("(properties->>'admin_level')::int = ?", [(int) $value]);
    }

    public function options(Request $request): array
    {
        return [
            'Regione (L4)'   => 4,
            'Provincia (L6)' => 6,
            'Comune (L8)'    => 8,
            'Municipio (L9)' => 9,
            'Quartiere (L10)'=> 10,
        ];
    }
}
```

#### 3. `TaxonomyWhereHasGeometryFilter.php`
**Crea:** `wm-package/src/Nova/Filters/TaxonomyWhereHasGeometryFilter.php`

Filtra per presenza/assenza di geometria.

```php
<?php

namespace Wm\WmPackage\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyWhereHasGeometryFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Geometria');
    }

    public function apply(Request $request, $query, $value)
    {
        return $value === 'yes'
            ? $query->whereNotNull('geometry')
            : $query->whereNull('geometry');
    }

    public function options(Request $request): array
    {
        return [
            __('Presente') => 'yes',
            __('Assente')  => 'no',
        ];
    }
}
```

### Registra i filtri nella resource

**Modifica:** `wm-package/src/Nova/TaxonomyWhere.php`

Aggiungere il metodo `filters()` e i relativi `use`:

```php
use Wm\WmPackage\Nova\Filters\TaxonomyWhereAdminLevelFilter;
use Wm\WmPackage\Nova\Filters\TaxonomyWhereHasGeometryFilter;
use Wm\WmPackage\Nova\Filters\TaxonomyWhereSourceFilter;

public function filters(NovaRequest $request): array
{
    return [
        new TaxonomyWhereSourceFilter,
        new TaxonomyWhereAdminLevelFilter,
        new TaxonomyWhereHasGeometryFilter,
    ];
}
```

### Verification

```bash
# Grep: i 3 file esistono
ls wm-package/src/Nova/Filters/TaxonomyWhere*.php

# Grep: i filtri sono registrati nella resource
grep -n "TaxonomyWhereSourceFilter\|filters(" wm-package/src/Nova/TaxonomyWhere.php
```

---

## Phase 2: Crea `CreateLayerFromTaxonomyWhere` Action

### File da creare

**Crea:** `wm-package/src/Nova/Actions/CreateLayerFromTaxonomyWhere.php`

Logica:
- Funziona su `$models` (TaxonomyWhere selezionati) — NO `$standalone = true`
- Se più app: mostra Select `app_id` (pattern copiato da `ImportTaxonomyWhere.php:fields()`)
- Per ogni taxonomy where:
  1. Crea `Layer` con `name` dalla taxonomy, `app_id` risolto, `user_id` dall'auth user
  2. Attacca la taxonomy where al layer via `$layer->taxonomyWheres()->attach($taxonomyWhere->id)`
- Restituisce messaggio con count creati

**Pattern fillable Layer** (da `Layer.php:50-58`):
```php
Layer::create([
    'name'    => $taxonomyWhere->name,
    'app_id'  => $appId,   // null se una sola app (boot hook lo assegna auto)
    'user_id' => auth()->id(),
]);
```

**Pattern attach pivot** (da `Layer.php:103-107` + `TaxonomyWhereable`):
```php
$layer->taxonomyWheres()->attach($taxonomyWhere->id);
```

```php
<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class CreateLayerFromTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Crea Layer');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $apps = App::all();

        if ($apps->count() === 1) {
            $appId = null; // boot hook assegna auto
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }
        }

        $count = 0;

        foreach ($models as $taxonomyWhere) {
            $layer = Layer::create([
                'name'    => $taxonomyWhere->name,
                'app_id'  => $appId,
                'user_id' => auth()->id(),
            ]);

            $layer->taxonomyWheres()->attach($taxonomyWhere->id);
            $count++;
        }

        return Action::message("Creati {$count} layer.");
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];

        $apps = App::all();
        if ($apps->count() > 1) {
            $fields[] = Select::make('App', 'app_id')
                ->options($apps->pluck('name', 'id')->toArray())
                ->rules('required');
        }

        return $fields;
    }
}
```

### Registra l'action nella resource

**Modifica:** `wm-package/src/Nova/TaxonomyWhere.php`

Aggiungere `new CreateLayerFromTaxonomyWhere` in `actions()`:

```php
use Wm\WmPackage\Nova\Actions\CreateLayerFromTaxonomyWhere;

public function actions(NovaRequest $request): array
{
    return [
        new ImportTaxonomyWhere,
        new CreateLayerFromTaxonomyWhere,
        new RetryTaxonomyWhereGeometryFetch,
        new SyncTracksTaxonomyWhereAction,
    ];
}
```

### Verification

```bash
# File esiste
ls wm-package/src/Nova/Actions/CreateLayerFromTaxonomyWhere.php

# Registrata nella resource
grep -n "CreateLayerFromTaxonomyWhere" wm-package/src/Nova/TaxonomyWhere.php

# Nessun $standalone = true nell'action (deve girare su modelli selezionati)
grep "standalone" wm-package/src/Nova/Actions/CreateLayerFromTaxonomyWhere.php
# → nessun risultato atteso
```

---

## Phase 3: Verifica Finale

### Checklist

- [ ] 3 file filtri esistono in `wm-package/src/Nova/Filters/TaxonomyWhere*.php`
- [ ] `TaxonomyWhere.php` ha metodo `filters()` con i 3 filtri
- [ ] `CreateLayerFromTaxonomyWhere.php` esiste, NO `$standalone`
- [ ] Action registrata in `TaxonomyWhere::actions()`
- [ ] `vendor/bin/phpstan analyse` non introduce nuovi errori

### Anti-pattern da evitare

- NON usare `->osmfeatures_id` o `->admin_level` come colonne dirette (sono in `properties`)
- NON dimenticare `->attach()` dopo la creazione del layer (altrimenti non c'è la relazione)
- NON aggiungere `$standalone = true` all'action (deve girare sui record selezionati)
- NON filtrare `admin_level` come colonna diretta: usare `(properties->>'admin_level')::int`
