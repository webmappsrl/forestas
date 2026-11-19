<?php

declare(strict_types=1);

use App\Models\EcPoi;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;

/**
 * Il modello Ente per l'API degli enti (oc:8965): POI collegati, enti ancora presenti in Drupal,
 * miniature delle immagini.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function modelPoi(): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'Caserma Giuanne Stuppa'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [],
        'geometry' => DB::raw("ST_GeomFromText('POINT Z(9.38 40.48 0)', 4326)"),
    ]));
}

it('collega i POI con il tipo del package e il ruolo', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '13', 'name' => ['it' => 'Oasi Tepilora'], 'properties' => []]);
    $linked = modelPoi();
    $otherType = modelPoi();
    DB::table('enteables')->insert([
        ['ente_id' => $ente->id, 'enteable_id' => $linked->id, 'enteable_type' => WmEcPoi::class, 'ruolo' => 'operatore'],
        // Una riga con il tipo di forestas non è un collegamento scritto dall'import.
        ['ente_id' => $ente->id, 'enteable_id' => $otherType->id, 'enteable_type' => EcPoi::class, 'ruolo' => 'operatore'],
    ]);

    $pois = $ente->ecPois()->get();

    expect($pois->pluck('id')->all())->toBe([$linked->id])
        ->and($pois->first())->toBeInstanceOf(EcPoi::class)
        ->and($pois->first()->getRelation('pivot')->getAttribute('ruolo'))->toBe('operatore');
});

it('esclude gli enti spariti da Drupal', function () {
    Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Attivo'], 'properties' => []]);
    Ente::create(['sardegnasentieri_id' => '2', 'name' => ['it' => 'Senza properties']]);
    Ente::create(['sardegnasentieri_id' => '3', 'name' => ['it' => 'Sparito'], 'properties' => ['forestas' => ['deleted_from_source' => true]]]);

    expect(Ente::active()->pluck('sardegnasentieri_id')->sort()->values()->all())->toBe(['1', '2']);
});

it('registra una conversione per ogni misura delle miniature', function () {
    $ente = new Ente;
    $ente->registerMediaConversions();

    expect($ente->mediaConversions)->toHaveCount(count(config('wm-package.services.image.thumbnail_sizes')));
});

it('salva l ente anche se Elasticsearch non risponde', function () {
    // Come TaxonomyTermableObserver del package: l'indice si riallinea al giro dopo, il
    // salvataggio non deve fallire (review oc:8965).
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('update')->andThrow(new RuntimeException('Elasticsearch non raggiungibile'));
    $engine->shouldReceive('delete')->andThrow(new RuntimeException('Elasticsearch non raggiungibile'));
    app(EngineManager::class)->extend('down', fn () => $engine);
    config(['scout.driver' => 'down']);
    $log = Log::spy();

    $ente = Ente::create(['sardegnasentieri_id' => '13', 'name' => ['it' => 'Oasi Tepilora'], 'properties' => []]);
    $ente->unsearchable();

    expect(Ente::find($ente->id))->not->toBeNull();
    $log->shouldHaveReceived('warning')->atLeast()->once();
});
