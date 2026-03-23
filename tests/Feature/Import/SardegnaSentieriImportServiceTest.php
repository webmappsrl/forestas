<?php

declare(strict_types=1);

use App\Enums\StatoValidazione;
use App\Services\Import\SardegnaSentieriImportService;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function sardegnaSentieriApp(): App
{
    return App::firstOrCreate(
        ['sku' => 'it.webmapp.sardegnasentieri'],
        ['name' => 'Sardegna Sentieri', 'user_id' => sardegnaSentieriUser()->id]
    );
}

function sardegnaSentieriUser(): User
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'forestas', 'password' => bcrypt('secret')]
    );
    if (! $user->hasRole('Editor')) {
        $user->assignRole('Editor');
    }

    return $user;
}

function makeService(array $clientMethods = []): SardegnaSentieriImportService
{
    $client = Mockery::mock(SardegnaSentieriClient::class);
    foreach ($clientMethods as $method => $return) {
        $client->shouldReceive($method)->andReturn($return);
    }

    return new SardegnaSentieriImportService($client);
}

function minimalPoiFeature(int $id, array $overrides = []): array
{
    return array_merge_recursive([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [9.1903, 41.1087]],
        'properties' => [
            'id' => (string) $id,
            'name' => ['it' => 'Punto di Interesse', 'en' => 'Point of Interest'],
            'description' => ['it' => 'Descrizione', 'en' => 'Description'],
            'addr_locality' => 'Nuoro',
            'codice' => 'POI-001',
            'updated_at' => '2024-01-15T10:00:00',
            'collegamenti' => [],
            'come_arrivare' => null,
            'url' => null,
            'taxonomies' => ['tipologia_poi' => [], 'zona_geografica' => []],
        ],
    ], $overrides);
}

function minimalTrackFeature(int $id, array $overrides = []): array
{
    return array_merge_recursive([
        'type' => 'Feature',
        'geometry' => null,
        'properties' => [
            'id' => (string) $id,
            'name' => ['it' => 'Sentiero Test', 'en' => 'Test Track'],
            'description' => ['it' => 'Desc', 'en' => 'Desc'],
            'excerpt' => null,
            'lunghezza' => '5000',
            'dislivello_totale' => '300',
            'durata' => '3600',
            'type' => 'sentiero',
            'allegati' => [],
            'video' => [],
            'gpx' => [],
            'url' => null,
            'updated_at' => '2024-01-15T10:00:00',
            'partenza' => null,
            'arrivo' => null,
            'taxonomies' => [
                'categorie_fruibilita_sentieri' => [],
                'tipologia_sentieri' => [],
                'stato_di_validazione' => [],
                'zona_geografica' => [],
            ],
        ],
    ], $overrides);
}

function gpxWithNamespace(array $coords = [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]): string
{
    $trkpts = implode('', array_map(
        fn($c) => "<trkpt lat=\"{$c[1]}\" lon=\"{$c[0]}\"><ele>{$c[2]}</ele></trkpt>",
        $coords
    ));

    return <<<GPX
<?xml version="1.0" encoding="UTF-8"?>
<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1">
  <trk><trkseg>{$trkpts}</trkseg></trk>
</gpx>
GPX;
}

function gpxWithoutNamespace(array $coords = [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]): string
{
    $trkpts = implode('', array_map(
        fn($c) => "<trkpt lat=\"{$c[1]}\" lon=\"{$c[0]}\"><ele>{$c[2]}</ele></trkpt>",
        $coords
    ));

    return <<<GPX
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1">
  <trk><trkseg>{$trkpts}</trkseg></trk>
</gpx>
GPX;
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    sardegnaSentieriApp(); // ensures App + User exist
});

// ---------------------------------------------------------------------------
// importPoi — creazione e aggiornamento
// ---------------------------------------------------------------------------

it('crea un nuovo EcPoi dalla API', function () {
    $service = makeService(['getPoiDetail' => minimalPoiFeature(42)]);

    $poi = $service->importPoi(42);

    expect(EcPoi::count())->toBe(1)
        ->and($poi->properties['out_source_feature_id'])->toBe('42')
        ->and($poi->properties['forestas']['codice'])->toBe('POI-001');
});

it('aggiorna un EcPoi esistente senza duplicati', function () {
    $service = makeService(['getPoiDetail' => minimalPoiFeature(42)]);
    $service->importPoi(42);

    // Seconda esecuzione — stesso ID
    $feature = minimalPoiFeature(42, ['properties' => ['codice' => 'POI-002']]);
    $service2 = makeService(['getPoiDetail' => $feature]);
    $service2->importPoi(42);

    expect(EcPoi::count())->toBe(1)
        ->and(EcPoi::first()->properties['forestas']['codice'])->toBe('POI-002');
});

it('salva la geometry come POINT WKT', function () {
    $service = makeService(['getPoiDetail' => minimalPoiFeature(42)]);
    $poi = $service->importPoi(42);

    // La geometry viene salvata e poi rielaborata da setGeometryAttribute;
    // verifichiamo che la colonna non sia null
    expect($poi->getRawOriginal('geometry'))->not->toBeNull();
});

