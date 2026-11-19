<?php

declare(strict_types=1);

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Il JSON di tutti gli enti (oc:8965), oggi per il sito WordPress: un'unica risorsa che il sito tiene in
 * cache, con i dati della scheda Drupal e tracce e POI collegati con i ruoli.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    Queue::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    config(['forestas.wp_api_token' => 'secret']);
});

function indexEnte(string $id, array $attributes = []): Ente
{
    return Ente::create(array_merge([
        'sardegnasentieri_id' => $id,
        'slug' => 'ente-'.$id,
        'name' => ['it' => 'Ente '.$id],
        'properties' => [],
    ], $attributes));
}

function indexTrack(string $ref, array $properties = []): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Sentiero '.$ref],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => array_merge(['ref' => $ref], $properties),
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((9.38 40.48 0, 9.39 40.49 0))', 4326)"),
    ]));
}

function indexPoi(string $name, array $properties = []): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => $name],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => $properties,
        'geometry' => DB::raw("ST_GeomFromText('POINT Z(9.38 40.48 0)', 4326)"),
    ]));
}

function entesLinkTrack(Ente $ente, EcTrack $track, string $role): void
{
    DB::table('enteables')->insert(['ente_id' => $ente->id, 'enteable_id' => $track->id, 'enteable_type' => EcTrack::class, 'ruolo' => $role]);
}

function entesLinkPoi(Ente $ente, EcPoi $poi, string $role): void
{
    DB::table('enteables')->insert(['ente_id' => $ente->id, 'enteable_id' => $poi->id, 'enteable_type' => WmEcPoi::class, 'ruolo' => $role]);
}

function entesJson(TestCase $case): array
{
    return $case->getJson('/api/v1/entes', ['Authorization' => 'Bearer secret'])->assertOk()->json();
}

it('restituisce gli enti con la forma concordata', function () {
    $ente = indexEnte('13', [
        'slug' => 'oasi-tepilora',
        'name' => ['it' => 'Oasi Tepilora'],
        'description' => ['it' => '<p>Complesso forestale</p>'],
        'contatti' => ['it' => '<p>Sede</p>'],
        'pagina_web' => 'https://www.sardegnaforeste.it/tepilora',
        'pagina_web_title' => 'Complesso Forestale del Tepilora',
        'geometry' => 'POINT(9.38873 40.480499)',
        'drupal_created_at' => '2013-05-09 13:05:31',
        'properties' => [
            'forestas' => ['updated_at' => '2024-03-20 15:58:28'],
            'related_url' => ['Parco di Tepilora' => 'https://www.parcoditepilora.it'],
        ],
    ]);
    $status = TaxonomyTerm::create(['identifier' => EcTrack::VALIDATION_STATUS_TAXONOMY, 'name' => ['it' => 'Stato di validazione']]);
    $validated = TaxonomyTerm::create(['parent_id' => $status->id, 'identifier' => 'validation-status-validated', 'name' => ['it' => 'Validato']]);
    $track = indexTrack('Z-NU-G-202');
    $track->taxonomyTerms()->attach($validated->id);
    entesLinkTrack($ente, $track, 'rilevatore');
    entesLinkTrack($ente, $track, 'gestore');
    $poi = indexPoi('Caserma Giuanne Stuppa');
    entesLinkPoi($ente, $poi, 'operatore');

    $json = entesJson($this);

    expect($json['generated_at'])->toBeString()
        ->and($json['data'])->toHaveCount(1);
    $item = $json['data'][0];
    expect(array_keys($item))->toBe([
        'id', 'slug', 'name', 'type', 'description', 'contacts', 'website', 'geometry',
        'feature_image', 'image_gallery', 'related_url', 'created_at', 'updated_at', 'tracks', 'pois',
    ])
        ->and($item['slug'])->toBe('oasi-tepilora')
        ->and($item['name']['it'])->toBe('Oasi Tepilora')
        ->and($item['description']['it'])->toBe('<p>Complesso forestale</p>')
        ->and($item['contacts']['it'])->toBe('<p>Sede</p>')
        ->and($item['website'])->toBe(['url' => 'https://www.sardegnaforeste.it/tepilora', 'title' => 'Complesso Forestale del Tepilora'])
        ->and($item['geometry']['type'])->toBe('Point')
        ->and($item['geometry']['coordinates'])->toBe([9.38873, 40.480499])
        ->and($item['feature_image'])->toBeNull()
        ->and($item['image_gallery'])->toBe([])
        ->and($item['related_url'])->toBe(['Parco di Tepilora' => 'https://www.parcoditepilora.it'])
        ->and($item['created_at'])->toStartWith('2013-05-09T13:05:31')
        ->and($item['updated_at'])->toBe('2024-03-20 15:58:28')
        ->and($item['tracks'])->toBe([[
            'id' => $track->id,
            'ref' => 'Z-NU-G-202',
            'name' => ['it' => 'Sentiero Z-NU-G-202'],
            'validation_status' => 'validation-status-validated',
            'roles' => ['gestore', 'rilevatore'],
            'created_at' => null,
            // Traccia con ruolo gestore: i campi della card dei «Sentieri gestiti».
            'route_type' => null,
            'feature_image' => null,
            'distance' => null,
            'duration_forward' => null,
        ]])
        ->and($item['pois'])->toBe([[
            'id' => $poi->id,
            'name' => ['it' => 'Caserma Giuanne Stuppa'],
            'roles' => ['operatore'],
        ]]);
});

