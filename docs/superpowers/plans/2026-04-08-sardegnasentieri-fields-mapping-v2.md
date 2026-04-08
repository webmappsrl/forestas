# Sardegna Sentieri — Field Mapping v2: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completare e correggere il mapping campi Sardegna Sentieri per track e POI secondo la revisione umana del 2026-04-08.

**Architecture:** Le modifiche sono distribuite su DTO, service di import, risorse Nova e un nuovo modello per la relazione POI→POI. Nessuna nuova migrazione eccetto per la tabella pivot `ec_poi_related_pois`. Il mediaSync per track/POI è già implementato via Jobs — non va toccato.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5, wm-package (submodule), PostgreSQL + PostGIS, Pest

**Nota:** NON committare nulla — l'utente gestisce i commit in autonomia.

---

## File map

| File | Azione | Responsabilità |
|---|---|---|
| `app/Dto/Import/ForestasTrackData.php` | Modify | Aggiungere `created_at`, rimuovere `codice_cai` e `type`, spostare `codice_cai` → `ref` |
| `app/Dto/Import/TrackPropertiesData.php` | Modify | Aggiungere `ref` da `codice_cai`, rimuovere mapping `codice_cai` da forestas |
| `app/Services/Import/SardegnaSentieriImportService.php` | Modify | `type` → TaxonomyActivity; `ente_istituzione_societa` per POI; `poi_correlati` POI |
| `app/Models/EcPoi.php` | Create | Relazione `relatedPois()` self-referencing |
| `database/migrations/..._create_ec_poi_related_pois_table.php` | Create | Tabella pivot POI→POI |
| `app/Nova/EcTrack.php` | Modify | Readonly su `sardegnasentieri_id`, `url`, `updated_at`; aggiungere `info_utili`, `roadbook` |
| `app/Nova/EcPoi.php` | Modify | Readonly su `out_source_feature_id`, `url`, `updated_at`; `come_arrivare` come Textarea |
| `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` | Modify | Aggiungere test per nuovi comportamenti |

---

## Task 1: `ForestasTrackData` — aggiungere `created_at`, rimuovere `type` e `codice_cai`

**Files:**
- Modify: `app/Dto/Import/ForestasTrackData.php`

- [ ] **Step 1: Scrivi il test**

In `tests/Feature/Import/SardegnaSentieriImportServiceTest.php` aggiungi:

```php
it('importa created_at nel forestas delle track', function () {
    $feature = minimalTrackFeature(75, ['properties' => [
        'gpx' => ['http://example.com/track.gpx'],
        'created_at' => '2023-05-10T08:00:00',
    ]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithoutNamespace());

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    expect($track->properties['forestas']['created_at'])->toBe('2023-05-10T08:00:00');
});

it('non salva type in forestas ma assegna TaxonomyActivity', function () {
    $feature = minimalTrackFeature(75, ['properties' => [
        'gpx' => ['http://example.com/track.gpx'],
        'type' => 'sentiero',
    ]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithoutNamespace());

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    expect(isset($track->properties['forestas']['type']))->toBeFalse()
        ->and($track->taxonomyActivities()->where('identifier', 'sardegnasentieri:type:sentiero')->exists())->toBeTrue();
});

it('mappa codice_cai su properties->ref invece di forestas->codice_cai', function () {
    $feature = minimalTrackFeature(75, ['properties' => [
        'gpx' => ['http://example.com/track.gpx'],
        'codice_cai' => 'CAI-123',
    ]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithoutNamespace());

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    expect($track->properties['ref'])->toBe('CAI-123')
        ->and(isset($track->properties['forestas']['codice_cai']))->toBeFalse();
});
```

- [ ] **Step 2: Verifica che i test falliscano**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="importa created_at|non salva type|mappa codice_cai"
```

Expected: FAIL

- [ ] **Step 3: Aggiorna `ForestasTrackData`**

```php
<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiTrackResponse;

