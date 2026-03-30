# Forestas Tab Nova Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere una tab "Forestas" nelle risorse Nova EcPoi e EcTrack che esponga i campi `properties->forestas` editabili campo per campo, adattando i DTO dell'import per compatibilità con il campo `KeyValue` di Nova.

**Architecture:** I DTO `ForestasPoiData` e `ForestasTrackData` convertono le liste PHP in array associativi con chiavi stringa prima della persistenza. Le risorse Nova app-level `App\Nova\EcPoi` e `App\Nova\EcTrack` aggiungono un `Tab::group("Forestas")` con metodo `getForestasTabFields()`, seguendo il pattern del progetto (cfr. `wm-package/src/Nova/EcTrack.php`).

**Tech Stack:** Laravel Nova 5, `Laravel\Nova\Fields\Text`, `Laravel\Nova\Fields\Textarea`, `Laravel\Nova\Fields\KeyValue`, `Laravel\Nova\Tabs\Tab`, Pest PHP

---

## File Map

| File | Azione | Responsabilità |
|---|---|---|
| `app/Dto/Import/ForestasPoiData.php` | Modifica | Converte `collegamenti`, `zona_geografica` da lista a array associativo in `toArray()` |
| `app/Dto/Import/ForestasTrackData.php` | Modifica | Converte `allegati`, `video`, `gpx`, `zona_geografica` da lista a array associativo in `toArray()` |
| `app/Nova/EcPoi.php` | Modifica | Aggiunge `fields()` e `getForestasTabFields()` con tab Forestas |
| `app/Nova/EcTrack.php` | Modifica | Aggiunge `getForestasTabFields()` e tab Forestas in `fields()` |
| `tests/Unit/Dto/ForestasPoiDataTest.php` | Crea | Test unitari per la conversione array in `ForestasPoiData::toArray()` |
| `tests/Unit/Dto/ForestasTrackDataTest.php` | Crea | Test unitari per la conversione array in `ForestasTrackData::toArray()` |

---

## Task 1: Test e fix ForestasPoiData — conversione liste in array associativi

**Files:**
- Create: `tests/Unit/Dto/ForestasPoiDataTest.php`
- Modify: `app/Dto/Import/ForestasPoiData.php`

- [ ] **Step 1: Crea il file di test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Import\ForestasPoiData;
use PHPUnit\Framework\Attributes\Test;

it('converts collegamenti list to associative array in toArray', function () {
    $data = new ForestasPoiData(
        codice: 'A001',
        collegamenti: ['url-a', 'url-b'],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [],
    );

    $result = $data->toArray();

    expect($result['collegamenti'])->toBe(['0' => 'url-a', '1' => 'url-b']);
});

it('converts zona_geografica list to associative array in toArray', function () {
    $data = new ForestasPoiData(
        codice: null,
        collegamenti: [],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [42, 99],
    );

    $result = $data->toArray();

    expect($result['zona_geografica'])->toBe(['0' => 42, '1' => 99]);
});