it('dà immagine principale e galleria nella forma di MediaResource', function () {
    $ente = indexEnte('3');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    foreach (['principale.png', 'galleria.png'] as $i => $file) {
        $ente->addMediaFromString($png)->usingFileName($file)
            ->withCustomProperties(['caption' => 'Foto '.$i, 'author' => 'Autore', 'credits' => 'Credits'])
            ->toMediaCollection('default');
    }

    $item = entesJson($this)['data'][0];

    expect($item['feature_image']['url'])->toContain('principale')
        ->and($item['feature_image'])->toHaveKeys(['id', 'url', 'thumbnail', 'sizes'])
        ->and($item['image_gallery'])->toHaveCount(1)
        ->and($item['image_gallery'][0]['url'])->toContain('galleria');
});

it('esclude gli enti, le tracce e i POI spariti da Drupal', function () {
    $ente = indexEnte('1');
    indexEnte('2', ['properties' => ['forestas' => ['deleted_from_source' => true]]]);
    entesLinkTrack($ente, indexTrack('A'), 'gestore');
    entesLinkTrack($ente, indexTrack('B', ['forestas' => ['deleted_from_source' => true]]), 'gestore');
    entesLinkPoi($ente, indexPoi('Vivo'), 'operatore');
    entesLinkPoi($ente, indexPoi('Sparito', ['forestas' => ['deleted_from_source' => true]]), 'operatore');

    $data = entesJson($this)['data'];

    expect(array_column($data, 'slug'))->toBe(['ente-1'])
        ->and(array_column($data[0]['tracks'], 'ref'))->toBe(['A'])
        ->and(array_column(array_column($data[0]['pois'], 'name'), 'it'))->toBe(['Vivo']);
});

it('non fa una query per ente né per traccia', function () {
    $countFor = function (int $entes): int {
        DB::table('enteables')->delete();
        Ente::query()->delete();
        for ($i = 1; $i <= $entes; $i++) {
            $ente = indexEnte("q{$i}");
            entesLinkTrack($ente, indexTrack("T{$i}a"), 'gestore');
            entesLinkTrack($ente, indexTrack("T{$i}b"), 'rilevatore');
            entesLinkPoi($ente, indexPoi("P{$i}"), 'operatore');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        entesJson($this);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($countFor(5))->toBe($countFor(1));
});

it('dà ai sentieri gestiti la card del carosello e a ogni traccia la data di creazione', function () {
    // Il carosello «Sentieri gestiti» del dettaglio dell'ente sul sito (oc:8990): foto, nome con
    // codice, tempo e lunghezza, ordinati come su Drupal per data di creazione.
    $ente = indexEnte('13');
    $routeType = TaxonomyTerm::create(['identifier' => 'route-type', 'name' => ['it' => 'Tipo percorso']]);
    $trail = TaxonomyTerm::create(['parent_id' => $routeType->id, 'identifier' => 'route-type-trail', 'name' => ['it' => 'Sentiero']]);
    $managed = indexTrack('Z-NU-G-202', [
        'manual_data' => ['distance' => 12.3, 'duration_forward' => 240],
        'forestas' => ['created_at' => '2014-05-09 11:53:14'],
    ]);
    $managed->taxonomyTerms()->attach($trail->id);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $managed->addMediaFromString($png)->usingFileName('sentiero.png')->toMediaCollection('default');
    $surveyed = indexTrack('Z-NU-G-203', ['forestas' => ['created_at' => '2020-01-01 10:00:00']]);
    entesLinkTrack($ente, $managed, 'gestore');
    entesLinkTrack($ente, $surveyed, 'rilevatore');

    $tracks = collect(entesJson($this)['data'][0]['tracks'])->keyBy('ref');

    expect($tracks['Z-NU-G-202']['created_at'])->toBe('2014-05-09 11:53:14')
        ->and($tracks['Z-NU-G-202']['feature_image']['url'])->toContain('sentiero')
        ->and($tracks['Z-NU-G-202']['route_type']['identifier'])->toBe('route-type-trail')
        ->and($tracks['Z-NU-G-202']['distance'])->toBe(12.3)
        ->and($tracks['Z-NU-G-202']['duration_forward'])->toBe(240)
        ->and($tracks['Z-NU-G-203']['created_at'])->toBe('2020-01-01 10:00:00')
        ->and($tracks['Z-NU-G-203'])->not->toHaveKey('feature_image');
});

it('non carica geometria e properties intere delle tracce', function () {
    // 1.330 tracce collegate sul DB locale, 17 MB fra geometrie e properties (review oc:8965).
    $ente = indexEnte('1');
    entesLinkTrack($ente, indexTrack('A'), 'gestore');
    DB::flushQueryLog();
    DB::enableQueryLog();

    entesJson($this);

    $trackQuery = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql) => str_contains($sql, 'from "ec_tracks"'));
    DB::disableQueryLog();
    expect($trackQuery)->not->toBeNull()
        ->and($trackQuery)->not->toContain('"ec_tracks".*')
        ->and($trackQuery)->not->toContain('geometry');
});

it('risponde anche con un tipo di ente che l enum non conosce', function () {
    // L'import salva il valore grezzo quando il termine Drupal non è mappato (review oc:8965).
    $ente = indexEnte('1');
    DB::table('entes')->where('id', $ente->id)->update(['tipo_ente' => 'tipo-nuovo']);

    expect(entesJson($this)['data'][0]['type'])->toBe('tipo-nuovo');
});

it('richiede il token', function () {
    $this->getJson('/api/v1/entes')->assertUnauthorized();
});
