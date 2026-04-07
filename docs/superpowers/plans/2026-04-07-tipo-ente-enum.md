# TipoEnte Enum Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sostituire il campo `tipo_ente` integer raw su `Ente` con un enum forestas-specifico `TipoEnte`, risolto durante l'import tramite l'API Drupal `/taxonomy/term/{tid}` con fallback slug per ID non mappati.

**Architecture:** Enum `TipoEnte: string` con slug forestas come backing value e `getDrupalId()` come unico punto di conoscenza dei Drupal term ID. La colonna `tipo_ente` su `entes` diventa `string nullable`. L'import usa `TipoEnte::fromDrupalId()` e, se l'ID non è mappato, chiama `/taxonomy/term/{tid}?_format=json`, slugifica il label e logga un warning.

**Tech Stack:** PHP 8.4 enums, Laravel 12, Nova 5, Pest tests.

---

## File Map

| File | Azione |
|------|--------|
| `app/Enums/TipoEnte.php` | Crea |
| `database/migrations/2026_04_07_100000_change_tipo_ente_to_string_on_entes.php` | Crea |
| `app/Models/Ente.php` | Modifica cast `tipo_ente` |
| `app/Http/Clients/SardegnaSentieriClient.php` | Aggiungi `getTaxonomyTerm()` |
| `app/Services/Import/SardegnaSentieriImportService.php` | Aggiorna logica `tipo_ente` |
| `app/Nova/Ente.php` | Campo `Select` per `tipo_ente` |
| `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` | Aggiungi test `tipo_ente` |

---

### Task 1: Enum `TipoEnte`

**Files:**
- Create: `app/Enums/TipoEnte.php`

- [ ] **Step 1: Scrivi il test**

In `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` aggiungi in coda:

```php
// ---------------------------------------------------------------------------
// TipoEnte enum
// ---------------------------------------------------------------------------

it('TipoEnte::fromDrupalId ritorna il case corretto per ID noti', function () {
    expect(TipoEnte::fromDrupalId(4699))->toBe(TipoEnte::ComplessoForestale)
        ->and(TipoEnte::fromDrupalId(4700))->toBe(TipoEnte::EntePartner)
        ->and(TipoEnte::fromDrupalId(4701))->toBe(TipoEnte::AltrePubbliche)
        ->and(TipoEnte::fromDrupalId(4702))->toBe(TipoEnte::PrivatoAssociazione)
        ->and(TipoEnte::fromDrupalId(4703))->toBe(TipoEnte::Comune);
});

it('TipoEnte::fromDrupalId ritorna null per ID sconosciuto', function () {
    expect(TipoEnte::fromDrupalId(9999))->toBeNull();
});

it('TipoEnte getDrupalId ritorna il Drupal term ID corretto', function () {
    expect(TipoEnte::ComplessoForestale->getDrupalId())->toBe(4699)
        ->and(TipoEnte::EntePartner->getDrupalId())->toBe(4700)
        ->and(TipoEnte::AltrePubbliche->getDrupalId())->toBe(4701)
        ->and(TipoEnte::PrivatoAssociazione->getDrupalId())->toBe(4702)
        ->and(TipoEnte::Comune->getDrupalId())->toBe(4703);
});
```

Aggiungi l'import in cima al file:
```php
use App\Enums\TipoEnte;
```

- [ ] **Step 2: Verifica che i test falliscono**

```bash
vendor/bin/pest --filter="TipoEnte"
```
Expected: FAIL — `TipoEnte not found`