it('returns empty array for empty lists', function () {
    $data = new ForestasPoiData(
        codice: null,
        collegamenti: [],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [],
    );

    $result = $data->toArray();

    expect($result['collegamenti'])->toBe([]);
    expect($result['zona_geografica'])->toBe([]);
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisce**

```bash
vendor/bin/pest tests/Unit/Dto/ForestasPoiDataTest.php -v
```

Expected: FAIL — i valori sono ancora liste `[0, 1, 2]` non array associativi con chiavi stringa.

- [ ] **Step 3: Modifica `ForestasPoiData::toArray()`**

Apri `app/Dto/Import/ForestasPoiData.php`. Sostituisci:

```php
public function toArray(): array
{
    return get_object_vars($this);
}
```

con:

```php
public function toArray(): array
{
    return [
        'codice'          => $this->codice,
        'collegamenti'    => self::listToAssoc($this->collegamenti),
        'come_arrivare'   => $this->come_arrivare,
        'url'             => $this->url,
        'updated_at'      => $this->updated_at,
        'zona_geografica' => self::listToAssoc($this->zona_geografica),
    ];
}

/**
 * Converte una lista PHP (array indicizzato numericamente) in array associativo
 * con chiavi stringa ("0", "1", ...) compatibile con il campo KeyValue di Nova.
 *
 * @param  list<mixed>  $list
 * @return array<string, mixed>
 */
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
```

- [ ] **Step 4: Esegui il test per verificare che passa**

```bash
vendor/bin/pest tests/Unit/Dto/ForestasPoiDataTest.php -v
```

Expected: PASS (3 test passano)

- [ ] **Step 5: Commit**

```bash
git add app/Dto/Import/ForestasPoiData.php tests/Unit/Dto/ForestasPoiDataTest.php
git commit -m "feat(import): convert ForestasPoiData list fields to assoc arrays for Nova KeyValue"
```

---

## Task 2: Test e fix ForestasTrackData — conversione liste in array associativi

**Files:**
- Create: `tests/Unit/Dto/ForestasTrackDataTest.php`
- Modify: `app/Dto/Import/ForestasTrackData.php`

- [ ] **Step 1: Crea il file di test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Import\ForestasTrackData;

it('converts allegati list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '42',
        type: null,
        allegati: ['http://example.com/a.pdf', 'http://example.com/b.pdf'],
        video: [],
        gpx: [],
        url: null,
        updated_at: null,
        zona_geografica: [],
    );

    $result = $data->toArray();

    expect($result['allegati'])->toBe([
        '0' => 'http://example.com/a.pdf',
        '1' => 'http://example.com/b.pdf',
    ]);
});

it('converts video list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        type: null,
        allegati: [],
        video: ['https://youtu.be/abc', 'https://youtu.be/xyz'],
        gpx: [],
        url: null,
        updated_at: null,
        zona_geografica: [],
    );

    $result = $data->toArray();

    expect($result['video'])->toBe([
        '0' => 'https://youtu.be/abc',
        '1' => 'https://youtu.be/xyz',
    ]);
});

it('converts gpx list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        type: null,
        allegati: [],
        video: [],
        gpx: ['https://example.com/track.gpx'],
        url: null,
        updated_at: null,
        zona_geografica: [],
    );

    $result = $data->toArray();

    expect($result['gpx'])->toBe(['0' => 'https://example.com/track.gpx']);
});

it('converts zona_geografica list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        type: null,
        allegati: [],
        video: [],
        gpx: [],
        url: null,
        updated_at: null,
        zona_geografica: [10, 20],
    );

    $result = $data->toArray();

    expect($result['zona_geografica'])->toBe(['0' => 10, '1' => 20]);
});

it('preserves skip_dem_jobs in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        type: null,
        allegati: [],
        video: [],
        gpx: [],
        url: null,
        updated_at: null,
        zona_geografica: [],
        skip_dem_jobs: true,
    );

    expect($data->toArray()['skip_dem_jobs'])->toBeTrue();
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisce**

```bash
vendor/bin/pest tests/Unit/Dto/ForestasTrackDataTest.php -v
```

Expected: FAIL — i valori sono ancora liste indicizzate numericamente.

- [ ] **Step 3: Modifica `ForestasTrackData::toArray()`**

Apri `app/Dto/Import/ForestasTrackData.php`. Sostituisci:

```php
public function toArray(): array
{
    return get_object_vars($this);
}
```

con:

```php
public function toArray(): array
{
    return [
        'source_id'       => $this->source_id,
        'type'            => $this->type,
        'allegati'        => self::listToAssoc($this->allegati),
        'video'           => self::listToAssoc($this->video),
        'gpx'             => self::listToAssoc($this->gpx),
        'url'             => $this->url,
        'updated_at'      => $this->updated_at,
        'zona_geografica' => self::listToAssoc($this->zona_geografica),
        'skip_dem_jobs'   => $this->skip_dem_jobs,
    ];
}

/**
 * Converte una lista PHP (array indicizzato numericamente) in array associativo
 * con chiavi stringa ("0", "1", ...) compatibile con il campo KeyValue di Nova.
 *
 * @param  list<mixed>  $list
 * @return array<string, mixed>
 */
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
```

- [ ] **Step 4: Esegui il test per verificare che passa**

```bash
vendor/bin/pest tests/Unit/Dto/ForestasTrackDataTest.php -v
```