readonly class ForestasTrackData
{
    public function __construct(
        public string $source_id,
        public array $allegati,
        public array $video,
        public array $gpx,
        public ?string $url,
        public ?string $created_at,
        public ?string $updated_at,
        public array $zona_geografica,
        public ?string $codice,
        public ?string $data_rilievo,
        public ?array $info_utili,
        public ?array $roadbook,
        public bool $skip_dem_jobs = true,
    ) {}

    public static function fromApiResponse(int $externalId, ApiTrackResponse $response): self
    {
        return new self(
            source_id: (string) $externalId,
            allegati: $response->allegati,
            video: $response->video,
            gpx: $response->gpx,
            url: $response->url,
            created_at: $response->created_at ?? null,
            updated_at: $response->updated_at,
            zona_geografica: $response->taxonomies->zona_geografica,
            codice: $response->codice,
            data_rilievo: $response->data_rilievo,
            info_utili: $response->info_utili,
            roadbook: $response->roadbook,
        );
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->source_id,
            'allegati' => self::listToAssoc($this->allegati),
            'video' => self::listToAssoc($this->video),
            'gpx' => self::listToAssoc($this->gpx),
            'url' => $this->url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'zona_geografica' => self::listToAssoc($this->zona_geografica),
            'codice' => $this->codice,
            'data_rilievo' => $this->data_rilievo,
            'info_utili' => $this->info_utili,
            'roadbook' => $this->roadbook,
            'skip_dem_jobs' => $this->skip_dem_jobs,
        ];
    }

    private static function listToAssoc(array $list): array
    {
        if (empty($list)) {
            return [];
        }

        $result = [];
        foreach (array_values($list) as $i => $value) {
            $result[(string) $i] = $value;
        }

        return $result;
    }
}
```

- [ ] **Step 4: Aggiorna `ApiTrackResponse` — aggiungere `created_at`**

In `app/Dto/Api/ApiTrackResponse.php`, aggiungi il campo nel costruttore dopo `$updated_at`:

```php
public ?string $created_at,
```

E nel `fromJson()`, dopo `updated_at`:

```php
created_at: isset($props['created_at']) ? (string) $props['created_at'] : null,
```

- [ ] **Step 5: Aggiorna `TrackPropertiesData` — aggiungere `ref`, rimuovere `codice_cai` da forestas**

In `app/Dto/Import/TrackPropertiesData.php`, modifica `fromApiResponse()`:

```php
public static function fromApiResponse(int $externalId, ApiTrackResponse $response, array $existingManualData = []): self
{
    $apiManual = ManualTrackData::fromArray([
        'distance' => $response->lunghezza,
        'ascent' => $response->dislivello_totale,
        'duration_forward' => $response->durata,
        'duration_backward' => $response->durata,
        'ele_min' => $response->ele_min,
        'ele_max' => $response->ele_max,
    ]);

    $manual = empty($existingManualData)
        ? $apiManual
        : ManualTrackData::merge(ManualTrackData::fromArray($existingManualData), $apiManual);

    return new self(
        description: $response->description,
        excerpt: $response->excerpt,
        manual_data: $manual,
        sardegnasentieri_id: (string) $externalId,
        ref: $response->codice_cai,
        forestas: ForestasTrackData::fromApiResponse($externalId, $response),
    );
}
```

Aggiungi `ref` nel costruttore:

```php
public function __construct(
    ?array $description,
    ?array $excerpt,
    ?ManualTrackData $manual_data,
    public string $sardegnasentieri_id,
    public ?string $ref,
    public ForestasTrackData $forestas,
) {
    parent::__construct(
        description: $description,
        excerpt: $excerpt,
        manual_data: $manual_data,
    );
}
```

E in `toArray()`:

```php
public function toArray(): array
{
    return array_merge(
        parent::toArray(),
        [
            'sardegnasentieri_id' => $this->sardegnasentieri_id,
            'ref' => $this->ref,
            'forestas' => $this->forestas->toArray(),
        ]
    );
}
```

- [ ] **Step 6: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Dto/
```

Expected: no new errors.

---

## Task 2: Import service — `type` → TaxonomyActivity

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`

- [ ] **Step 1: I test del Task 1 coprono già questo — verifica che passino ora**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="non salva type in forestas"
```

Expected: FAIL (ancora da implementare)

- [ ] **Step 2: Aggiungi il metodo `syncTrackType()` e chiamalo in `importTrackFromResponse()`**

In `SardegnaSentieriImportService`, aggiungi dopo `syncTrackWarnings()`:

```php
private function syncTrackType(EcTrack $ecTrack, ApiTrackResponse $response): void
{
    if ($response->type === null || $response->type === '') {
        return;
    }

    $identifier = 'sardegnasentieri:type:' . $response->type;

    $activity = TaxonomyActivity::firstOrCreate(
        ['identifier' => $identifier],
        ['name' => ucfirst($response->type)]
    );

    $currentIds = $ecTrack->taxonomyActivities()->pluck('taxonomy_activities.id')->toArray();
    $allIds = array_unique(array_merge($currentIds, [$activity->id]));
    $ecTrack->taxonomyActivities()->sync($allIds);
}
```

In `importTrackFromResponse()`, aggiungi dopo `$this->syncTrackWarnings($ecTrack, $response);`:

```php
$this->syncTrackType($ecTrack, $response);
```