- [ ] **Step 3: Crea l'enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoEnte: string
{
    case ComplessoForestale   = 'complesso-forestale';
    case EntePartner          = 'ente-partner';
    case AltrePubbliche       = 'altre-pubbliche-istituzioni';
    case PrivatoAssociazione  = 'privato-associazione';
    case Comune               = 'comune';

    public function getDrupalId(): int
    {
        return match ($this) {
            self::ComplessoForestale   => 4699,
            self::EntePartner          => 4700,
            self::AltrePubbliche       => 4701,
            self::PrivatoAssociazione  => 4702,
            self::Comune               => 4703,
        };
    }

    public static function fromDrupalId(int $id): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->getDrupalId() === $id) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::ComplessoForestale   => __('Complesso forestale'),
            self::EntePartner          => __('Ente partner'),
            self::AltrePubbliche       => __('Altre Pubbliche Istituzioni'),
            self::PrivatoAssociazione  => __('Privato/associazione'),
            self::Comune               => __('Comune'),
        };
    }
}
```

- [ ] **Step 4: Verifica che i test passano**

```bash
vendor/bin/pest --filter="TipoEnte"
```
Expected: 3 PASSED

- [ ] **Step 5: Commit**

```bash
git add app/Enums/TipoEnte.php tests/Feature/Import/SardegnaSentieriImportServiceTest.php
git commit -m "feat(enum): add TipoEnte enum with getDrupalId and fromDrupalId"
```

---

### Task 2: Migrazione colonna `tipo_ente` → string

**Files:**
- Create: `database/migrations/2026_04_07_100000_change_tipo_ente_to_string_on_entes.php`

- [ ] **Step 1: Crea la migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entes', function (Blueprint $table) {
            $table->dropColumn('tipo_ente');
        });

        Schema::table('entes', function (Blueprint $table) {
            $table->string('tipo_ente')->nullable()->after('pagina_web');
        });
    }

    public function down(): void
    {
        Schema::table('entes', function (Blueprint $table) {
            $table->dropColumn('tipo_ente');
        });

        Schema::table('entes', function (Blueprint $table) {
            $table->integer('tipo_ente')->nullable()->after('pagina_web');
        });
    }
};
```

> **Nota:** Il `drop` + `add` è necessario perché PostgreSQL non supporta `change()` da integer a string direttamente in Doctrine. I dati esistenti (interi raw) vengono persi — sono comunque invalidi per il nuovo schema.

- [ ] **Step 2: Esegui la migrazione**

```bash
php artisan migrate
```
Expected: `Migrated: 2026_04_07_100000_change_tipo_ente_to_string_on_entes`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_07_100000_change_tipo_ente_to_string_on_entes.php
git commit -m "feat(migration): change tipo_ente on entes from integer to string"
```

---

### Task 3: Cast enum su modello `Ente`

**Files:**
- Modify: `app/Models/Ente.php`

- [ ] **Step 1: Aggiorna il cast**

In `app/Models/Ente.php` modifica il cast da `'tipo_ente' => 'integer'` a `TipoEnte::class`:

```php
use App\Enums\TipoEnte;
```

```php
protected $casts = [
    'properties' => 'array',
    'tipo_ente'  => TipoEnte::class,
];
```

- [ ] **Step 2: Verifica i test esistenti**

```bash
vendor/bin/pest --filter="TipoEnte"
```
Expected: 3 PASSED (i test del Task 1 continuano a passare)

- [ ] **Step 3: Commit**

```bash
git add app/Models/Ente.php
git commit -m "feat(model): cast tipo_ente to TipoEnte enum on Ente model"
```

---

### Task 4: `getTaxonomyTerm()` sul client

**Files:**
- Modify: `app/Http/Clients/SardegnaSentieriClient.php`

- [ ] **Step 1: Aggiungi il metodo al client**

In `app/Http/Clients/SardegnaSentieriClient.php` aggiungi dopo `getNodeDetail()`:

```php
/**
 * Get Drupal taxonomy term by ID.
 * Used as fallback when TipoEnte::fromDrupalId() returns null.
 *
 * @return array<string, mixed>
 *
 * @throws ConnectionException
 */
