<?php

declare(strict_types=1);

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\TaxonomyWhere;

uses(RefreshDatabase::class);

// Il package ha rinominato syncTracksTaxonomyWhere() in syncTaxonomyWhere() senza alias
// (wm-package oc:8487): la chiamata vecchia faceva fallire l'import dopo aver creato le where.
it('importa le where dai layer e completa la sincronizzazione dei sentieri', function () {
    Http::fake([
        'geohub.webmapp.it/*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['layer_id' => 101],
                'geometry' => [
                    'type' => 'MultiPolygon',
                    'coordinates' => [[[[9.0, 40.0], [9.1, 40.0], [9.1, 40.1], [9.0, 40.1], [9.0, 40.0]]]],
                ],
            ]],
        ]),
        'wmfe.s3.eu-central-1.amazonaws.com/*' => Http::response([
            'MAP' => ['layers' => [['id' => 101, 'title' => ['it' => 'Area di prova']]]],
        ]),
    ]);

    $counters = app(TaxonomyWhereLayersImportService::class)->import();

    expect($counters['created'])->toBe(1)
        ->and($counters['errors'])->toBe(0)
        ->and($counters['synced_tracks'])->toBe(0)
        ->and(TaxonomyWhere::count())->toBe(1);
});

/**
 * Geohub finto con le aree date (layer_id => geometria) e i loro titoli in `32.json`.
 *
 * @param  array<int, array<string, mixed>>  $areas
 */
function fakeGeohubAreas(string $geojsonFile, array $areas): void
{
    $features = [];
    $layers = [];
    foreach ($areas as $layerId => $geometry) {
        $features[] = ['type' => 'Feature', 'properties' => ['layer_id' => $layerId], 'geometry' => $geometry];
        $layers[] = ['id' => $layerId, 'title' => ['it' => "Area {$layerId}"]];
    }

    Http::fake([
        "geohub.webmapp.it/*/{$geojsonFile}" => Http::response(['type' => 'FeatureCollection', 'features' => $features]),
        'wmfe.s3.eu-central-1.amazonaws.com/*' => Http::response(['MAP' => ['layers' => $layers]]),
    ]);
}

function squareAround(float $lon, float $lat): array
{
    return [
        'type' => 'MultiPolygon',
        'coordinates' => [[[[$lon, $lat], [$lon + 0.1, $lat], [$lon + 0.1, $lat + 0.1], [$lon, $lat + 0.1], [$lon, $lat]]]],
    ];
}

// Le aree geografiche hanno la loro fonte, distinta da quella delle catastali (oc:9001).
it('importa le aree geografiche con la loro fonte', function () {
    fakeGeohubAreas('AreeGeograficheConLayers.geojson', [217 => squareAround(9.0, 40.0)]);

    $counters = app(TaxonomyWhereLayersImportService::class)
        ->import(null, TaxonomyWhereLayersImportService::GEOGRAPHIC_AREAS_SOURCE_KEY);

    expect($counters['created'])->toBe(1)
        ->and(TaxonomyWhere::first()->properties['source'])->toBe('geohub_conf_32_geographic_areas');
});

it('non tocca le aree catastali quando importa le aree geografiche con lo stesso layer_id', function () {
    $cadastral = TaxonomyWhere::create([
        'name' => ['it' => 'Area F (Nord)', 'en' => 'Area F (Nord)'],
        'properties' => ['source' => 'geohub_conf_32', 'external_layer_id' => '101', 'layer_id' => '101'],
    ]);
    $before = $cadastral->fresh();
    fakeGeohubAreas('AreeGeograficheConLayers.geojson', [101 => squareAround(9.0, 40.0)]);

    $counters = app(TaxonomyWhereLayersImportService::class)
        ->import(null, TaxonomyWhereLayersImportService::GEOGRAPHIC_AREAS_SOURCE_KEY);

    $after = $cadastral->fresh();
    expect($counters['created'])->toBe(1)
        ->and(TaxonomyWhere::count())->toBe(2)
        ->and($after->getTranslations('name'))->toBe($before->getTranslations('name'))
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue();
});

it('rifiuta una fonte sconosciuta', function () {
    app(TaxonomyWhereLayersImportService::class)->import(null, 'sconosciuta');
})->throws(InvalidArgumentException::class);

it('scrive nel log dell\'import un\'area non importata', function () {
    fakeGeohubAreas('AreeGeograficheConLayers.geojson', [
        // Un tipo che ST_GeomFromGeoJSON non conosce: PostGIS rifiuta la geometria.
        230 => ['type' => 'NotAGeometry', 'coordinates' => []],
    ]);
    $log = Log::spy();
    $log->shouldReceive('channel')->andReturnSelf();

    $counters = app(TaxonomyWhereLayersImportService::class)
        ->import(null, TaxonomyWhereLayersImportService::GEOGRAPHIC_AREAS_SOURCE_KEY);

    // L'area rifiutata non resta creata senza geometria (transazione per area).
    expect($counters['errors'])->toBe(1)
        ->and(TaxonomyWhere::count())->toBe(0);
    $log->shouldHaveReceived('channel')->with('import')->atLeast()->once();
    $log->shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []) => ($context['layer_id'] ?? null) === '230'
    )->once();
});
