> Ticket: oc:8641

# L'import da Sardegna Sentieri non scrive più `manual_data` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **In questo progetto i commit sono vietati durante l'esecuzione.** I blocchi `git commit` sono
> istruzioni per il dev, da eseguire solo dopo il review gate. Non lanciare `vendor/bin/pest` senza
> aver prima eseguito il Task 0.

**Goal:** l'import da Sardegna Sentieri smette di scrivere in `properties.manual_data` dell'EcTrack e lascia intatto quello già presente.

**Architecture:** `TrackPropertiesData::fromApiResponse()` passa `manual_data: null` al DTO del package; `EcTrackPropertiesData::toArray()` scarta i campi null, quindi la chiave non esce e l'`array_merge` di `SardegnaSentieriImportService::importTrackFromResponse()` conserva il `manual_data` esistente. Il parametro `$existingManualData` sparisce da DTO e service.

**Tech Stack:** Laravel 12, Pest, PostgreSQL + PostGIS (DB di test `forestas_testing`), container `php-forestas`.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Tutto il codice nel repo `forestas`: nessuna modifica a `wm-package` (`ManualTrackData`, `EcTrackPropertiesData` restano invariati)
- `ApiTrackResponse` resta invariato
- Nessun comando di pulizia dei `manual_data` esistenti
- Commenti e messaggi di commit in italiano, termini tecnici in inglese
- Test solo dopo la verifica di isolamento di `.claude/rules/test.md`
- Non eseguire `composer format` sull'intero progetto: solo sui file toccati

## Review Focus

- API con tutti i 6 valori valorizzati → `toArray()` senza chiave `manual_data` (coperto nel Task 1)
- API con i valori null → nessuna chiave `manual_data`, nessun errore (Task 1)
- EcTrack esistente con `manual_data` inserito da un operatore, incluso `descent` → identico dopo il reimport (Task 2)
- EcTrack nuovo → `properties` senza `manual_data` (Task 2)
- Reimport con `lunghezza` cambiata dall'API → `manual_data` dell'operatore non cambia (Task 2)

---

### Task 0: Verifica dell'isolamento del DB di test

**Files:** nessuna modifica.

- [ ] **Step 1: `phpunit.xml` punta a `forestas_testing`**

Run: `grep -n "DB_DATABASE" phpunit.xml`
Expected: `<env name="DB_DATABASE" value="forestas_testing"/>` (o equivalente `force="true"`)

- [ ] **Step 2: `.env.testing` esiste e punta a `forestas_testing`**

Run: `grep -n "^DB_DATABASE" .env.testing`
Expected: `DB_DATABASE=forestas_testing`

- [ ] **Step 3: il database `forestas_testing` esiste**

Run: `docker exec postgres-forestas psql -U forestas -lqt | cut -d'|' -f1 | grep -w forestas_testing`
Expected: una riga `forestas_testing`

Se uno dei tre check fallisce: **fermarsi e chiedere consenso al dev**, non lanciare i test.

---

### Task 1: `TrackPropertiesData` non valorizza più `manual_data`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1)

**Files:**
- Create: `tests/Unit/Dto/TrackPropertiesDataTest.php`
- Modify: `app/Dto/Import/TrackPropertiesData.php:28-55`

**Interfaces:**
- Produces: `TrackPropertiesData::fromApiResponse(int $externalId, ApiTrackResponse $response): self` — il terzo parametro `$existingManualData` non esiste più

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Api\ApiTrackResponse;
use App\Dto\Import\TrackPropertiesData;

function trackResponse(array $properties = []): ApiTrackResponse
{
    return ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => null,
        'properties' => array_merge([
            'id' => '75',
            'name' => ['it' => 'Sentiero Test'],
            'description' => ['it' => 'Desc'],
            'excerpt' => null,
            'type' => 'sentiero',
            'allegati' => [],
            'video' => [],
            'gpx' => [],
            'url' => null,
            'updated_at' => '2024-01-15T10:00:00',
            'partenza' => null,
            'arrivo' => null,
            'taxonomies' => [],
        ], $properties),
    ]);
}