- [ ] **Step 3: Verifica test**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="non salva type in forestas|importa created_at|mappa codice_cai"
```

Expected: PASS

---

## Task 3: Import service — `ente_istituzione_societa` per POI

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`

- [ ] **Step 1: Scrivi il test**

```php
it('importa ente_istituzione_societa per POI', function () {
    $node = [
        'title' => [['value' => 'Ente Test POI']],
        'field_tipo_ente' => [['target_id' => 4699]],
        'field_contatti' => [],
        'field_pagina_web' => [],
        'field_immagine_principale' => [],
        'body' => [],
        'field_geolocalizzazione' => [],
    ];

    $feature = minimalPoiFeature(42, ['properties' => [
        'ente_istituzione_societa' => ['soggetto_gestore' => '100'],
    ]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getPoiDetail')->andReturn($feature);
    $client->shouldReceive('getNodeDetail')->with(100)->andReturn($node);

    $mediaSync = Mockery::mock(\App\Services\Import\SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldReceive('syncImportedImages')->andReturn(null);

    $service = new SardegnaSentieriImportService($client, $mediaSync);
    $poi = $service->importPoi(42);

    expect(\App\Models\Ente::count())->toBe(1)
        ->and(DB::table('enteables')
            ->where('enteable_id', $poi->id)
            ->where('enteable_type', \Wm\WmPackage\Models\EcPoi::class)
            ->where('ruolo', 'gestore')
            ->exists()
        )->toBeTrue();
});
```

- [ ] **Step 2: Verifica che il test fallisca**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="importa ente_istituzione_societa per POI"
```

Expected: FAIL

- [ ] **Step 3: Aggiungi `syncEntiPoi()` e chiamalo in `importPoiFromResponse()`**

In `SardegnaSentieriImportService`, aggiungi il metodo (stessa logica di `syncEnti()` ma con `EcPoi::class`):

```php
private function syncEntiPoi(EcPoi $ecPoi, ApiPoiResponse $response): void
{
    $ente = $response->ente_istituzione_societa ?? [];

    $pairs = [];

    $ruoliMap = [
        'soggetto_gestore'     => 'gestore',
        'soggetto_manutentore' => 'manutentore',
        'soggetto_rilevatore'  => 'rilevatore',
    ];

    foreach ($ruoliMap as $field => $ruolo) {
        $nodeId = isset($ente[$field]) ? (string) $ente[$field] : null;
        if ($nodeId === null) {
            continue;
        }
        $enteId = $this->resolveOrImportEnte((int) $nodeId);
        if ($enteId !== null) {
            $pairs[] = ['ente_id' => $enteId, 'ruolo' => $ruolo];
        }
    }

    $operatori = is_array($ente['riferimento_operatori_guid'] ?? null)
        ? $ente['riferimento_operatori_guid']
        : [];

    foreach ($operatori as $nodeId) {
        $enteId = $this->resolveOrImportEnte((int) $nodeId);
        if ($enteId !== null) {
            $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'operatore'];
        }
    }

    if (empty($pairs)) {
        return;
    }

    DB::table('enteables')
        ->where('enteable_id', $ecPoi->id)
        ->where('enteable_type', EcPoi::class)
        ->delete();

    foreach ($pairs as $pair) {
        DB::table('enteables')->insertOrIgnore([
            'ente_id'        => $pair['ente_id'],
            'enteable_id'    => $ecPoi->id,
            'enteable_type'  => EcPoi::class,
            'ruolo'          => $pair['ruolo'],
        ]);
    }
}
```

In `importPoiFromResponse()`, aggiungi dopo `$this->syncPoiTaxonomies($ecPoi, $response);`:

```php
$this->syncEntiPoi($ecPoi, $response);
```

- [ ] **Step 4: Verifica test**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="importa ente_istituzione_societa per POI"
```

Expected: PASS

---

## Task 4: Migrazione e modello — relazione POI→POI

**Files:**
- Create: `database/migrations/2026_04_08_000000_create_ec_poi_related_pois_table.php`
- Modify: `app/Models/EcPoi.php`

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
        Schema::create('ec_poi_related_pois', function (Blueprint $table) {
            $table->unsignedBigInteger('ec_poi_id');
            $table->unsignedBigInteger('related_poi_id');
            $table->primary(['ec_poi_id', 'related_poi_id']);
            $table->foreign('ec_poi_id')->references('id')->on('ec_pois')->onDelete('cascade');
            $table->foreign('related_poi_id')->references('id')->on('ec_pois')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_poi_related_pois');
    }
};
```

- [ ] **Step 2: Esegui la migrazione**

```bash
docker exec -it php-forestas php artisan migrate
```

Expected: migrazione applicata senza errori.

- [ ] **Step 3: Aggiungi la relazione `relatedPois()` su `EcPoi` locale**

In `app/Models/EcPoi.php` (creare se non esiste, altrimenti aggiungere):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;

class EcPoi extends WmEcPoi
{
    public function relatedPois(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ec_poi_related_pois',
            'ec_poi_id',
            'related_poi_id'
        );
    }
}
```

