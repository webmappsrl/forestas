<?php

declare(strict_types=1);

use App\Jobs\Import\CalculateNearbyTracksJob;
use App\Jobs\Import\CalculatePoiTracksChunkJob;
use App\Jobs\Import\RecalculatePoiTracksForDistanceJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;

/**
 * I collegamenti fra POI e percorsi (`ec_poi_ec_track`) calcolati per vicinanza (oc:8959): i
 * percorsi entro la distanza dell'App del POI (`PoiNearbyTracksDistance`, 1 km di default), al posto di quelli
 * scritti su Drupal. Ogni blocco riscrive solo i suoi POI, perché il dettaglio del POI legge la
 * tabella dal vivo; `order` è la distanza in metri, così le tracce elencano i POI dal più vicino.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function linkTrack(string $wkt): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$wkt}))', 4326)"),
    ]));
}

function linkPoi(string $point): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'POI'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [],
        'geometry' => DB::raw("ST_GeomFromText('POINT Z({$point} 0)', 4326)"),
    ]));
}

function linkRows(): array
{
    return DB::table('ec_poi_ec_track')->orderBy('ec_poi_id')->orderBy('order')
        ->get()->map(fn ($r) => [$r->ec_poi_id, $r->ec_track_id, (int) $r->order])->all();
}

it('collega le tracce entro 1 km, con la distanza in metri come ordine', function () {
    $near = linkTrack('9 40.5 0, 9.1 40.5 0');
    // Circa 2 km più a nord: fuori dal km.
    linkTrack('9 40.52 0, 9.1 40.52 0');
    // Circa 111 m a sud della prima traccia.
    $poi = linkPoi('9.05 40.499');

    (new CalculatePoiTracksChunkJob([$poi->id]))->handle();

    $rows = linkRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0][0])->toBe($poi->id)
        ->and($rows[0][1])->toBe($near->id)
        ->and($rows[0][2])->toBeGreaterThan(100)->toBeLessThan(120);
});

it("usa il raggio dell'App del POI, non quello dei sentieri correlati", function () {
    linkTrack('9 40.5 0, 9.1 40.5 0');
    $poi = linkPoi('9.05 40.499');
    config(['sardegnasentieri.nearby_tracks_radius_meters' => 5000]);
    $app = App::query()->findOrFail(SardegnaSentieriImportService::IMPORT_APP_ID);
    $app->forceFill([PoiNearbyTracksDistance::COLUMN => 50])->saveQuietly();

    (new CalculatePoiTracksChunkJob([$poi->id]))->handle();

    expect(linkRows())->toBe([]);

    $app->forceFill([PoiNearbyTracksDistance::COLUMN => 200])->saveQuietly();

    (new CalculatePoiTracksChunkJob([$poi->id]))->handle();

    expect(linkRows())->toHaveCount(1);
});

it('non scarta un blocco che ne trova un altro in corso sugli stessi POI: lo mette in fila il lock', function () {
    expect(method_exists(CalculatePoiTracksChunkJob::class, 'middleware'))->toBeFalse();
});

it('usa per ogni POI il raggio della sua App, anche in un blocco con App diverse', function () {
    $otherApp = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID + 1]);
    $otherApp->forceFill([PoiNearbyTracksDistance::COLUMN => 50])->saveQuietly();
    linkTrack('9 40.5 0, 9.1 40.5 0');
    $poi = linkPoi('9.05 40.499');
    EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Altra'], 'app_id' => $otherApp->id, 'properties' => ['ref' => 'X'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((9 40.5 0, 9.1 40.5 0))', 4326)"),
    ]));
    $otherPoi = EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'Altro'], 'app_id' => $otherApp->id, 'properties' => [],
        'geometry' => DB::raw("ST_GeomFromText('POINT Z(9.05 40.499 0)', 4326)"),
    ]));

    (new CalculatePoiTracksChunkJob([$poi->id, $otherPoi->id]))->handle();

    expect(array_column(linkRows(), 0))->toBe([$poi->id]);
});

it('riscrive solo le righe dei POI del blocco, anche quelle scritte prima da Drupal', function () {
    $track = linkTrack('9 40.5 0, 9.1 40.5 0');
    $far = linkTrack('8 39 0, 8.1 39 0');
    $poi = linkPoi('9.05 40.499');
    $other = linkPoi('9.06 40.499');
    DB::table('ec_poi_ec_track')->insert([
        ['ec_poi_id' => $other->id, 'ec_track_id' => $far->id, 'order' => 0],
        ['ec_poi_id' => $poi->id, 'ec_track_id' => $far->id, 'order' => 0],
    ]);

    (new CalculatePoiTracksChunkJob([$poi->id]))->handle();

    expect(DB::table('ec_poi_ec_track')->where('ec_poi_id', $other->id)->pluck('ec_track_id')->all())->toBe([$far->id])
        ->and(DB::table('ec_poi_ec_track')->where('ec_poi_id', $poi->id)->pluck('ec_track_id')->all())->toBe([$track->id]);
});

it('ordina i POI di una traccia dal più vicino', function () {
    $track = linkTrack('9 40.5 0, 9.1 40.5 0');
    $far = linkPoi('9.05 40.495');
    $close = linkPoi('9.06 40.499');

    (new CalculatePoiTracksChunkJob([$far->id, $close->id]))->handle();

    expect($track->fresh()->ecPois()->pluck('ec_pois.id')->all())->toBe([$close->id, $far->id]);
});

it('accoda i blocchi dei POI nello stesso batch dei correlati, senza svuotare la tabella', function () {
    config(['sardegnasentieri.nearby_tracks_chunk_size' => 2]);
    $track = linkTrack('9 40.5 0, 9.1 40.5 0');
    $a = linkPoi('9.05 40.499');
    $b = linkPoi('9.06 40.499');
    $c = linkPoi('9.07 40.499');
    DB::table('ec_poi_ec_track')->insert(['ec_poi_id' => $a->id, 'ec_track_id' => $track->id, 'order' => 0]);

    (new CalculateNearbyTracksJob)->handle();

    expect(DB::table('ec_poi_ec_track')->count())->toBe(1);
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs
        ->whereInstanceOf(CalculatePoiTracksChunkJob::class)
        ->map(fn ($job) => $job->poiIds)->values()->all() === [[$a->id, $b->id], [$c->id]]);
});

it('ricalcola solo i blocchi dei POI, senza toccare i sentieri correlati', function () {
    config(['sardegnasentieri.nearby_tracks_chunk_size' => 2]);
    $track = linkTrack('9 40.5 0, 9.1 40.5 0');
    $other = linkTrack('9 40.5005 0, 9.1 40.5005 0');
    $a = linkPoi('9.05 40.499');
    $b = linkPoi('9.06 40.499');
    $c = linkPoi('9.07 40.499');
    DB::table('ec_track_related_tracks')->insert(['ec_track_id' => $track->id, 'related_track_id' => $other->id]);

    (new RecalculatePoiTracksForDistanceJob)->handle();

    expect(DB::table('ec_track_related_tracks')->count())->toBe(1);
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'sardegnasentieri poi nearby tracks'
        && $batch->jobs->every(fn ($job) => $job instanceof CalculatePoiTracksChunkJob)
        && $batch->jobs->map(fn ($job) => $job->poiIds)->values()->all() === [[$a->id, $b->id], [$c->id]]);
});

it('blocca i POI del blocco prima di riscriverli, così due blocchi con confini diversi non duplicano le righe', function () {
    linkTrack('9 40.5 0, 9.1 40.5 0');
    $poi = linkPoi('9.05 40.499');
    DB::enableQueryLog();

    (new CalculatePoiTracksChunkJob([$poi->id]))->handle();

    $queries = array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());
    $lock = array_key_first(array_filter($queries, fn ($q) => str_contains($q, 'from "ec_pois"') && str_contains($q, 'for update')));
    $delete = array_key_first(array_filter($queries, fn ($q) => str_starts_with($q, 'delete from "ec_poi_ec_track"')));

    expect($lock)->not->toBeNull()
        ->and($lock)->toBeLessThan($delete);
});