public function getTaxonomyTerm(int $id): array
{
    $response = Http::timeout(self::TIMEOUT)
        ->get('https://www.sardegnasentieri.it/taxonomy/term/'.$id, ['_format' => 'json']);

    throw_if($response->failed(), \RuntimeException::class, "Failed to fetch taxonomy term {$id}: ".$response->body());

    return $response->json() ?? [];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Clients/SardegnaSentieriClient.php
git commit -m "feat(client): add getTaxonomyTerm() for Drupal taxonomy term resolution"
```

---

### Task 5: Logica import `tipo_ente` con fallback

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`

- [ ] **Step 1: Scrivi i test**

In `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` aggiungi una helper `makeEnteNode()` e i test:

```php
function makeEnteNode(int $tipoEnteId): array
{
    return [
        'title'                      => [['value' => 'Ente Test']],
        'field_tipo_ente'            => [['target_id' => $tipoEnteId]],
        'field_contatti'             => [],
        'field_pagina_web'           => [],
        'field_immagine_principale'  => [],
        'body'                       => [],
        'field_geolocalizzazione'    => [],
    ];
}
```

```php
it('importa tipo_ente mappato come slug enum', function () {
    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getNodeDetail')->with(100)->andReturn(makeEnteNode(4699));
    $client->shouldNotReceive('getTaxonomyTerm');

    $mediaSync = Mockery::mock(\App\Services\Import\SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldReceive('syncImportedImages')->andReturn(null);

    $service = new SardegnaSentieriImportService($client, $mediaSync);
    $service->importOrUpdateEnte('100');

    $ente = \App\Models\Ente::first();
    expect($ente->tipo_ente)->toBe(TipoEnte::ComplessoForestale)
        ->and($ente->getRawOriginal('tipo_ente'))->toBe('complesso-forestale');
});

it('importa tipo_ente non mappato usando slug dal label API con warning', function () {
    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getNodeDetail')->with(200)->andReturn(makeEnteNode(9999));
    $client->shouldReceive('getTaxonomyTerm')->with(9999)->andReturn([
        'name' => [['value' => 'Nuovo Tipo']],
    ]);

    $mediaSync = Mockery::mock(\App\Services\Import\SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldReceive('syncImportedImages')->andReturn(null);

    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->with(\Mockery::pattern('/TipoEnte Drupal ID 9999 non mappato/'));

    $service = new SardegnaSentieriImportService($client, $mediaSync);
    $service->importOrUpdateEnte('200');

    $ente = \App\Models\Ente::first();
    expect($ente->getRawOriginal('tipo_ente'))->toBe('nuovo-tipo');
});

it('importa ente senza tipo_ente quando il campo è assente', function () {
    $node = makeEnteNode(4699);
    $node['field_tipo_ente'] = [];

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getNodeDetail')->with(300)->andReturn($node);
    $client->shouldNotReceive('getTaxonomyTerm');

    $mediaSync = Mockery::mock(\App\Services\Import\SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldReceive('syncImportedImages')->andReturn(null);

    $service = new SardegnaSentieriImportService($client, $mediaSync);
    $service->importOrUpdateEnte('300');

    expect(\App\Models\Ente::first()->tipo_ente)->toBeNull();
});
```

Aggiungi import in cima al test file:
```php
use App\Services\Import\SardegnaSentieriMediaSyncService;
```

- [ ] **Step 2: Verifica che i test falliscono**

```bash
vendor/bin/pest --filter="importa tipo_ente"
```
Expected: FAIL — `importOrUpdateEnte not found` (metodo non ancora pubblico/rinominato)

- [ ] **Step 3: Aggiorna il service**

In `app/Services/Import/SardegnaSentieriImportService.php`:

Aggiungi import:
```php
use App\Enums\TipoEnte;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
```

Rendi pubblico il metodo che importa un ente (attualmente privato). Cerca il metodo che chiama `getNodeDetail` e contiene `$ente->tipo_ente = $tipoEnte` — rinominalo in `importOrUpdateEnte(string $sardegnaSentieriId): ?int` e rendilo `public`.

Sostituisci il blocco `tipo_ente` (righe 624-626):
```php
$tipoEnteId = isset($node['field_tipo_ente'][0]['target_id'])
    ? (int) $node['field_tipo_ente'][0]['target_id']
    : null;
```

Con:
```php
$tipoEnteId = isset($node['field_tipo_ente'][0]['target_id'])
    ? (int) $node['field_tipo_ente'][0]['target_id']
    : null;

$tipoEnteSlug = null;
if ($tipoEnteId !== null) {
    $enum = TipoEnte::fromDrupalId($tipoEnteId);
    if ($enum !== null) {
        $tipoEnteSlug = $enum->value;
    } else {
        try {
            $term = $this->client->getTaxonomyTerm($tipoEnteId);
            $label = $term['name'][0]['value'] ?? null;
            if ($label !== null) {
                $tipoEnteSlug = Str::slug($label);
                Log::warning("TipoEnte Drupal ID {$tipoEnteId} non mappato nell'enum: slug '{$tipoEnteSlug}'");
            }
        } catch (\Exception) {
            Log::warning("TipoEnte Drupal ID {$tipoEnteId} non risolvibile via API");
        }
    }
}
```

Sostituisci la riga `$ente->tipo_ente = $tipoEnte;` con:
```php
$ente->tipo_ente = $tipoEnteSlug;
```

> **Nota ID mancanti:** Se i test o un import reale producono warning con slug non mappati, aggiungi i case mancanti all'enum seguendo il pattern esistente: chiama `/taxonomy/term/{id}?_format=json`, verifica il label, aggiungi il case con slug e `getDrupalId()`.

- [ ] **Step 4: Verifica che i test passano**

```bash
vendor/bin/pest --filter="importa tipo_ente"
```
Expected: 3 PASSED

- [ ] **Step 5: Commit**

```bash
git add app/Services/Import/SardegnaSentieriImportService.php tests/Feature/Import/SardegnaSentieriImportServiceTest.php
git commit -m "feat(import): resolve tipo_ente via TipoEnte enum with slug fallback"
```

---

### Task 6: Nova `Ente` — campo Select

**Files:**
- Modify: `app/Nova/Ente.php`

- [ ] **Step 1: Aggiorna il campo `tipo_ente`**

In `app/Nova/Ente.php` aggiungi import:
```php
use App\Enums\TipoEnte;
use Laravel\Nova\Fields\Select;
```

Sostituisci:
```php
Text::make('Tipo Ente (ID)', 'tipo_ente')->readonly(),
```

Con:
```php
Select::make('Tipo Ente', 'tipo_ente')
    ->options(collect(TipoEnte::cases())->mapWithKeys(
        fn (TipoEnte $case) => [$case->value => $case->label()]
    )->toArray())
    ->displayUsingLabels()
    ->readonly(),
```

- [ ] **Step 2: Verifica i test esistenti**

```bash
vendor/bin/pest --filter="TipoEnte|importa tipo_ente"
```
Expected: 6 PASSED

- [ ] **Step 3: Commit**

```bash
git add app/Nova/Ente.php
git commit -m "feat(nova): show tipo_ente as Select with TipoEnte enum labels on Ente resource"
```

---

## Self-Review

**Spec coverage:**
- ✅ Enum `TipoEnte` con `getDrupalId()`, `fromDrupalId()`, `label()` → Task 1
- ✅ Migrazione `tipo_ente` integer → string → Task 2
- ✅ Cast `TipoEnte::class` su `Ente` → Task 3
- ✅ `getTaxonomyTerm()` su client → Task 4
- ✅ Logica import con fallback slug + warning → Task 5
- ✅ Nova `Select` per `tipo_ente` → Task 6
- ✅ Nota implementazione per ID mancanti → Task 5, Step 3

**Placeholder scan:** nessun TBD o TODO nel piano.

**Type consistency:** `TipoEnte::fromDrupalId(int)` usato in Task 1 (test) e Task 5 (impl). `importOrUpdateEnte(string)` definito e testato in Task 5. `getTaxonomyTerm(int)` definito in Task 4 e usato in Task 5. Coerente.
