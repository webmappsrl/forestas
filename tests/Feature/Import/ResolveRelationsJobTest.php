<?php

use App\Jobs\Import\ResolveSardegnaSentieriRelationsJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;

/**
 * Risoluzione dei collegamenti percorso-POI e POI-POI a fine import (oc:8932).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function relTrack(string $nid, array $pois = [], string $wkt = '9 40.5 0, 9.1 40.5 0'): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia', 'en' => 'Traccia'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [
            'sardegnasentieri_id' => $nid,
            'forestas' => ['source_relations' => ['pois' => $pois, 'tracks' => []]],
        ],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$wkt}))', 4326)"),
    ]));
}

function relPoi(string $nid, array $tracks = [], array $pois = [], string $point = '9.05 40.5'): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'Poi', 'en' => 'Poi'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [
            'out_source_feature_id' => $nid,
            'forestas' => ['source_relations' => ['pois' => $pois, 'tracks' => $tracks]],
        ],
        'geometry' => DB::raw("ST_GeomFromText('POINT({$point})', 4326)"),
    ]));
}

function resolveRelations(): void
{
    (new ResolveSardegnaSentieriRelationsJob)->handle();
}

it('non scrive piu i collegamenti percorso-POI di Drupal: li calcola la vicinanza', function () {
    $t = relTrack('1', ['2']);
    relPoi('2', ['1']);

    resolveRelations();

    expect($t->ecPois()->count())->toBe(0);
});

it('rende reciproci i collegamenti fra POI scritti in un verso solo', function () {
    $a = relPoi('101', [], ['102']);
    $b = relPoi('102');

    resolveRelations();

    expect($a->relatedPois()->pluck('ec_pois.id')->all())->toBe([$b->id]);
    expect($b->relatedPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('scarta un POI che cita se stesso e tiene l altro collegamento valido', function () {
    // Su Drupal succede davvero (nid 2516, 2701, 2901): un collegamento a se stesso non va scritto.
    $a = relPoi('101', [], ['101', '102']);
    $b = relPoi('102');

    resolveRelations();

    expect($a->relatedPois()->pluck('ec_pois.id')->all())->toBe([$b->id]);
    expect($b->relatedPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('non parte in parallelo a un altro giro dei collegamenti', function () {
    $job = new ResolveSardegnaSentieriRelationsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('sardegnasentieri-resolve-relations')
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBeGreaterThan($job->timeout);
});
