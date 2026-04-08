# Sardegna Sentieri — Field Mapping Completo: Piano di Implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completare il mapping di tutti i campi Sardegna Sentieri (track + POI) con import enti, TaxonomyWarning, geometry fallback, poi_correlati e from/to.

**Architecture:** La maggior parte dell'implementazione è già completata in una sessione precedente. Rimangono 3 task: fix bug nel modello Ente, creazione Nova resource Ente, registrazione nel menu Nova.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5, wm-package (submodule), PostgreSQL + PostGIS

---

## Stato al momento della stesura del piano

I seguenti file sono già implementati e **non vanno toccati**:

| File | Stato |
|---|---|
| `app/Dto/Api/ApiTrackResponse.php` | ✅ completo |
| `app/Dto/Api/ApiPoiResponse.php` | ✅ completo |
| `app/Dto/Api/ApiTaxonomiesData.php` | ✅ completo |
| `app/Dto/Import/ForestasTrackData.php` | ✅ completo |
| `app/Dto/Import/ForestasPoiData.php` | ✅ completo |
| `app/Dto/Import/TrackPropertiesData.php` | ✅ completo |
| `app/Dto/Import/PoiPropertiesData.php` | ✅ completo |
| `app/Http/Clients/SardegnaSentieriClient.php` | ✅ completo |
| `app/Services/Import/SardegnaSentieriImportService.php` | ✅ completo |
| `app/Models/TaxonomyWarning.php` | ✅ completo |
| `app/Models/TaxonomyWarningable.php` | ✅ completo |
| `app/Models/Enteable.php` | ✅ completo |
| `app/Models/EcTrack.php` | ✅ completo |
| `app/Nova/TaxonomyWarning.php` | ✅ completo |
| `database/migrations/2026_04_02_120000_create_taxonomy_warnings_table.php` | ✅ completo |
| `database/migrations/2026_04_02_120001_create_taxonomy_warningables_table.php` | ✅ completo |
| `database/migrations/2026_04_02_120002_create_enteables_table.php` | ✅ completo |

---

## Task 1: Fix modello Ente

**Files:**
- Modify: `app/Models/Ente.php`

**Problema:** Il modello ha due bug:
1. Manca `use App\Models\EcTrack;`
2. `morphToMany` è sbagliato: Ente è il lato "owner" della pivot (ha `ente_id`), non il lato morph. Va usato `morphedByMany` con FK esplicito `ente_id`.

**Contesto pivot table `enteables`:**
```
id | ente_id (FK → ec_pois) | enteable_id | enteable_type | ruolo
```
- `EcTrack::enti()` usa `morphToMany(Ente::class, 'enteable')` → EcTrack è il lato morph ✅
- `Ente::ecTracks()` deve usare `morphedByMany(EcTrack::class, 'enteable', 'enteables', 'ente_id')` → Ente è il lato owner

- [ ] **Step 1: Fix il file**

Sostituire l'intero contenuto di `app/Models/Ente.php` con:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\EcPoi;

class Ente extends EcPoi
{
    /**
     * Tracks dove questo ente ha un ruolo specifico.
     */
    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'enteable', 'enteables', 'ente_id')
            ->using(Enteable::class)
            ->withPivot('ruolo');
    }
}
```

- [ ] **Step 2: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Models/Ente.php
```

Expected: no errors (o solo baseline warnings pre-esistenti).

---

## Task 2: Nova resource Ente

**Files:**
- Create: `app/Nova/Ente.php`

Il modello `Ente` estende `EcPoi`. La Nova resource deve estendere `App\Nova\EcPoi` (il resource locale, non wm-package) per ereditare i tab Forestas già definiti, e aggiungere:
- Campo `ruolo` letto dalla pivot `enteables` (read-only nella resource Ente)
- MorphToMany verso EcTrack

- [ ] **Step 1: Crea il file**

```php
<?php

declare(strict_types=1);

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Ente extends EcPoi
{
    public static $model = \App\Models\Ente::class;

    public static function label(): string
    {
        return 'Enti';
    }

    public static function singularLabel(): string
    {
        return 'Ente';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MorphToMany::make('Track associati', 'ecTracks', EcTrack::class)
                ->display('name')
                ->fields(fn () => [
                    Text::make('Ruolo', 'ruolo')->readonly(),
                ]),
        ];
    }
}
```

- [ ] **Step 2: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Nova/Ente.php
```

Expected: no errors.

---

## Task 3: Registrare Ente in NovaServiceProvider

**Files:**
- Modify: `app/Providers/NovaServiceProvider.php`

L'ente è concettualmente un EcPoi speciale — va nella sezione EC del menu Nova.

- [ ] **Step 1: Aggiungi import**

In `app/Providers/NovaServiceProvider.php`, aggiungi dopo la riga `use App\Nova\EcTrack;`:

```php
use App\Nova\Ente;
```

- [ ] **Step 2: Aggiungi voce menu**

Nella sezione EC del menu (array `MenuSection::make('EC', [...])`), aggiungi `MenuItem::resource(Ente::class)` dopo `EcPoi`:

```php
MenuSection::make('EC', [
    MenuItem::resource(EcPoi::class),
    MenuItem::resource(Ente::class),
    MenuItem::resource(EcTrack::class),
    MenuItem::resource(Layer::class),
    MenuItem::resource(FeatureCollection::class),
])->icon('document'),
```

- [ ] **Step 3: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Providers/NovaServiceProvider.php
```

Expected: no errors.

---

## Verifica finale

- [ ] **PHPStan full**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse
```

Expected: no new errors rispetto alla baseline.

- [ ] **composer format**

```bash
docker exec -it php-forestas composer format
```

- [ ] **Test**

```bash
docker exec -it php-forestas vendor/bin/pest --filter=sardegnasentieri
```

Se non esistono test specifici, verificare almeno che l'autoload non produca errori:

```bash
docker exec -it php-forestas php artisan optimize:clear
docker exec -it php-forestas php artisan about
```
