<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Models\User;
use App\Nova\TaxonomyWarning as TaxonomyWarningResource;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Le avvertenze convergono nel modello unico (oc:8910): erano una tassonomia a sé, con le sue due
 * tabelle, e diventano la tassonomia `warnings`. I tid sono quelli veri della copia di Drupal.
 */
uses(RefreshDatabase::class);

/** Le due avvertenze di prova, con i tid e i nomi veri. */
const AVVERTENZE_DRUPAL = [
    4716 => ['name' => ['it' => 'Cinghiali', 'en' => 'wild boars']],
    4718 => ['name' => ['it' => 'Zecche', 'en' => 'watch out for ticks']],
];

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

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();
});

function warningsService(array $warningsTrack = []): array
{
    $feature = ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]],
        'properties' => [
            'id' => '75',
            'name' => ['it' => 'Sentiero Test'],
            'type' => 'sentiero',
            'updated_at' => '2026-10-01T10:00:00',
            'taxonomies' => [
                'tipologia_di_avvertenze' => array_map('strval', $warningsTrack),
                'categorie_fruibilita_sentieri' => [],
                'fruibilita_padri' => [],
            ],
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class.', '.SardegnaSentieriSource::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getTaxonomy')->andReturn([]);
    $client->shouldReceive('getTaxonomyWarnings')->andReturn(AVVERTENZE_DRUPAL);

    $media = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $media->shouldIgnoreMissing();

    return [new SardegnaSentieriImportService($client, $client, $media), $client];
}

function warningsOf(EcTrack $track): array
{
    return $track->fresh()->terms('warnings')->pluck('identifier')->sort()->values()->all();
}

it('importa le avvertenze come termini della tassonomia warnings', function () {
    [$service] = warningsService();
    $service->importAll();

    $taxonomy = TaxonomyTerm::query()->taxonomies()->where('identifier', 'warnings')->first();

    expect($taxonomy)->not->toBeNull()
        ->and($taxonomy->children()->pluck('identifier')->all())
        ->toBe(['sardegnasentieri:warning:4716', 'sardegnasentieri:warning:4718']);

    $cinghiali = TaxonomyTerm::where('identifier', 'sardegnasentieri:warning:4716')->first();

    expect($cinghiali->getTranslation('name', 'it'))->toBe('Cinghiali')
        ->and($cinghiali->getTranslation('name', 'en'))->toBe('wild boars');
});

it('non duplica i termini se l importa due volte', function () {
    [$service] = warningsService();
    $service->importAll();
    $service->importAll();

    expect(TaxonomyTerm::where('identifier', 'like', 'sardegnasentieri:warning:%')->count())->toBe(2);
});

it('lega le avvertenze al sentiero e non ne accumula al reimport', function () {
    [$service] = warningsService([4716]);
    $service->importAll();

    $track = $service->importTrack(75);
    $service->importTrack(75);

    expect(warningsOf($track))->toBe(['sardegnasentieri:warning:4716']);
});

it('toglie l avvertenza che Drupal non porta più', function () {
    [$service] = warningsService([4716, 4718]);
    $service->importAll();
    $track = $service->importTrack(75);

    expect(warningsOf($track))->toHaveCount(2);

    [$service2] = warningsService([4718]);
    $service2->importTrack(75);

    expect(warningsOf($track))->toBe(['sardegnasentieri:warning:4718']);
});

it('non lascia in giro le tabelle vecchie', function () {
    expect(Schema::hasTable('taxonomy_warnings'))->toBeFalse()
        ->and(Schema::hasTable('taxonomy_warningables'))->toBeFalse();
});

it('mostra nella vista Avvertenze solo i termini di quelil tassonomia', function () {
    [$service] = warningsService();
    $service->importAll();

    $identifiers = TaxonomyWarningResource::indexQuery(
        NovaRequest::create('/nova-api/taxonomy-warnings', 'GET'),
        TaxonomyTerm::query()
    )->pluck('identifier')->all();

    expect($identifiers)->toBe(['sardegnasentieri:warning:4716', 'sardegnasentieri:warning:4718']);
});

it('conserva l indirizzo Nova di prima', function () {
    expect(TaxonomyWarningResource::uriKey())->toBe('taxonomy-warnings');
});

it('la pagina Nova delle avvertenze risponde e mostra i nomi', function () {
    [$service] = warningsService();
    $service->importAll();

    RolesAndPermissionsService::seedDatabase();
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    // In Nova il titolo è il nome nella lingua dell'interfaccia più l'identifier.
    app()->setLocale('it');
    $index = $this->actingAs($admin)->getJson('/nova-api/taxonomy-warnings')->assertOk();

    expect(collect($index->json('resources'))->pluck('title')->sort()->values()->all())
        ->toBe(['Cinghiali', 'Zecche']);
});