Expected: PASS (5 test passano)

- [ ] **Step 5: Commit**

```bash
git add app/Dto/Import/ForestasTrackData.php tests/Unit/Dto/ForestasTrackDataTest.php
git commit -m "feat(import): convert ForestasTrackData list fields to assoc arrays for Nova KeyValue"
```

---

## Task 3: Tab Forestas su App\Nova\EcPoi

**Files:**
- Modify: `app/Nova/EcPoi.php`

- [ ] **Step 1: Riscrivi `app/Nova/EcPoi.php`**

```php
<?php

namespace App\Nova;

use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public function fields(NovaRequest $request): array
    {
        return array_merge(parent::fields($request), [
            Tab::group(__('Forestas'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
            ]),
        ]);
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Codice', 'properties->forestas->codice'),
            Textarea::make('Come arrivare', 'properties->forestas->come_arrivare'),
            Text::make('URL', 'properties->forestas->url'),
            Text::make('Aggiornato il', 'properties->forestas->updated_at'),
            KeyValue::make('Collegamenti', 'properties->forestas->collegamenti'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
```

- [ ] **Step 2: Verifica sintassi PHP**

```bash
php -l app/Nova/EcPoi.php
```

Expected: `No syntax errors detected in app/Nova/EcPoi.php`

- [ ] **Step 3: Verifica che Nova non ha errori di caricamento**

```bash
docker exec -it php-forestas php artisan nova:check 2>/dev/null || docker exec -it php-forestas php artisan route:list --path=nova-api/ec-pois 2>&1 | head -5
```

Se il comando non è disponibile, usa:

```bash
docker exec -it php-forestas php artisan tinker --execute="echo App\Nova\EcPoi::class;"
```

Expected: nessun errore fatale

- [ ] **Step 4: Commit**

```bash
git add app/Nova/EcPoi.php
git commit -m "feat(nova): add Forestas tab to EcPoi resource"
```

---

## Task 4: Tab Forestas su App\Nova\EcTrack

**Files:**
- Modify: `app/Nova/EcTrack.php`

- [ ] **Step 1: Aggiorna `app/Nova/EcTrack.php`**

```php
<?php

namespace App\Nova;

use App\Enums\StatoValidazione;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    public function fields(NovaRequest $request): array
    {
        return array_merge(parent::fields($request), [
            Select::make('Stato validazione', 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
            Tab::group(__('Forestas'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
            ]),
        ]);
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Source ID', 'properties->forestas->source_id'),
            Text::make('Tipo', 'properties->forestas->type'),
            Text::make('URL', 'properties->forestas->url'),
            Text::make('Aggiornato il', 'properties->forestas->updated_at'),
            KeyValue::make('Allegati', 'properties->forestas->allegati'),
            KeyValue::make('Video', 'properties->forestas->video'),
            KeyValue::make('GPX', 'properties->forestas->gpx'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
```

- [ ] **Step 2: Verifica sintassi PHP**

```bash
php -l app/Nova/EcTrack.php
```

Expected: `No syntax errors detected in app/Nova/EcTrack.php`

- [ ] **Step 3: Verifica caricamento**

```bash
docker exec -it php-forestas php artisan tinker --execute="echo App\Nova\EcTrack::class;"
```

Expected: nessun errore fatale

- [ ] **Step 4: Commit**

```bash
git add app/Nova/EcTrack.php
git commit -m "feat(nova): add Forestas tab to EcTrack resource"
```

---

## Task 5: Verifica finale suite test

- [ ] **Step 1: Esegui tutti i test Unit**

```bash
vendor/bin/pest tests/Unit/ -v
```

Expected: tutti i test passano, inclusi i nuovi `ForestasPoiDataTest` e `ForestasTrackDataTest`.

- [ ] **Step 2: Verifica in browser (manuale)**

Apri un EcPoi o EcTrack importato da Sardegna Sentieri in Nova. Verifica che:
- La tab "Forestas" è visibile nella sezione Details
- I campi scalari (`codice`, `url`, `updated_at`, ecc.) mostrano il valore corretto
- I campi `KeyValue` (`collegamenti`, `zona_geografica`, ecc.) mostrano le coppie chiave/valore