- [ ] **Step 4: Scrivi il test**

```php
it('sincronizza poi_correlati dei POI come relazione relatedPois', function () {
    $poi1 = EcPoi::factory()->create(['properties' => ['out_source_feature_id' => '10']]);
    $poi2 = EcPoi::factory()->create(['properties' => ['out_source_feature_id' => '20']]);

    $feature = minimalPoiFeature(42, ['properties' => [
        'poi_correlati' => ['10', '20'],
    ]]);

    $service = makeService(['getPoiDetail' => $feature]);
    $poi = $service->importPoi(42);

    $localPoi = \App\Models\EcPoi::find($poi->id);
    expect($localPoi->relatedPois()->count())->toBe(2);
});
```

- [ ] **Step 5: Aggiungi `syncRelatedPoisForPoi()` in `SardegnaSentieriImportService`**

Aggiungere il metodo e chiamarlo in `importPoiFromResponse()`:

```php
private function syncRelatedPoisForPoi(EcPoi $ecPoi, ApiPoiResponse $response): void
{
    $ids = collect($response->poi_correlati)
        ->map(fn (string $sourceId) => EcPoi::whereRaw(
            "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
            [$sourceId, $sourceId]
        )->value('id'))
        ->filter()
        ->values()
        ->toArray();

    $localPoi = \App\Models\EcPoi::find($ecPoi->id);
    if ($localPoi !== null) {
        $localPoi->relatedPois()->sync($ids);
    }
}
```

In `importPoiFromResponse()`, dopo `$this->syncEntiPoi($ecPoi, $response);`:

```php
$this->syncRelatedPoisForPoi($ecPoi, $response);
```

- [ ] **Step 6: Verifica test**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="sincronizza poi_correlati dei POI"
```

Expected: PASS

- [ ] **Step 7: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Models/EcPoi.php app/Services/Import/SardegnaSentieriImportService.php
```

Expected: no new errors.

---

## Task 5: Nova — readonly fields e tab Forestas

**Files:**
- Modify: `app/Nova/EcTrack.php`
- Modify: `app/Nova/EcPoi.php`

- [ ] **Step 1: Aggiorna `EcTrack` Nova**

In `app/Nova/EcTrack.php`, modifica `getForestasTabFields()`:

```php
public function getForestasTabFields(): array
{
    return [
        Text::make('Sardegna Sentieri ID', 'properties->sardegnasentieri_id')->readonly(),
        Text::make('Source ID', 'properties->forestas->source_id')->readonly(),
        Text::make('URL', 'properties->forestas->url')->readonly(),
        Text::make('Aggiornato il', 'properties->forestas->updated_at')->readonly(),
        Text::make('Creato il', 'properties->forestas->created_at')->readonly(),
        Textarea::make('Info utili', 'properties->forestas->info_utili')->readonly(),
        Textarea::make('Roadbook', 'properties->forestas->roadbook')->readonly(),
        KeyValue::make('Allegati', 'properties->forestas->allegati'),
        KeyValue::make('Video', 'properties->forestas->video'),
        KeyValue::make('GPX', 'properties->forestas->gpx'),
        KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
    ];
}
```

Aggiungi `Textarea` agli import in cima al file:

```php
use Laravel\Nova\Fields\Textarea;
```

- [ ] **Step 2: Aggiorna `EcPoi` Nova**

In `app/Nova/EcPoi.php`, modifica `getForestasTabFields()`:

```php
public function getForestasTabFields(): array
{
    return [
        Text::make('Sardegna Sentieri ID', 'properties->out_source_feature_id')->readonly(),
        Text::make('Codice', 'properties->forestas->codice'),
        Textarea::make('Come arrivare', 'properties->forestas->come_arrivare')->readonly(),
        Text::make('URL', 'properties->forestas->url')->readonly(),
        Text::make('Aggiornato il', 'properties->forestas->updated_at')->readonly(),
        KeyValue::make('Collegamenti', 'properties->forestas->collegamenti'),
        KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
    ];
}
```

- [ ] **Step 3: Verifica PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Nova/EcTrack.php app/Nova/EcPoi.php
```

Expected: no new errors.

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

- [ ] **Test suite**

```bash
docker exec -it php-forestas vendor/bin/pest --filter=sardegnasentieri
```

Expected: tutti i test passano.