it('lancia eccezione se la geometry è mancante', function () {
    $feature = minimalPoiFeature(42);
    $feature['geometry'] = null;

    $service = makeService(['getPoiDetail' => $feature]);

    expect(fn () => $service->importPoi(42))
        ->toThrow(RuntimeException::class, 'Invalid geometry');
});

// ---------------------------------------------------------------------------
// importPoi — sync tassonomie (P1: sync su array vuoto)
// ---------------------------------------------------------------------------

it('sincronizza le TaxonomyPoiType quando presenti', function () {
    $taxonomy = TaxonomyPoiType::create(['identifier' => 'rifugio', 'name' => 'Rifugio']);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getPoiDetail')->andReturn(
        minimalPoiFeature(42, ['properties' => ['taxonomies' => ['tipologia_poi' => ['999'], 'zona_geografica' => []]]])
    );
    $client->shouldReceive('getTaxonomy')->with('tipologia_poi')->andReturn([
        '999' => ['geohub_identifier' => 'rifugio', 'name' => 'Rifugio'],
    ]);

    $service = new SardegnaSentieriImportService($client);
    $poi = $service->importPoi(42);

    expect($poi->taxonomyPoiTypes()->count())->toBe(1)
        ->and($poi->taxonomyPoiTypes()->first()->identifier)->toBe('rifugio');
});

