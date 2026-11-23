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
 *
 * I termini li crea l'import, perché la sorgente del dato è Drupal: il comando crea solo la
 * tassonomia, vuota. Il nome però è nostro — dalla mappatura, o dallo slug dell'inglese, o da
 * quello dell'italiano — e il tid resta in `properties->source->drupal_tid`, che è anche il modo
 * in cui l'import riconosce un termine già creato.
 */
uses(RefreshDatabase::class);

/**
 * Le avvertenze di prova. Le prime due hanno i tid e i nomi veri e stanno nell'elenco di sistema;
 * la terza non c'e' ancora da nessuna parte, come un'avvertenza aggiunta oggi a Drupal.
 */
const AVVERTENZE_DRUPAL = [
    4716 => ['name' => ['it' => 'Cinghiali', 'en' => 'wild boars']],
    4718 => ['name' => ['it' => 'Zecche', 'en' => 'watch out for ticks']],
    9999 => ['name' => ['it' => 'Rischio meduse', 'en' => 'jellyfish']],
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

function warningsService(array $warningsTrack = [], ?array $warnings = null, ?array $taxonomy = null): array
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
    $client->shouldReceive('getTaxonomyWarnings')->andReturn($taxonomy ?? AVVERTENZE_DRUPAL);

    $media = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $media->shouldIgnoreMissing();

    return [new SardegnaSentieriImportService($client, $client, $media), $client];
}

function warningsOf(EcTrack $track): array
{
    return $track->fresh()->terms('warnings')->pluck('identifier')->sort()->values()->all();
}

it('crea i termini delle avvertenze con il nome nostro e il tid di Drupal fra le properties', function () {
    $taxonomy = TaxonomyTerm::query()->taxonomies()->where('identifier', 'warnings')->first();

    // La tassonomia nasce dal comando, vuota: i termini li porta l'import.
    expect($taxonomy)->not->toBeNull()
        ->and($taxonomy->children()->count())->toBe(0);

    [$service] = warningsService();
    $service->importAll();

    $cinghiali = TaxonomyTerm::where('identifier', 'warnings-wild-boar')->firstOrFail();

    expect($cinghiali->getTranslation('name', 'it'))->toBe('Cinghiali')
        ->and($cinghiali->properties['source']['drupal_tid'])->toBe(4716)
        ->and($cinghiali->parent->identifier)->toBe('warnings');
});

it('non lascia nessun termine con l identifier di Drupal', function () {
    [$service] = warningsService();
    $service->importAll();

    expect(TaxonomyTerm::where('identifier', 'like', 'sardegnasentieri:%')->count())->toBe(0);
});

it('crea l avvertenza nuova con il nome inglese, non con il tid', function () {
    [$service] = warningsService();

    $service->importAll();

    $nuova = TaxonomyTerm::where('identifier', 'warnings-jellyfish')->first();

    expect($nuova)->not->toBeNull()
        ->and($nuova->getTranslation('name', 'it'))->toBe('Rischio meduse')
        ->and($nuova->properties['source']['drupal_tid'])->toBe(9999)
        ->and($nuova->parent->identifier)->toBe('warnings');
});

it('usa il nome della mappatura, non lo slug dell inglese discorsivo di Drupal', function () {
    [$service] = warningsService();

    $service->importAll();
    $service->importAll();

    // «wild boars» darebbe `warnings-wild-boars`: vince la mappatura. «jellyfish» non e' nella
    // mappatura e prende lo slug dell'inglese.
    expect(TaxonomyTerm::where('identifier', 'warnings-wild-boar')->count())->toBe(1)
        ->and(TaxonomyTerm::where('identifier', 'warnings-wild-boars')->count())->toBe(0)
        ->and(TaxonomyTerm::where('identifier', 'warnings-jellyfish')->count())->toBe(1);
});

it('lega le avvertenze al sentiero e non ne accumula al reimport', function () {
    [$service] = warningsService([4716]);
    $service->importAll();

    $track = $service->importTrack(75);
    $service->importTrack(75);

    expect(warningsOf($track))->toBe(['warnings-wild-boar']);
});

