<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

/**
 * Le colonne di Forestas su ec_tracks (oc:8910): l'import scrive quelle che vengono da Drupal e
 * non tocca mai le tre dell'inclusività.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);

    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));
});

function importWithColumns(array $extra = [], int $nid = 80): EcTrack
{
    $feature = ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]],
        'properties' => array_merge([
            'id' => (string) $nid,
            'name' => ['it' => 'Sentiero Test'],
            'type' => 'sentiero',
            'updated_at' => '2026-10-01T10:00:00',
            'codice' => 'SE-001',
            'data_rilievo' => '2024-05-17 10:30:00',
            'taxonomies' => ['zona_geografica' => ['11', '12']],
        ], $extra),
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class.', '.SardegnaSentieriSource::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);

    $media = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $media->shouldIgnoreMissing();

    return (new SardegnaSentieriImportService($client, $client, $media))->importTrack($nid);
}

it('scrive codice e data di rilievo in colonna e lascia la zona geografica nel JSON', function () {
    $track = importWithColumns()->fresh();

    expect($track->forestas_registry_code)->toBe('SE-001')
        ->and($track->forestas_survey_date?->format('Y-m-d'))->toBe('2024-05-17')
        ->and($track->properties['forestas'])->not->toHaveKeys(['codice', 'data_rilievo'])
        ->and($track->properties['forestas']['zona_geografica'])->toHaveCount(2);
});

it('non tocca le colonne dell\'inclusività a un reimport', function () {
    $track = importWithColumns();
    $track->forceFill([
        'forestas_inclusivity_physical' => 30,
        'forestas_inclusivity_aids' => 5,
        'forestas_inclusivity_digital' => 0,
    ])->saveQuietly();

    importWithColumns(['codice' => 'SE-002']);

    $track = $track->fresh();
    expect($track->forestas_registry_code)->toBe('SE-002')
        ->and($track->forestas_inclusivity_physical)->toBe(30)
        ->and($track->forestas_inclusivity_aids)->toBe(5)
        ->and($track->forestas_inclusivity_digital)->toBe(0);
});

it('espone le colonne dentro forestas nel dettaglio, non piatte (oc:8959)', function () {
    $track = importWithColumns();
    $track->forceFill([
        'forestas_inclusivity_physical' => 30,
        'forestas_inclusivity_aids' => 5,
        'forestas_inclusivity_digital' => 0,
    ])->saveQuietly();

    $properties = $track->fresh()->getDetailGeojson()['properties'];

    expect((array) $properties['forestas'])->toMatchArray([
        'registry_code' => 'SE-001',
        'survey_date' => '2024-05-17',
    ])
        ->and($properties['forestas']['inclusivity'])->toMatchArray(['physical' => 30, 'aids' => 5, 'digital' => 0])
        ->and($properties)->not->toHaveKey('forestas_registry_code');
});

it('conserva i due testi liberi dell\'inclusività a un reimport', function () {
    $track = importWithColumns();
    $properties = $track->properties;
    $properties['forestas']['inclusivity_independent_use'] = 'Con accompagnatore';
    $properties['forestas']['inclusivity_assistance'] = 'Su prenotazione';
    $track->forceFill(['properties' => $properties])->saveQuietly();

    importWithColumns(['codice' => 'SE-002']);

    $forestas = $track->fresh()->properties['forestas'];
    expect($forestas['inclusivity_independent_use'])->toBe('Con accompagnatore')
        ->and($forestas['inclusivity_assistance'])->toBe('Su prenotazione')
        ->and($forestas['zona_geografica'])->toHaveCount(2);
});
