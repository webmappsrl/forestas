<?php

use App\Jobs\Import\ResolveSardegnaSentieriRelationsJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;

/**
 * Risoluzione dei collegamenti percorso-POI e POI-POI a fine import (oc:8932).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function relTrack(string $nid, array $pois = [], string $wkt = '9 40.5 0, 9.1 40.5 0'): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia', 'en' => 'Traccia'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [
            'sardegnasentieri_id' => $nid,
            'forestas' => ['source_relations' => ['pois' => $pois, 'tracks' => []]],
        ],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$wkt}))', 4326)"),
    ]));
}

function relPoi(string $nid, array $tracks = [], array $pois = [], string $point = '9.05 40.5'): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'Poi', 'en' => 'Poi'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [
            'out_source_feature_id' => $nid,
            'forestas' => ['source_relations' => ['pois' => $pois, 'tracks' => $tracks]],
        ],
        'geometry' => DB::raw("ST_GeomFromText('POINT({$point})', 4326)"),
    ]));
}

function resolveRelations(): void
{
    (new ResolveSardegnaSentieriRelationsJob)->handle();
}

it('unisce i collegamenti scritti dal percorso e quelli scritti dal POI', function () {
    $a = relPoi('101');
    $b = relPoi('102', ['1']);
    $t = relTrack('1', ['101']);

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

it('non perde i collegamenti di un contenuto importato prima di quello citato', function () {
    // Il POI cita un percorso che nel database c'e' gia'; il percorso non cita il POI.
    $t = relTrack('1');
    $p = relPoi('101', ['1']);
    // E un secondo POI cita il primo, che a sua volta esiste.
    $q = relPoi('102', [], ['101']);

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$p->id]);
    expect($q->relatedPois()->pluck('ec_pois.id')->all())->toBe([$p->id]);
});

it('cerca nel database e non fra i contenuti del batch', function () {
    // Import orario: solo il percorso e' stato reimportato, il POI e' di un giro precedente.
    $p = relPoi('101');
    $t = relTrack('1', ['101']);

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$p->id]);
});

it('ignora un nodo citato che non è mai stato importato', function () {
    $p = relPoi('101');
    $t = relTrack('1', ['999', '101']);
    $q = relPoi('102', ['888'], ['777']);

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$p->id]);
    expect($q->relatedPois()->count())->toBe(0);
});

it('scarta le coppie oltre i 10 km misurati sulla geometria', function () {
    // Percorso lungo ~40 km: un POI al suo estremo e' legittimo, anche se lontano dal centro.
    $t = relTrack('1', ['101', '102'], '9 40.5 0, 9.5 40.5 0');
    $near = relPoi('101', [], [], '9.5 40.5');
    // ~22 km a nord della geometria
    relPoi('102', [], [], '9.25 40.7');

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$near->id]);
});

it('rimuove un collegamento sparito alla fonte', function () {
    $a = relPoi('101');
    $b = relPoi('102');
    $t = relTrack('1', ['101', '102']);
    resolveRelations();
    expect($t->ecPois()->count())->toBe(2);

    $props = $t->properties;
    $props['forestas']['source_relations']['pois'] = ['101'];
    EcTrack::withoutEvents(fn () => $t->forceFill(['properties' => $props])->saveQuietly());
    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('rende reciproci i collegamenti fra POI scritti in un verso solo', function () {
    $a = relPoi('101', [], ['102']);
    $b = relPoi('102');

    resolveRelations();

    expect($a->relatedPois()->pluck('ec_pois.id')->all())->toBe([$b->id]);
    expect($b->relatedPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('scarta un POI che cita se stesso e tiene l altro collegamento valido', function () {
    // Su Drupal succede davvero (nid 2516, 2701, 2901): un collegamento a se stesso non va scritto.
    $a = relPoi('101', [], ['101', '102']);
    $b = relPoi('102');

    resolveRelations();

    expect($a->relatedPois()->pluck('ec_pois.id')->all())->toBe([$b->id]);
    expect($b->relatedPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('non svuota il pivot di un contenuto non rimportato', function () {
    // Senza `source_relations` il contenuto non e' stato rimportato in questo giro: i suoi
    // collegamenti, compresi quelli aggiunti a mano in Nova, restano.
    $a = relPoi('101');
    $b = relPoi('102');
    $t = relTrack('1');
    $t->forceFill(['properties' => ['sardegnasentieri_id' => '1']]);
    EcTrack::withoutEvents(fn () => $t->saveQuietly());
    $b->forceFill(['properties' => ['out_source_feature_id' => '102']]);
    EcPoi::withoutEvents(fn () => $b->saveQuietly());
    $t->ecPois()->attach($a->id);
    $b->relatedPois()->attach($a->id);

    resolveRelations();

    expect($t->ecPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
    expect($b->relatedPois()->pluck('ec_pois.id')->all())->toBe([$a->id]);
});

it('svuota il pivot di un contenuto rimportato senza piu collegamenti alla fonte', function () {
    $a = relPoi('101');
    $b = relPoi('102');
    $t = relTrack('1', []);
    $t->ecPois()->attach($a->id);
    $b->relatedPois()->attach($a->id);

    resolveRelations();

    expect($t->ecPois()->count())->toBe(0);
    expect($b->relatedPois()->count())->toBe(0);
});

it('non parte in parallelo a un altro giro dei collegamenti', function () {
    $job = new ResolveSardegnaSentieriRelationsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('sardegnasentieri-resolve-relations')
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBeGreaterThan($job->timeout);
});