// I valori di lunghezza, dislivello, durata e quote su Drupal vengono dal vecchio
// calcolo di Webmapp, non da misure: l'import li ignora (oc:8641).
it('non scrive manual_data anche se la API manda lunghezza, dislivello, durata e quote', function () {
    $response = trackResponse([
        'lunghezza' => '5000',
        'dislivello_totale' => '300',
        'durata' => '3600',
        'ele_min' => '120',
        'ele_max' => '420',
    ]);

    $result = TrackPropertiesData::fromApiResponse(75, $response)->toArray();

    expect($result)->not->toHaveKey('manual_data')
        ->and($result['sardegnasentieri_id'])->toBe('75');
});

it('non scrive manual_data quando la API non manda i valori', function () {
    $result = TrackPropertiesData::fromApiResponse(75, trackResponse())->toArray();

    expect($result)->not->toHaveKey('manual_data');
});
```

- [ ] **Step 2: Verificare che il test fallisca**

Run: `docker exec php-forestas vendor/bin/pest tests/Unit/Dto/TrackPropertiesDataTest.php`
Expected: FAIL sul primo test (`manual_data` presente); il secondo può già passare

- [ ] **Step 3: Implementazione minima**

In `app/Dto/Import/TrackPropertiesData.php` sostituire il metodo `fromApiResponse()` con:

```php
    /**
     * L'import non scrive `manual_data`: lunghezza, dislivello, durata e quote su
     * Drupal vengono dal vecchio calcolo di Webmapp, non da misure. `manual_data`
     * resta degli operatori, e con la chiave assente dal DTO l'array_merge del
     * service conserva quello già presente sul sentiero (oc:8641).
     */
    public static function fromApiResponse(int $externalId, ApiTrackResponse $response): self
    {
        return new self(
            description: $response->description,
            excerpt: $response->excerpt,
            manual_data: null,
            ref: $response->codice_cai,
            sardegnasentieri_id: (string) $externalId,
            forestas: ForestasTrackData::fromApiResponse($externalId, $response),
        );
    }
```

Lasciare `use Wm\WmPackage\Dto\ManualTrackData;`: serve ancora al tipo del parametro del costruttore.

- [ ] **Step 4: Verificare che il test passi**

Run: `docker exec php-forestas vendor/bin/pest tests/Unit/Dto/TrackPropertiesDataTest.php`
Expected: PASS (2 test)

---

### Task 2: il service non passa più `manual_data` e i test lo verificano

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2)

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php:411-417`
- Modify: `tests/Feature/Import/SardegnaSentieriImportServiceTest.php:302-314` e `:333-351`

**Interfaces:**
- Consumes: `TrackPropertiesData::fromApiResponse(int $externalId, ApiTrackResponse $response): self` dal Task 1

- [ ] **Step 1: Riscrivere i due test esistenti e aggiungere il test di conservazione**

Nel test `crea un nuovo EcTrack dalla API con GPX` (riga ~302) sostituire l'ultima asserzione:

```php
    expect(EcTrack::count())->toBe(1)
        ->and($track->properties['sardegnasentieri_id'])->toBe('75')
        ->and($track->properties)->not->toHaveKey('manual_data');
```

Nel test `aggiorna un EcTrack esistente senza duplicati` (riga ~333) sostituire l'ultima asserzione:

```php
    expect(EcTrack::count())->toBe(1)
        ->and(EcTrack::first()->properties)->not->toHaveKey('manual_data');
```

Subito dopo quel test aggiungere:

```php
it('conserva il manual_data inserito da un operatore quando reimporta il sentiero (oc:8641)', function () {
    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')
        ->andReturn(minimalTrackFeature(75, ['properties' => ['gpx' => ['http://example.com/track.gpx']]]));
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithoutNamespace());

    (makeServiceWith($client))->importTrack(75);

    $operatorManual = ['distance' => '7.2', 'ascent' => '410', 'descent' => '380', 'duration_forward' => '150'];
    $track = EcTrack::first();
    $track->properties = array_merge($track->properties, ['manual_data' => $operatorManual]);
    $track->saveQuietly();

    $client2 = Mockery::mock(SardegnaSentieriClient::class);
    $client2->shouldReceive('getTrackDetail')
        ->andReturn(minimalTrackFeature(75, ['properties' => ['lunghezza' => '9999', 'durata' => '60']]));

    (makeServiceWith($client2))->importTrack(75);

    expect(EcTrack::first()->properties['manual_data'])->toBe($operatorManual);
});
```

- [ ] **Step 2: Verificare lo stato dei test prima di toccare il service**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Import/SardegnaSentieriImportServiceTest.php --filter="EcTrack|manual_data"`
Expected: PASS. Il comportamento è già cambiato nel Task 1: PHP ignora il terzo argomento che il
service passa ancora a un metodo che ne accetta due. Questo task riscrive i test che affermavano il
contrario e toglie dal service la preparazione ormai inutile. Il test di conservazione, sul codice
di prima, fallirebbe su `distance` sovrascritta con `9999`: è la regressione che protegge

- [ ] **Step 3: Implementazione minima**

In `app/Services/Import/SardegnaSentieriImportService.php` sostituire:

```php
        $existingProperties = is_array($ecTrack->properties) ? $ecTrack->properties : [];
        $existingManualData = is_array($existingProperties['manual_data'] ?? null) ? $existingProperties['manual_data'] : [];

        $data['properties'] = array_merge(
            $existingProperties,
            TrackPropertiesData::fromApiResponse($externalId, $response, $existingManualData)->toArray()
        );
```

con:

```php
        $existingProperties = is_array($ecTrack->properties) ? $ecTrack->properties : [];

        // Il DTO non emette `manual_data`: l'array_merge conserva quello già
        // presente, che è degli operatori (oc:8641).
        $data['properties'] = array_merge(
            $existingProperties,
            TrackPropertiesData::fromApiResponse($externalId, $response)->toArray()
        );
```

- [ ] **Step 4: Verificare che i test passino**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Import/SardegnaSentieriImportServiceTest.php tests/Unit/Dto/TrackPropertiesDataTest.php`
Expected: PASS, nessun test rotto nel file

- [ ] **Step 5: Suite dell'import, PHPStan, formattazione dei file toccati**

Run:
```bash
docker exec php-forestas vendor/bin/pest tests/Feature/Import tests/Unit/Dto
docker exec php-forestas vendor/bin/phpstan analyse app/Dto/Import/TrackPropertiesData.php app/Services/Import/SardegnaSentieriImportService.php
docker exec php-forestas vendor/bin/pint app/Dto/Import/TrackPropertiesData.php app/Services/Import/SardegnaSentieriImportService.php tests/Unit/Dto/TrackPropertiesDataTest.php tests/Feature/Import/SardegnaSentieriImportServiceTest.php
```
Expected: test PASS, PHPStan senza errori nuovi, pint applicato solo ai 4 file

- [ ] **Step 6: Riavviare Horizon** (i worker tengono in memoria le classi vecchie)

Run: `docker restart horizon-forestas`

- [ ] **Step 7: Commit (solo dopo il review gate, lo esegue il dev)**

```bash
git add app/Dto/Import/TrackPropertiesData.php app/Services/Import/SardegnaSentieriImportService.php tests/Unit/Dto/TrackPropertiesDataTest.php tests/Feature/Import/SardegnaSentieriImportServiceTest.php docs/features/8641-import-sardegna-sentieri-non-scrive-manual-data/
git commit -m "feat(oc:8641): l'import da Sardegna Sentieri non scrive più manual_data"
```
