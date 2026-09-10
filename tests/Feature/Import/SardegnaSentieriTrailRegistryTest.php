<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers (mirror di tests/Feature/Import/SardegnaSentieriImportServiceTest.php)
// ---------------------------------------------------------------------------

function trailRegistrySardegnaSentieriApp(): App
{
    $user = trailRegistrySardegnaSentieriUser();
    $id = SardegnaSentieriImportService::IMPORT_APP_ID;
    $existing = App::query()->find($id);
    if ($existing !== null) {
        return $existing;
    }

    return App::withoutEvents(fn () => App::query()->create([
        'id' => $id,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));
}

function trailRegistrySardegnaSentieriUser(): User
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    if (! $user->hasRole('Editor')) {
        $user->assignRole('Editor');
    }

    return $user;
}

function trailRegistryMakeService(SardegnaSentieriClient $client): SardegnaSentieriImportService
{
    $mediaSync = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldIgnoreMissing();

    return new SardegnaSentieriImportService($client, $mediaSync);
}

function trailRegistryMinimalTrackFeature(int $id, array $overrides = []): ApiTrackResponse
{
    $base = [
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
            'codice_cai' => null,
            'taxonomies' => [
                'categorie_fruibilita_sentieri' => [],
                'tipologia_sentieri' => [],
                'stato_di_validazione' => [],
                'zona_geografica' => [],
            ],
        ],
    ];

    if (isset($overrides['properties'])) {
        $base['properties'] = array_merge($base['properties'], $overrides['properties']);
        unset($overrides['properties']);
    }

    $feature = array_merge($base, $overrides);

    return ApiTrackResponse::fromJson($feature);
}

function trailRegistryGpx(array $coords = [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]): string
{
    $trkpts = implode('', array_map(
        fn ($c) => "<trkpt lat=\"{$c[1]}\" lon=\"{$c[0]}\"><ele>{$c[2]}</ele></trkpt>",
        $coords
    ));

    return <<<GPX
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1">
  <trk><trkseg>{$trkpts}</trkseg></trk>
</gpx>
GPX;
}

/**
 * Crea un settore (riga taxonomy_wheres) osm2cai che copre la geometria
 * prodotta da trailRegistryGpx() — stesso pattern di makeSector() in
 * wm-package/tests/Pest.php, riscritto qui perche' quell'helper non e'
 * raggiungibile dalla suite di forestas.
 */
function trailRegistryMakeSector(string $fullCode): int
{
    $properties = json_encode(['full_code' => $fullCode, 'source' => 'osm2cai']);

    return DB::selectOne(<<<'SQL'
        INSERT INTO taxonomy_wheres (name, properties, geometry, created_at, updated_at)
        VALUES (:name, :properties::jsonb, ST_GeomFromText(:wkt, 4326)::geography, now(), now())
        RETURNING id
    SQL, [
        'name' => $fullCode,
        'properties' => $properties,
        'wkt' => 'POLYGON((9 41, 9 42, 10 42, 10 41, 9 41))',
    ])->id;
}

beforeEach(function () {
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));
    trailRegistrySardegnaSentieriApp();
});

// ---------------------------------------------------------------------------
// Task 6: registrazione del codice durante l'import
// ---------------------------------------------------------------------------

it('registra nel registro il codice di un sentiero appena importato', function () {
    trailRegistryMakeSector('ZNUB5');

    $externalId = 75;
    $response = trailRegistryMinimalTrackFeature($externalId, [
        'properties' => [
            'gpx' => ['http://example.com/track.gpx'],
            'codice_cai' => 'Z-NU-B-535',
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getGpxContent')->andReturn(trailRegistryGpx());

    $track = trailRegistryMakeService($client)->importTrackFromResponse($externalId, $response);

    $code = TrailRegistryCode::where('ec_track_id', $track->id)->firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned);
});

it('non crea un secondo codice quando la stessa traccia viene reimportata', function () {
    trailRegistryMakeSector('ZNUB5');

    $externalId = 75;
    $response = trailRegistryMinimalTrackFeature($externalId, [
        'properties' => [
            'gpx' => ['http://example.com/track.gpx'],
            'codice_cai' => 'Z-NU-B-535',
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getGpxContent')->andReturn(trailRegistryGpx());

    $service = trailRegistryMakeService($client);
    $service->importTrackFromResponse($externalId, $response);
    $service->importTrackFromResponse($externalId, $response);

    expect(TrailRegistryCode::count())->toBe(1);
});

it('importa comunque la traccia se la registrazione del codice fallisce', function () {
    // Nessun settore nel database: la registrazione non puo' riuscire
    // (noSector), ma la traccia deve comunque essere salvata.
    $externalId = 75;
    $response = trailRegistryMinimalTrackFeature($externalId, [
        'properties' => [
            'gpx' => ['http://example.com/track.gpx'],
            'codice_cai' => 'Z-NU-B-535',
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getGpxContent')->andReturn(trailRegistryGpx());

    $track = trailRegistryMakeService($client)->importTrackFromResponse($externalId, $response);

    expect(EcTrack::find($track->id))->not->toBeNull();
    expect(TrailRegistryCode::count())->toBe(0);
});

it('non registra nulla per un tracciato senza ref', function () {
    trailRegistryMakeSector('ZNUB5');

    $externalId = 75;
    $response = trailRegistryMinimalTrackFeature($externalId, [
        'properties' => [
            'gpx' => ['http://example.com/track.gpx'],
            'codice_cai' => null,
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getGpxContent')->andReturn(trailRegistryGpx());

    trailRegistryMakeService($client)->importTrackFromResponse($externalId, $response);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('non tocca il registro quando il dominio e spento', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    trailRegistryMakeSector('ZNUB5');

    $externalId = 75;
    $response = trailRegistryMinimalTrackFeature($externalId, [
        'properties' => [
            'gpx' => ['http://example.com/track.gpx'],
            'codice_cai' => 'Z-NU-B-535',
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class);
    $client->shouldReceive('getGpxContent')->andReturn(trailRegistryGpx());

    trailRegistryMakeService($client)->importTrackFromResponse($externalId, $response);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});
