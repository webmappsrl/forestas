<?php

declare(strict_types=1);

use App\Models\EcTrack;
use App\Models\User;
use App\Services\Import\GeographicAreasImportService;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyWhere;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    // UpdateAppConfigJob è ShouldBeUnique su Redis: il lock si prende anche sotto Bus::fake().
    config(['cache.stores.redis.driver' => 'array']);

    $user = User::factory()->create();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID, 'user_id' => $user->id]);

    $square = fn (float $lon) => [
        'type' => 'MultiPolygon',
        'coordinates' => [[[[$lon, 40.0], [$lon + 0.1, 40.0], [$lon + 0.1, 40.1], [$lon, 40.1], [$lon, 40.0]]]],
    ];

    Http::fake([
        'geohub.webmapp.it/*/AreeGeograficheConLayers.geojson' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['layer_id' => 217], 'geometry' => $square(9.0)],
                ['type' => 'Feature', 'properties' => ['layer_id' => 222], 'geometry' => $square(9.5)],
            ],
        ]),
        'wmfe.s3.eu-central-1.amazonaws.com/*' => Http::response(['MAP' => ['layers' => [
            ['id' => 217, 'title' => ['it' => 'Coros'], 'description' => ['it' => 'Il Coros']],
            ['id' => 222, 'title' => ['it' => 'Goceano']],
        ]]]),
    ]);
});

function geographicLayers()
{
    return Layer::query()
        ->whereHas('taxonomyWheres', fn ($q) => $q->where(
            'properties->source',
            TaxonomyWhereLayersImportService::GEOGRAPHIC_AREAS_SOURCE_KEY
        ))
        ->get();
}

// Le aree geografiche nascono dall'import come le catastali, senza passi a mano (oc:9001).
it('crea un layer vuoto per area, con i POI in manuale e la descrizione dell\'area', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'osmid' => null,
    ]);
    DB::statement(
        "UPDATE ec_tracks SET geometry = ST_GeomFromText('LINESTRING Z(9.04 40.04 0, 9.06 40.06 0)', 4326) WHERE id = ?",
        [$track->getKey()]
    );

    $result = app(GeographicAreasImportService::class)->run();

    $layers = geographicLayers();
    $coros = $layers->first(fn (Layer $layer) => $layer->getTranslation('name', 'it') === 'Coros');

    expect($result['layers_created'])->toBe(2)
        ->and($layers)->toHaveCount(2)
        ->and($layers->every(fn (Layer $layer) => ($layer->configuration['poi_mode'] ?? null) === 'manual'))->toBeTrue()
        ->and(DB::table('layerables')->count())->toBe(0)
        ->and($coros->properties['description']['it'] ?? null)->toBe('Il Coros');
});

it('collega i layer alla FeatureCollection aree_geografiche in modalità generated', function () {
    app(GeographicAreasImportService::class)->run();

    $fc = FeatureCollection::query()->where('name', 'aree_geografiche')->sole();

    expect($fc->mode)->toBe('generated')
        ->and($fc->app_id)->toBe(SardegnaSentieriImportService::IMPORT_APP_ID)
        ->and($fc->layers()->pluck('layers.id')->sort()->values()->all())
        ->toBe(geographicLayers()->pluck('id')->sort()->values()->all());
});

it('porta a generated la FeatureCollection già caricata, tenendo colori ed etichetta', function () {
    FeatureCollection::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'aree_geografiche',
        'mode' => 'upload',
        'label' => ['it' => 'Aree geografiche (mia)'],
        'fill_color' => '#123456aa',
    ]);

    app(GeographicAreasImportService::class)->run();

    $fc = FeatureCollection::query()->where('name', 'aree_geografiche')->sole();

    expect($fc->mode)->toBe('generated')
        ->and($fc->fill_color)->toBe('#123456aa')
        ->and($fc->getTranslation('label', 'it'))->toBe('Aree geografiche (mia)')
        ->and($fc->layers()->count())->toBe(2);
});

it('un secondo giro non crea doppioni', function () {
    app(GeographicAreasImportService::class)->run();
    $layers = Layer::count();
    $areas = TaxonomyWhere::count();

    $result = app(GeographicAreasImportService::class)->run();

    expect($result['layers_created'])->toBe(0)
        ->and(Layer::count())->toBe($layers)
        ->and(TaxonomyWhere::count())->toBe($areas);
});

it('non tocca le aree catastali e i loro layer', function () {
    $cadastral = TaxonomyWhere::create([
        'name' => ['it' => 'Area F (Nord)'],
        'properties' => ['source' => TaxonomyWhereLayersImportService::SOURCE_KEY, 'external_layer_id' => '310'],
    ]);
    $cadastralLayer = Layer::factory()->createQuietly(['app_id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    DB::table('taxonomy_whereables')->insert([
        'taxonomy_where_id' => $cadastral->id,
        'taxonomy_whereable_id' => $cadastralLayer->id,
        'taxonomy_whereable_type' => Layer::class,
    ]);

    app(GeographicAreasImportService::class)->run();

    expect(Layer::query()->whereKey($cadastralLayer->id)->sole()->configuration['poi_mode'] ?? null)->not->toBe('manual')
        ->and(geographicLayers()->pluck('id'))->not->toContain($cadastralLayer->id)
        ->and(Layer::count())->toBe(3);
});

it('non tocca la FeatureCollection se nessuna area ha un layer', function () {
    // Per esempio 32.json cambia struttura e tutte le aree vengono saltate: la generazione
    // sovrascriverebbe il file caricato con un overlay vuoto. Client nuovo: con Http::fake() vince
    // la prima risposta registrata, che è quella del beforeEach.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'geohub.webmapp.it/*/AreeGeograficheConLayers.geojson' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        'wmfe.s3.eu-central-1.amazonaws.com/*' => Http::response(['MAP' => ['layers' => []]]),
    ]);
    FeatureCollection::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'aree_geografiche',
        'mode' => 'upload',
    ]);

    app(GeographicAreasImportService::class)->run();

    expect(FeatureCollection::query()->where('name', 'aree_geografiche')->sole()->mode)->toBe('upload');
    Bus::assertNotDispatched(GenerateFeatureCollectionJob::class);
});

it('rimette i POI in manuale su un layer di area rimasto senza modalità', function () {
    // Un giro interrotto fra la creazione del layer e l'impostazione della modalità lascerebbe il
    // layer con i POI automatici: il ricalcolo ci metterebbe i POI e rigenererebbe i PBF.
    app(GeographicAreasImportService::class)->run();
    $layer = geographicLayers()->first();
    DB::statement("UPDATE layers SET configuration = configuration - 'poi_mode' WHERE id = ?", [$layer->id]);

    app(GeographicAreasImportService::class)->run();

    expect(Layer::query()->whereKey($layer->id)->sole()->configuration['poi_mode'] ?? null)->toBe('manual');
});
