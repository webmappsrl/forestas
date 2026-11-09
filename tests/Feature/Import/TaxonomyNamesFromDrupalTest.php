<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Services\StorageService;

/**
 * Il nome di tipi di POI e attivita' e' quello del termine Drupal, non
 * l'identificativo tecnico (oc:8932). L'identifier non cambia.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));

    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    App::withoutEvents(fn () => App::query()->firstOrCreate(
        ['id' => SardegnaSentieriImportService::IMPORT_APP_ID],
        [
            'name' => 'Sardegna Sentieri',
            'sku' => 'it.webmapp.sardegnasentieri',
            'customer_name' => 'forestas',
            'user_id' => $user->id,
        ]
    ));
});

/**
 * @param  array<string, array<int|string, array<string, mixed>>>  $vocabularies  vocabolario => termini
 */
function taxonomyNamesService(array $vocabularies): SardegnaSentieriImportService
{
    $source = mockSardegnaSentieriSource();
    $source->shouldReceive('getTaxonomy')->andReturnUsing(fn (string $vid) => $vocabularies[$vid] ?? []);
    $source->shouldReceive('getTaxonomyWarnings')->andReturn([]);

    $mediaSync = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldIgnoreMissing();

    return new SardegnaSentieriImportService($source, $source, $mediaSync);
}

it('usa il nome del termine Drupal e non l\'identifier', function () {
    taxonomyNamesService([
        'tipologia_poi' => [
            '10' => ['geohub_identifier' => 'monumental-tree', 'name' => ['it' => 'Alberi monumentali']],
        ],
        'tipologia_sentieri' => [
            '20' => ['geohub_identifier' => 'hiking', 'name' => ['it' => 'Escursionismo']],
        ],
    ])->importAll();

    $poiType = TaxonomyPoiType::where('identifier', 'monumental-tree')->firstOrFail();
    expect($poiType->getTranslation('name', 'it'))->toBe('Alberi monumentali')
        ->and($poiType->identifier)->toBe('monumental-tree');

    $activity = TaxonomyActivity::where('identifier', 'hiking')->firstOrFail();
    expect($activity->getTranslation('name', 'it'))->toBe('Escursionismo');
});

it('non cambia l\'identifier, così non nascono termini doppi', function () {
    TaxonomyPoiType::withoutEvents(fn () => TaxonomyPoiType::create(['identifier' => 'monumental-tree', 'name' => 'monumental-tree']));

    taxonomyNamesService([
        'tipologia_poi' => [
            '10' => ['geohub_identifier' => 'monumental-tree', 'name' => ['it' => 'Alberi monumentali']],
        ],
    ])->importAll();

    expect(TaxonomyPoiType::where('identifier', 'monumental-tree')->count())->toBe(1)
        ->and(TaxonomyPoiType::count())->toBe(1)
        ->and(TaxonomyPoiType::first()->getTranslation('name', 'it'))->toBe('Alberi monumentali');
});

it('porta la traduzione inglese quando Drupal ce l\'ha', function () {
    taxonomyNamesService([
        'tipologia_poi' => [
            '10' => ['geohub_identifier' => 'monumental-tree', 'name' => ['it' => 'Alberi monumentali', 'en' => 'Monumental trees']],
            '11' => ['geohub_identifier' => 'crossroads', 'name' => ['it' => 'Incroci - waypoint']],
        ],
    ])->importAll();

    $withEnglish = TaxonomyPoiType::where('identifier', 'monumental-tree')->firstOrFail();
    expect($withEnglish->getTranslation('name', 'en'))->toBe('Monumental trees');

    // Dove l'inglese manca non si scrive niente: il campo ripiega sull'italiano.
    $withoutEnglish = TaxonomyPoiType::where('identifier', 'crossroads')->firstOrFail();
    expect($withoutEnglish->getTranslation('name', 'it'))->toBe('Incroci - waypoint')
        ->and($withoutEnglish->getTranslations('name'))->not->toHaveKey('en');
});

it('con più termini sullo stesso identifier vince l\'ultimo, come source_id', function () {
    taxonomyNamesService([
        'tipologia_poi' => [
            '1' => ['geohub_identifier' => 'crossroads', 'name' => ['it' => 'trekking - waypoint']],
            '2' => ['geohub_identifier' => 'crossroads', 'name' => ['it' => 'Incroci - waypoint']],
        ],
    ])->importAll();

    $poiType = TaxonomyPoiType::where('identifier', 'crossroads')->firstOrFail();
    expect(TaxonomyPoiType::count())->toBe(1)
        ->and($poiType->properties['source_id'])->toBe('2')
        ->and($poiType->getTranslation('name', 'it'))->toBe('Incroci - waypoint');
});

it('risolve l\'icona dall\'identifier e non dal nome del termine', function () {
    // L'icona corrisponde all'identifier, non a «Alberi monumentali».
    Storage::disk('wmfe')->put(app(StorageService::class)->getShardBasePath(SardegnaSentieriImportService::IMPORT_APP_ID).'icons.json', json_encode([
        'height' => 1024,
        'icons' => [['properties' => ['name' => 'txn-monumental-tree']]],
    ]));

    taxonomyNamesService([
        'tipologia_poi' => [
            '10' => ['geohub_identifier' => 'monumental-tree', 'name' => ['it' => 'Alberi monumentali']],
        ],
        'tipologia_sentieri' => [
            '20' => ['geohub_identifier' => 'monumental-tree', 'name' => ['it' => 'Alberi monumentali']],
        ],
    ])->importAll();

    expect(TaxonomyPoiType::where('identifier', 'monumental-tree')->firstOrFail()->getAttribute('icon'))->toBe('txn-monumental-tree')
        ->and(TaxonomyActivity::where('identifier', 'monumental-tree')->firstOrFail()->getAttribute('icon'))->toBe('txn-monumental-tree');
});
