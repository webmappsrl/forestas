<?php

use App\Jobs\Import\CalculateNearbyTracksChunkJob;
use App\Jobs\Import\CalculateNearbyTracksJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;

/**
 * Sentieri vicini calcolati dalla geometria (oc:8932). Il raggio e' in metri: la colonna e' gia'
 * `geography`, e il cast esplicito nel job lo protegge da un cambio a `::geometry` o del tipo della
 * colonna, dopo il quale PostGIS lo leggerebbe in gradi.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([GenerateOptimizedPBFChainJob::class]);
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function nearbyTrack(string $wkt): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia', 'en' => 'Traccia'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$wkt}))', 4326)"),
    ]));
}

/**
 * Esegue il calcolo come in coda: il job accoda un batch di blocchi, qui eseguiti in ordine.
 */
function runNearby(): void
{
    Bus::fake([GenerateOptimizedPBFChainJob::class, RegenerateTaxonomyWhereOutputsJob::class]);
    (new CalculateNearbyTracksJob)->handle();
    Bus::assertBatched(function (PendingBatch $batch) {
        $batch->jobs->each(fn ($job) => $job->handle());

        return true;
    });
}

function nearbyPairs(): array
{
    return DB::table('ec_track_related_tracks')->get()
        ->map(fn ($r) => [$r->ec_track_id, $r->related_track_id])->all();
}

it('collega due percorsi che si toccano', function () {
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');

    runNearby();

    expect(nearbyPairs())->toHaveCount(2);
    expect($a->relatedTracks()->pluck('ec_tracks.id')->all())->toBe([$b->id]);
    expect($b->relatedTracks()->pluck('ec_tracks.id')->all())->toBe([$a->id]);
});

it('non collega due percorsi a 2 km di distanza', function () {
    // 0.018 gradi di latitudine sono circa 2 km: se il raggio fosse in gradi li collegherebbe.
    nearbyTrack('9 40.5 0, 9.1 40.5 0');
    nearbyTrack('9 40.518 0, 9.1 40.518 0');

    runNearby();

    expect(nearbyPairs())->toBe([]);
});

it('lascia senza collegamenti un percorso isolato', function () {
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');
    $isolated = nearbyTrack('8 39 0, 8.1 39 0');

    runNearby();

    expect(nearbyPairs())->toHaveCount(2);
    expect($isolated->relatedTracks()->count())->toBe(0);
});

it('usa il raggio configurato', function () {
    // Circa 111 m di distanza: fuori dai 50 m di default, dentro i 200 m.
    nearbyTrack('9 40.5 0, 9.1 40.5 0');
    nearbyTrack('9 40.501 0, 9.1 40.501 0');

    runNearby();
    expect(nearbyPairs())->toBe([]);

    config(['sardegnasentieri.nearby_tracks_radius_meters' => 200]);
    runNearby();
    expect(nearbyPairs())->toHaveCount(2);
});

it('svuota la tabella e accoda un batch con un blocco ogni chunk_size tracce', function () {
    config(['sardegnasentieri.nearby_tracks_chunk_size' => 2]);
    Bus::fake();
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');
    $c = nearbyTrack('8 39 0, 8.1 39 0');
    DB::table('ec_track_related_tracks')->insert(['ec_track_id' => $a->id, 'related_track_id' => $c->id]);

    (new CalculateNearbyTracksJob)->handle();

    expect(nearbyPairs())->toBe([]);
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->queue() === ImportSardegnaSentieriTrackJob::QUEUE
        && $batch->allowsFailures()
        && $batch->jobs->whereInstanceOf(CalculateNearbyTracksChunkJob::class)->map(fn ($job) => $job->trackIds)->values()->all() === [[$a->id, $b->id], [$c->id]]);
});

it('rigenera file e indice solo quando sono finiti tutti i blocchi', function () {
    // Prima la catena rigenerava dopo il primo blocco: i file delle tracce dei blocchi seguenti
    // uscivano senza sentieri correlati fino al giro successivo (oc:8959).
    Bus::fake();
    nearbyTrack('9 40.5 0, 9.1 40.5 0');

    (new CalculateNearbyTracksJob)->handle();

    Bus::assertNotDispatched(RegenerateTaxonomyWhereOutputsJob::class);
    Bus::assertBatched(function (PendingBatch $batch) {
        foreach ($batch->finallyCallbacks() as $callback) {
            $callback->getClosure()(Mockery::mock(Batch::class));
        }

        return true;
    });
    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class, fn ($job) => $job->appId === SardegnaSentieriImportService::IMPORT_APP_ID
        && $job->queue === ImportSardegnaSentieriTrackJob::QUEUE);
});

it('senza tracce rigenera subito, senza batch', function () {
    Bus::fake();

    (new CalculateNearbyTracksJob)->handle();

    Bus::assertNothingBatched();
    Bus::assertDispatched(RegenerateTaxonomyWhereOutputsJob::class);
});

it('un blocco non cancella il lavoro degli altri blocchi', function () {
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');
    $c = nearbyTrack('8 39 0, 8.1 39 0');

    (new CalculateNearbyTracksChunkJob([$a->id]))->handle();
    (new CalculateNearbyTracksChunkJob([$b->id]))->handle();
    (new CalculateNearbyTracksChunkJob([$c->id]))->handle();

    expect(nearbyPairs())->toHaveCount(2);
    expect($c->relatedTracks()->count())->toBe(0);
});

it('registra sul canale import il blocco saltato', function () {
    Log::shouldReceive('channel')->with('import')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'da id 7 a id 9'));

    (new CalculateNearbyTracksChunkJob([7, 8, 9]))->failed(new RuntimeException('boom'));
});

it('non esegue due volte lo stesso blocco, ma non blocca i blocchi diversi', function () {
    $job = new CalculateNearbyTracksChunkJob([100, 101]);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('sardegnasentieri-nearby-tracks-100')
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and((new CalculateNearbyTracksChunkJob([200]))->middleware()[0]->key)->not->toBe($middleware[0]->key);
});
