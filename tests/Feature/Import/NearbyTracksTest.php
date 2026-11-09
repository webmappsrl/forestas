<?php

use App\Jobs\Import\CalculateNearbyTracksJob;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;
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

function nearbyPairs(): array
{
    return DB::table('ec_track_related_tracks')->get()
        ->map(fn ($r) => [$r->ec_track_id, $r->related_track_id])->all();
}

it('collega due percorsi che si toccano', function () {
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');

    (new CalculateNearbyTracksJob)->handle();

    expect(nearbyPairs())->toHaveCount(2);
    expect($a->relatedTracks()->pluck('ec_tracks.id')->all())->toBe([$b->id]);
    expect($b->relatedTracks()->pluck('ec_tracks.id')->all())->toBe([$a->id]);
});

it('non collega due percorsi a 2 km di distanza', function () {
    // 0.018 gradi di latitudine sono circa 2 km: se il raggio fosse in gradi li collegherebbe.
    nearbyTrack('9 40.5 0, 9.1 40.5 0');
    nearbyTrack('9 40.518 0, 9.1 40.518 0');

    (new CalculateNearbyTracksJob)->handle();

    expect(nearbyPairs())->toBe([]);
});

it('lascia senza collegamenti un percorso isolato', function () {
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');
    $isolated = nearbyTrack('8 39 0, 8.1 39 0');

    (new CalculateNearbyTracksJob)->handle();

    expect(nearbyPairs())->toHaveCount(2);
    expect($isolated->relatedTracks()->count())->toBe(0);
});

it('usa il raggio configurato', function () {
    // Circa 111 m di distanza: fuori dai 50 m di default, dentro i 200 m.
    nearbyTrack('9 40.5 0, 9.1 40.5 0');
    nearbyTrack('9 40.501 0, 9.1 40.501 0');

    (new CalculateNearbyTracksJob)->handle();
    expect(nearbyPairs())->toBe([]);

    config(['sardegnasentieri.nearby_tracks_radius_meters' => 200]);
    (new CalculateNearbyTracksJob)->handle();
    expect(nearbyPairs())->toHaveCount(2);
});

it('lavora a blocchi senza cancellare il lavoro del blocco precedente', function () {
    config(['sardegnasentieri.nearby_tracks_chunk_size' => 1]);
    Queue::fake();

    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    $b = nearbyTrack('9.1 40.5 0, 9.2 40.5 0');
    $c = nearbyTrack('8 39 0, 8.1 39 0');

    // Primo blocco: solo la traccia $a, e si riaccoda.
    (new CalculateNearbyTracksJob)->handle();
    expect(nearbyPairs())->toHaveCount(2);
    Queue::assertPushed(CalculateNearbyTracksJob::class, fn ($j) => $j->afterId === $a->id);

    // Blocchi successivi: non svuotano la tabella.
    (new CalculateNearbyTracksJob($a->id))->handle();
    (new CalculateNearbyTracksJob($b->id))->handle();

    expect(nearbyPairs())->toHaveCount(2);
    expect($c->relatedTracks()->count())->toBe(0);
});

it('accoda il blocco successivo prima di fare il lavoro', function () {
    config(['sardegnasentieri.nearby_tracks_chunk_size' => 1]);
    Queue::fake();
    $a = nearbyTrack('9 40.5 0, 9.1 40.5 0');
    nearbyTrack('9.1 40.5 0, 9.2 40.5 0');

    // Se il calcolo fallisce il blocco dopo e' gia' accodato.
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'ST_DWithin')) {
            throw new RuntimeException('calcolo interrotto');
        }
    });

    expect(fn () => (new CalculateNearbyTracksJob)->handle())->toThrow(RuntimeException::class);

    Queue::assertPushed(CalculateNearbyTracksJob::class, fn ($j) => $j->afterId === $a->id);
});

it('registra sul canale import il blocco saltato', function () {
    Log::shouldReceive('channel')->with('import')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'dopo id 7'));

    (new CalculateNearbyTracksJob(7))->failed(new RuntimeException('boom'));
});

it('non esegue due volte lo stesso blocco, ma non blocca i blocchi diversi', function () {
    $job = new CalculateNearbyTracksJob(100);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('sardegnasentieri-nearby-tracks-100')
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and((new CalculateNearbyTracksJob(200))->middleware()[0]->key)->not->toBe($middleware[0]->key);
});
