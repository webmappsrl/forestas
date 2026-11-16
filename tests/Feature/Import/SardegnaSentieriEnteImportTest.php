<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Enums\TipoEnte;
use App\Http\Clients\SardegnaSentieriClient;
use App\Jobs\Import\ImportSardegnaSentieriEnteJob;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

uses(RefreshDatabase::class);

/**
 * Il nodo di un ente nella forma di getNodeDetail().
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enteNode(int $nid, array $overrides = []): array
{
    return array_merge([
        'nid' => [['value' => $nid]],
        'title' => [['value' => 'Agenzia Forestas']],
        'body' => [['value' => '<p>Descrizione</p>']],
        'field_contatti' => [['value' => 'tel. 070 123']],
        'field_pagina_web' => [['uri' => 'https://www.sardegnaambiente.it']],
        'field_tipo_ente' => [['target_id' => TipoEnte::EntePartner->getDrupalId()]],
        'field_immagine_principale' => [['url' => 'https://example.org/forestas.jpg']],
        'field_geolocalizzazione' => [['lat' => 39.2, 'lon' => 9.1]],
    ], $overrides);
}

/**
 * Il service con una fonte che restituisce i nodi dati e una sync delle
 * immagini finta, restituita per le aspettative.
 *
 * @param  array<int, array<string, mixed>|Throwable>  $nodes
 * @return array{0: SardegnaSentieriImportService, 1: SardegnaSentieriSource&MockInterface, 2: SardegnaSentieriMediaSyncService&MockInterface}
 */
function enteService(array $nodes = []): array
{
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldReceive('getNodeDetail')->andReturnUsing(function (int $id) use ($nodes) {
        $node = $nodes[$id] ?? throw new RuntimeException("Nodo {$id} non trovato nella copia del DB Drupal");

        return $node instanceof Throwable ? throw $node : $node;
    });
    $mediaSync = Mockery::mock(SardegnaSentieriMediaSyncService::class);

    return [new SardegnaSentieriImportService($source, Mockery::mock(SardegnaSentieriClient::class), $mediaSync), $source, $mediaSync];
}

it('scrive l ente e salva il changed di Drupal', function () {
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281)]);
    $mediaSync->shouldReceive('syncImportedImages')->once();

    $id = $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');

    $ente = Ente::findOrFail($id);
    expect($ente->properties['forestas']['updated_at'])->toBe('2026-10-30 10:00:00')
        ->and($ente->getTranslation('name', 'it'))->toBe('Agenzia Forestas')
        ->and($ente->getTranslation('name', 'en'))->toBe('Agenzia Forestas')
        ->and($ente->tipo_ente)->toBe(TipoEnte::EntePartner);
});

it('tiene la formattazione della descrizione e toglie il resto', function () {
    // GAL BMG su Drupal ha titoli, paragrafi, link e corsivi (oc:8965).
    $body = '<h4>Il GAL</h4><p style="x">Testo con <a href="https://www.galbmg.it">link</a> e <em>corsivo</em></p><script>x()</script>';
    [$service, , $mediaSync] = enteService([3 => enteNode(3, ['body' => [['value' => $body]]])]);
    $mediaSync->shouldReceive('syncImportedImages')->once();

    $ente = Ente::findOrFail($service->importOrUpdateEnte('3', '2026-10-30 10:00:00'));

    expect($ente->getTranslation('description', 'it'))
        ->toBe('<h4>Il GAL</h4><p>Testo con <a href="https://www.galbmg.it">link</a> e <em>corsivo</em></p>');
});

