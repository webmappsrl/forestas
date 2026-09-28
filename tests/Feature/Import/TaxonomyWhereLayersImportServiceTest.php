<?php

declare(strict_types=1);

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
