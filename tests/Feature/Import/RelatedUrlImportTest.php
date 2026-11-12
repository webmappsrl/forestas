<?php

declare(strict_types=1);

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

uses(RefreshDatabase::class);

/**
 * Collegamenti esterni di Drupal in `properties.related_url`, come
 * etichetta => indirizzo, su percorsi e punti di interesse (oc:8932).
 * `related_url` non e' una colonna: vive dentro `properties`.
 */
beforeEach(function () {
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    if (! App::query()->find(SardegnaSentieriImportService::IMPORT_APP_ID)) {
        App::withoutEvents(fn () => App::query()->create([
            'id' => SardegnaSentieriImportService::IMPORT_APP_ID,
            'name' => 'Sardegna Sentieri',
            'sku' => 'it.webmapp.sardegnasentieri',
            'customer_name' => 'forestas',
            'user_id' => $user->id,
        ]));
    }
});

function relatedUrlService(): SardegnaSentieriImportService
{
    $mediaSync = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldIgnoreMissing();

    return new SardegnaSentieriImportService(
        Mockery::mock(SardegnaSentieriSource::class),
        Mockery::mock(SardegnaSentieriClient::class),
        $mediaSync,
    );
}

/** @param  list<array{uri: string, title: string}>  $links */
function relatedUrlPoi(int $id, array $links): ApiPoiResponse
{
    return ApiPoiResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [9.19, 41.10]],
        'properties' => [
            'id' => (string) $id,
            'name' => ['it' => 'Punto'],
            'collegamenti' => $links,
            'taxonomies' => ['tipologia_poi' => [], 'zona_geografica' => []],
        ],
    ]);
}

/** @param  list<array{uri: string, title: string}>  $links */
function relatedUrlTrack(int $id, array $links): ApiTrackResponse
{
    return ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 41.10], [9.20, 41.11]]],
        'properties' => [
            'id' => (string) $id,
            'name' => ['it' => 'Sentiero'],
            'type' => 'sentiero',
            'collegamenti' => $links,
            'taxonomies' => [
                'categorie_fruibilita_sentieri' => [],
                'tipologia_sentieri' => [],
                'stato_di_validazione' => [],
                'zona_geografica' => [],
            ],
        ],
    ]);
}

function relatedUrlSource(): SardegnaSentieriDatabaseSource
{
    try {
        $source = app(SardegnaSentieriDatabaseSource::class);
        $source->getPoiList();

        return $source;
    } catch (Throwable) {
        Assert::markTestSkipped('Copia del DB Drupal non raggiungibile');
    }
}

it('converte la lista di Drupal in label => url su punti di interesse', function () {
    $poi = relatedUrlService()->importPoiFromResponse(9001, relatedUrlPoi(9001, [
        ['uri' => 'http://www.wwf.it/oasi/sardegna/monte_arcosu/', 'title' => 'WWW oasi di monte Arcosu'],
    ]));

    $properties = EcPoi::findOrFail($poi->id)->properties;

    expect($properties['related_url'])->toBe(['WWW oasi di monte Arcosu' => 'http://www.wwf.it/oasi/sardegna/monte_arcosu/'])
        ->and($properties['forestas'])->not->toHaveKey('collegamenti');
});

it('importa i collegamenti degli itinerari', function () {
    $track = relatedUrlService()->importTrackFromResponse(9002, relatedUrlTrack(9002, [
        ['uri' => 'https://a.it', 'title' => 'A'],
        ['uri' => 'https://b.it', 'title' => 'B'],
    ]));

    expect(EcTrack::findOrFail($track->id)->properties['related_url'])->toBe(['A' => 'https://a.it', 'B' => 'https://b.it']);
});

it('regge un collegamento senza titolo', function () {
    $poi = relatedUrlService()->importPoiFromResponse(9003, relatedUrlPoi(9003, [
        ['uri' => 'https://senza-titolo.it', 'title' => ''],
    ]));

    expect(EcPoi::findOrFail($poi->id)->properties['related_url'])->toBe(['https://senza-titolo.it' => 'https://senza-titolo.it']);
});

it('toglie la chiave quando Drupal non ha piu\' collegamenti', function () {
    $service = relatedUrlService();
    $service->importPoiFromResponse(9004, relatedUrlPoi(9004, [['uri' => 'https://a.it', 'title' => 'A']]));
    $poi = $service->importPoiFromResponse(9004, relatedUrlPoi(9004, []));

    expect(EcPoi::findOrFail($poi->id)->properties)->not->toHaveKey('related_url');
});

it('legge i collegamenti dalla copia Drupal: 47 punti di interesse e 75 itinerari', function () {
    $source = relatedUrlSource();

    $pois = collect(array_keys($source->getPoiList()))
        ->filter(fn (string $id) => $source->getPoiDetail((int) $id)->collegamenti !== []);
    $tracks = collect(array_keys($source->getTrackList()))
        ->filter(fn (string $id) => $source->getTrackDetail((int) $id)->collegamenti !== []);

    expect($pois)->toHaveCount(47)
        ->and($tracks)->toHaveCount(75)
        ->and($source->getPoiDetail(94)->collegamenti[1])->toBe([
            'uri' => 'http://www.sardegnaambiente.it/j/v/1189?v=2&c=7216&s=8448',
            'title' => 'Foresta Demaniale di Monte Pisanu',
        ]);
});

it('tiene interi i titoli con la virgola, accoppiati al loro indirizzo', function () {
    // POI 1046: le API spezzavano il titolo su «, » e perdevano l'accoppiamento.
    $links = relatedUrlSource()->getPoiDetail(1046)->collegamenti;
    $titles = array_column($links, 'title');

    expect($titles)->toContain('Comune di Oliena, servizi turistici')
        ->and($titles)->toContain('Comune di Oliena, strutture ricettive');

    $poi = relatedUrlService()->importPoiFromResponse(9005, relatedUrlPoi(9005, $links));
    $related = EcPoi::findOrFail($poi->id)->properties['related_url'];
    foreach ($links as $link) {
        expect($related[$link['title']])->toBe($link['uri']);
    }
});