it('normalizza lo slug di Drupal in ASCII', function (array $overrides, string $expected) {
    // WordPress costruisce gli URL con sanitize_title(), che toglie gli accenti (oc:8965).
    [$service, , $mediaSync] = enteService([82 => enteNode(82, $overrides)]);
    $mediaSync->shouldReceive('syncImportedImages')->once();

    $ente = Ente::findOrFail($service->importOrUpdateEnte('82', '2026-10-30 10:00:00'));

    expect($ente->slug)->toBe($expected);
})->with([
    'slug accentato' => [['slug' => 'comune-di-lodè'], 'comune-di-lode'],
    'slug già pulito' => [['slug' => 'oasi-tepilora'], 'oasi-tepilora'],
    'senza slug, dal titolo' => [['title' => [['value' => 'Département de la Haute-Corse']]], 'departement-de-la-haute-corse'],
]);

it('aggiorna l indice dell ente dopo le immagini', function () {
    $engine = Mockery::spy(Engine::class);
    app(EngineManager::class)->extend('spy', fn () => $engine);
    config(['scout.driver' => 'spy']);
    [$service, , $mediaSync] = enteService([13 => enteNode(13)]);
    $imagesSynced = false;
    $mediaSync->shouldReceive('syncImportedImages')->once()->andReturnUsing(function () use (&$imagesSynced) {
        $imagesSynced = true;
    });
    $indexedAfterImages = null;
    $engine->shouldReceive('update')->andReturnUsing(function ($models) use (&$imagesSynced, &$indexedAfterImages) {
        $indexedAfterImages = $imagesSynced && $models->first()?->sardegnasentieri_id === '13';
    });

    $service->importOrUpdateEnte('13', '2026-10-30 10:00:00');

    expect($indexedAfterImages)->toBeTrue();
});

it('svuota i campi svuotati in Drupal', function () {
    $empties = ['body' => [], 'field_contatti' => [], 'field_pagina_web' => [], 'field_geolocalizzazione' => []];
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281)]);
    $mediaSync->shouldReceive('syncImportedImages');
    $id = $service->importOrUpdateEnte('5281', '2026-10-29 10:00:00');

    // Lo stesso ente, riletto dopo che in Drupal hanno svuotato i campi.
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281, $empties)]);
    $mediaSync->shouldReceive('syncImportedImages');
    $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');

    $ente = Ente::findOrFail($id);
    expect($ente->getRawOriginal('description'))->toBeNull()
        ->and($ente->getRawOriginal('contatti'))->toBeNull()
        ->and($ente->pagina_web)->toBeNull()
        ->and($ente->getRawOriginal('geometry'))->toBeNull();
});

it('svuota i campi anche di un ente senza tipo in Drupal', function () {
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281)]);
    $mediaSync->shouldReceive('syncImportedImages');
    $id = $service->importOrUpdateEnte('5281', '2026-10-29 10:00:00');

    // In Drupal tolgono il tipo e svuotano i contatti: il ramo del tipo
    // non mappato non deve far perdere le altre modifiche.
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281, ['field_tipo_ente' => [], 'field_contatti' => []])]);
    $mediaSync->shouldReceive('syncImportedImages');
    $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');

    $ente = Ente::findOrFail($id);
    expect($ente->getRawOriginal('contatti'))->toBeNull()
        ->and($ente->getRawOriginal('tipo_ente'))->toBeNull();
});

it('toglie l immagine rimossa in Drupal', function () {
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281, ['field_immagine_principale' => []])]);
    $mediaSync->shouldReceive('syncImportedImages')->once()->withArgs(fn ($ente, array $items) => $items === []);

    $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');
});

it('toglie il marchio di ente sparito', function () {
    Ente::create([
        'sardegnasentieri_id' => '5281',
        'name' => ['it' => 'Vecchio nome'],
        'properties' => ['forestas' => ['updated_at' => '2026-01-01 00:00:00', 'deleted_from_source' => true]],
    ]);
    [$service, , $mediaSync] = enteService([5281 => enteNode(5281)]);
    $mediaSync->shouldReceive('syncImportedImages');

    $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');

    $properties = Ente::where('sardegnasentieri_id', '5281')->firstOrFail()->properties;
    expect($properties['forestas'])->not->toHaveKey('deleted_from_source')
        ->and($properties['forestas']['updated_at'])->toBe('2026-10-30 10:00:00');
});