it('usa la mappatura anche quando Drupal non da il nome inglese', function () {
    [$service] = warningsService(warnings: [], taxonomy: [
        4720 => ['name' => ['it' => 'Rischio nebbia']],
    ]);

    $service->importAll();

    expect(TaxonomyTerm::where('identifier', 'warnings-fog')->count())->toBe(1);
});

it('importa l avvertenza anche senza nome inglese ne mappatura, con lo slug dell italiano', function () {
    // Il dato non si perde mai: un nome da tradurre e' un fastidio, un'avvertenza persa e' un
    // sentiero che non avverte di un pericolo.
    [$service] = warningsService(warnings: [], taxonomy: [
        7777 => ['name' => ['it' => 'Rischio sconosciuto']],
    ]);

    $service->importAll();

    $termine = TaxonomyTerm::where('identifier', 'warnings-rischio-sconosciuto')->first();

    expect($termine)->not->toBeNull()
        ->and($termine->properties['source']['drupal_tid'])->toBe(7777)
        ->and(TaxonomyTerm::where('identifier', 'like', '%7777%')->count())->toBe(0);
});

it('al secondo giro non riscrive niente', function () {
    [$service] = warningsService();
    $service->importAll();

    $prima = TaxonomyTerm::where('identifier', 'warnings-wild-boar')->firstOrFail();
    $quando = $prima->updated_at;

    $this->travel(2)->minutes();
    $service->importAll();

    expect(TaxonomyTerm::where('identifier', 'warnings-wild-boar')->value('updated_at'))
        ->toEqual($quando);
});

it('non ruba il termine di un altra avvertenza quando due nomi danno lo stesso identifier', function () {
    // Il tid 8888 vorrebbe `warnings-ticks`, che e' gia' del 4718: rubarglielo staccherebbe il
    // 4718 dai suoi sentieri. Non si importa e il log dice che serve un nome diverso.
    [$service] = warningsService(warnings: [], taxonomy: [
        4718 => ['name' => ['it' => 'Zecche', 'en' => 'watch out for ticks']],
        8888 => ['name' => ['it' => 'Altre zecche', 'en' => 'ticks']],
    ]);

    $service->importAll();

    $termine = TaxonomyTerm::where('identifier', 'warnings-ticks')->firstOrFail();

    expect($termine->properties['source']['drupal_tid'])->toBe(4718)
        ->and($termine->getTranslation('name', 'it'))->toBe('Zecche')
        ->and(TaxonomyTerm::where('identifier', 'like', 'warnings-%')->count())->toBe(1);
});

it('non attacca al sentiero un avvertenza che la sorgente non descrive', function () {
    // Un tid che Drupal cita ma non descrive non fa nascere niente: resta un'anomalia.
    [$service] = warningsService([12345]);

    $track = $service->importTrack(75);

    expect(warningsOf($track))->toBe([]);
});

it('toglie l avvertenza che Drupal non porta più', function () {
    [$service] = warningsService([4716, 4718]);
    $service->importAll();
    $track = $service->importTrack(75);

    expect(warningsOf($track))->toHaveCount(2);

    [$service2] = warningsService([4718]);
    $service2->importTrack(75);

    expect(warningsOf($track))->toBe(['warnings-ticks']);
});

it('non lascia in giro le tabelle vecchie', function () {
    expect(Schema::hasTable('taxonomy_warnings'))->toBeFalse()
        ->and(Schema::hasTable('taxonomy_warningables'))->toBeFalse();
});

it('mostra nella vista Avvertenze solo i termini di quella tassonomia', function () {
    [$service] = warningsService();
    $service->importAll();

    $identifiers = TaxonomyWarningResource::indexQuery(
        NovaRequest::create('/nova-api/taxonomy-warnings', 'GET'),
        TaxonomyTerm::query()
    )->pluck('identifier')->all();

    expect($identifiers)->toContain('warnings-wild-boar', 'warnings-ticks')
        ->and($identifiers)->each->toStartWith('warnings-');
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

    expect(collect($index->json('resources'))->pluck('title')->all())
        ->toContain('Cinghiali', 'Zecche');
});