it('rimuove le TaxonomyPoiType quando la API restituisce lista vuota (fix P1)', function () {
    $taxonomy = TaxonomyPoiType::create(['identifier' => 'rifugio', 'name' => 'Rifugio']);

    // Prima importazione con tassonomia
    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getPoiDetail')->andReturn(
        minimalPoiFeature(42, ['properties' => ['taxonomies' => ['tipologia_poi' => ['999'], 'zona_geografica' => []]]])
    );
    $client->shouldReceive('getTaxonomy')->with('tipologia_poi')->andReturn([
        '999' => ['geohub_identifier' => 'rifugio', 'name' => 'Rifugio'],
    ]);
    (new SardegnaSentieriImportService($client))->importPoi(42);

    $poi = EcPoi::first();
    expect($poi->taxonomyPoiTypes()->count())->toBe(1);

    // Seconda importazione senza tassonomie → deve rimuoverle
    $service2 = makeService(['getPoiDetail' => minimalPoiFeature(42)]);
    $service2->importPoi(42);

    expect($poi->fresh()->taxonomyPoiTypes()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// importTrack — creazione e aggiornamento
// ---------------------------------------------------------------------------

it('crea un nuovo EcTrack dalla API senza GPX', function () {
    $service = makeService(['getTrackDetail' => minimalTrackFeature(75)]);

    $track = $service->importTrack(75);

    expect(EcTrack::count())->toBe(1)
        ->and($track->properties['sardegnasentieri_id'])->toBe('75')
        ->and($track->properties['manual_data']['distance'])->toBe('5000');
});

it('imposta stato_validazione tramite enum', function () {
    $feature = minimalTrackFeature(75, [
        'properties' => ['taxonomies' => ['stato_di_validazione' => ['4188']]],
    ]);
    $service = makeService(['getTrackDetail' => $feature]);

    $track = $service->importTrack(75);

    expect($track->stato_validazione)->toBe(StatoValidazione::Validato->value);
});

it('aggiorna un EcTrack esistente senza duplicati', function () {
    $service = makeService(['getTrackDetail' => minimalTrackFeature(75)]);
    $service->importTrack(75);

    $feature2 = minimalTrackFeature(75, ['properties' => ['lunghezza' => '9999']]);
    (makeService(['getTrackDetail' => $feature2]))->importTrack(75);

    expect(EcTrack::count())->toBe(1)
        ->and(EcTrack::first()->properties['manual_data']['distance'])->toBe('9999');
});

// ---------------------------------------------------------------------------
// GPX parsing — namespace (fix P2)
// ---------------------------------------------------------------------------

it('parsa correttamente GPX con xmlns namespace (fix P2)', function () {
    $feature = minimalTrackFeature(75, ['properties' => ['gpx' => ['http://example.com/track.gpx']]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithNamespace());

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    expect($track->getRawOriginal('geometry'))->not->toBeNull();
});

it('parsa correttamente GPX senza namespace', function () {
    $feature = minimalTrackFeature(75, ['properties' => ['gpx' => ['http://example.com/track.gpx']]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andReturn(gpxWithoutNamespace());

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    expect($track->getRawOriginal('geometry'))->not->toBeNull();
});

it('continua senza geometry se tutti i GPX falliscono', function () {
    $feature = minimalTrackFeature(75, ['properties' => ['gpx' => ['http://example.com/bad.gpx']]]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getGpxContent')->andThrow(new RuntimeException('timeout'));

    $track = (new SardegnaSentieriImportService($client))->importTrack(75);

    // Track salvato anche senza geometry
    expect(EcTrack::count())->toBe(1)
        ->and($track->properties['sardegnasentieri_id'])->toBe('75');
});

// ---------------------------------------------------------------------------
// importTrack — sync relazioni vuote (fix P1)
// ---------------------------------------------------------------------------

it('rimuove le TaxonomyActivity quando la API restituisce lista vuota (fix P1)', function () {
    $activity = TaxonomyActivity::create(['identifier' => 'escursionismo', 'name' => 'Escursionismo']);

    // Prima importazione con attività
    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getTrackDetail')->andReturn(
        minimalTrackFeature(75, ['properties' => ['taxonomies' => [
            'categorie_fruibilita_sentieri' => ['111'],
            'tipologia_sentieri' => [],
        ]]])
    );
    $client->shouldReceive('getTaxonomy')->with('categorie_fruibilita_sentieri')->andReturn([
        '111' => ['geohub_identifier' => 'escursionismo', 'name' => 'Escursionismo'],
    ]);
    $client->shouldReceive('getTaxonomy')->with('tipologia_sentieri')->andReturn([]);

    (new SardegnaSentieriImportService($client))->importTrack(75);

    $track = EcTrack::first();
    expect($track->taxonomyActivities()->count())->toBe(1);

    // Seconda importazione senza attività → deve rimuoverle
    (makeService(['getTrackDetail' => minimalTrackFeature(75)]))->importTrack(75);

    expect($track->fresh()->taxonomyActivities()->count())->toBe(0);
});

it('rimuove ecPois collegati quando partenza e arrivo vengono svuotati (fix P1)', function () {
    $poi = EcPoi::factory()->create([
        'properties' => ['out_source_feature_id' => '10'],
    ]);

    // Prima importazione con partenza
    $feature = minimalTrackFeature(75, ['properties' => ['partenza' => '10']]);
    $service = makeService(['getTrackDetail' => $feature]);
    $service->importTrack(75);

    $track = EcTrack::first();
    expect($track->ecPois()->count())->toBe(1);

    // Seconda importazione senza partenza
    (makeService(['getTrackDetail' => minimalTrackFeature(75)]))->importTrack(75);

    expect($track->fresh()->ecPois()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Mark removed (fix P5) — via Command
// ---------------------------------------------------------------------------

it('il Command non marca come rimossi i POI presenti nella API', function () {
    $app = sardegnaSentieriApp();

    EcPoi::factory()->create([
        'app_id' => $app->id,
        'properties' => ['out_source_feature_id' => '42', 'forestas' => ['updated_at' => '2024-01-15T10:00:00']],
    ]);

    // Lega il mock al container per il Command
    $this->instance(SardegnaSentieriClient::class, tap(
        Mockery::mock(SardegnaSentieriClient::class),
        function ($mock) {
            $mock->shouldReceive('getPoiList')->andReturn(['42' => '2024-01-15T10:00:00']);
            $mock->shouldReceive('getTaxonomy')->andReturn([]);
        }
    ));

    $this->artisan('sardegnasentieri:import', ['--only' => 'pois'])
        ->assertExitCode(0);

    $poi = EcPoi::first();
    expect($poi->properties['forestas']['deleted_from_source'] ?? false)->toBeFalse();
});

it('il Command marca come deleted_from_source i POI non più nell\'API (fix P5)', function () {
    $app = sardegnaSentieriApp();

    // POI nel DB con ID 99, non presente nell'API (lista vuota)
    EcPoi::factory()->create([
        'app_id' => $app->id,
        'properties' => ['out_source_feature_id' => '99', 'forestas' => ['updated_at' => '2024-01-01']],
    ]);

    $this->instance(SardegnaSentieriClient::class, tap(
        Mockery::mock(SardegnaSentieriClient::class),
        function ($mock) {
            $mock->shouldReceive('getPoiList')->andReturn([]); // API vuota
            $mock->shouldReceive('getTaxonomy')->andReturn([]);
        }
    ));

    $this->artisan('sardegnasentieri:import', ['--only' => 'pois'])
        ->assertExitCode(0);

    $poi = EcPoi::first();
    expect($poi->properties['forestas']['deleted_from_source'])->toBeTrue();
});

it('il Command marca come deleted_from_source i Track non più nell\'API (fix P5)', function () {
    $app = sardegnaSentieriApp();

    EcTrack::factory()->create([
        'app_id' => $app->id,
        'properties' => [
            'sardegnasentieri_id' => '75',
            'forestas' => ['updated_at' => '2024-01-01'],
        ],
    ]);

    $this->instance(SardegnaSentieriClient::class, tap(
        Mockery::mock(SardegnaSentieriClient::class),
        function ($mock) {
            $mock->shouldReceive('getTrackList')->andReturn([]); // API vuota
            $mock->shouldReceive('getTaxonomy')->andReturn([]);
        }
    ));

    $this->artisan('sardegnasentieri:import', ['--only' => 'tracks'])
        ->assertExitCode(0);

    $track = EcTrack::first();
    expect($track->properties['forestas']['deleted_from_source'])->toBeTrue();
});