it('fallisce se il nodo non si legge', function () {
    [$service] = enteService([5281 => new RuntimeException('copia irraggiungibile')]);

    $service->importOrUpdateEnte('5281', '2026-10-30 10:00:00');
})->throws(RuntimeException::class, 'copia irraggiungibile');

it('un sentiero collega solo enti esistenti', function () {
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
    $user->assignRole('Editor');
    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID, 'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
    ]));
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));
    $gestore = Ente::create(['sardegnasentieri_id' => '5281', 'name' => ['it' => 'Agenzia Forestas']]);
    [$service, $source] = enteService();
    $source->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);

    $track = $service->importTrackFromResponse(1836, ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.1, 40.1], [9.2, 40.2]]],
        'properties' => [
            'id' => '1836', 'type' => 'sentiero', 'name' => ['it' => 'Sentiero'],
            'updated_at' => '2026-10-30 10:00:00',
            'ente_istituzione_societa' => ['soggetto_gestore' => '5281', 'soggetto_manutentore' => '9999'],
        ],
    ]));

    expect(DB::table('enteables')->where('enteable_id', $track->id)->get(['ente_id', 'ruolo'])->map(fn ($r) => (array) $r)->all())
        ->toBe([['ente_id' => $gestore->id, 'ruolo' => 'gestore']])
        ->and(Ente::count())->toBe(1);
    $source->shouldNotHaveReceived('getNodeDetail');
    // Il manutentore mancante si ricorda: il comando rimette in coda il
    // sentiero al giro dopo, anche se in Drupal non e' cambiato.
    expect($track->fresh()->properties['forestas']['enti_mancanti'] ?? null)->toBeTrue();
});

it('un sentiero con tutti gli enti presenti non resta da ricollegare', function () {
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
    $user->assignRole('Editor');
    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID, 'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
    ]));
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));
    Ente::create(['sardegnasentieri_id' => '5281', 'name' => ['it' => 'Agenzia Forestas']]);
    [$service, $source] = enteService();
    $source->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);

    $track = $service->importTrackFromResponse(1836, ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.1, 40.1], [9.2, 40.2]]],
        'properties' => [
            'id' => '1836', 'type' => 'sentiero', 'name' => ['it' => 'Sentiero'],
            'updated_at' => '2026-10-30 10:00:00',
            'ente_istituzione_societa' => ['soggetto_gestore' => '5281'],
        ],
    ]));

    expect($track->fresh()->properties['forestas'])->not->toHaveKey('enti_mancanti');
});

it('il job importa l ente', function () {
    $service = Mockery::mock(SardegnaSentieriImportService::class);
    $service->shouldReceive('importOrUpdateEnte')->once()->with('5281', '2026-10-30 10:00:00')->andReturn(1);

    (new ImportSardegnaSentieriEnteJob(5281, '2026-10-30 10:00:00'))->handle($service);
});

it('il job aspetta la ricarica della copia', function () {
    $lock = storage_path('framework/testing/reload-ente.lock');
    File::ensureDirectoryExists(dirname($lock));
    File::put($lock, '');
    config(['forestas.sardegnasentieri.reload_lock_path' => $lock]);
    $service = Mockery::mock(SardegnaSentieriImportService::class);
    $service->shouldNotReceive('importOrUpdateEnte');

    try {
        $job = (new ImportSardegnaSentieriEnteJob(5281, '2026-10-30 10:00:00'))->withFakeQueueInteractions();
        $job->handle($service);
        $job->assertReleased(600);
    } finally {
        File::delete($lock);
    }
});

it('il job sta sulla coda dell import', function () {
    expect((new ImportSardegnaSentieriEnteJob(5281, '2026-10-30 10:00:00'))->queue)->toBe('sardegnasentieri-import');
});
