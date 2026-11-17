<?php

declare(strict_types=1);

use App\Jobs\Import\RecalculatePoiTracksForDistanceJob;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;

/**
 * Cambiare in Nova la distanza dei percorsi vicini al POI ricalcola i collegamenti dei POI
 * (oc:8959): solo se il valore cambia davvero, e solo per l'App dell'import.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Il proprio fake sostituisce quello di TestCase: i job del package che il salvataggio
    // dell'App accoda restano finti, altrimenti girerebbero davvero verso S3.
    Bus::fake([
        RecalculatePoiTracksForDistanceJob::class,
        UpdateAppConfigJob::class,
        BuildAppPoisGeojsonJob::class,
        RegenerateTaxonomyWhereOutputsJob::class,
        UpdateEcTrackAwsJob::class,
    ]);
    // L'observer del package sull'App scrive anche sui dischi remoti.
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
});

it('accoda il ricalcolo quando cambia la distanza', function () {
    $app = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID])->fresh();

    $app->forceFill([PoiNearbyTracksDistance::COLUMN => 2000])->save();

    // Dopo il commit: Nova salva in una transazione, e il worker deve leggere il valore nuovo.
    Bus::assertDispatched(RecalculatePoiTracksForDistanceJob::class, fn ($job) => $job->afterCommit === true);
});

it('accoda un ricalcolo solo per più salvataggi ravvicinati', function () {
    expect(new RecalculatePoiTracksForDistanceJob)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

it('non ricalcola se la distanza non cambia', function () {
    $app = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID])->fresh();

    $app->forceFill(['name' => 'Altro nome'])->save();

    Bus::assertNotDispatched(RecalculatePoiTracksForDistanceJob::class);
});

it("non ricalcola per un'altra App", function () {
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    $other = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID + 1])->fresh();

    $other->forceFill([PoiNearbyTracksDistance::COLUMN => 2000])->save();

    Bus::assertNotDispatched(RecalculatePoiTracksForDistanceJob::class);
});
