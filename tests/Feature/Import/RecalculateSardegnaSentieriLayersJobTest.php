<?php

use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Jobs\Pbf\GenerateOptimizedPBFChainJob;
use Wm\WmPackage\Jobs\UpdateLayerGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\PBFGeneratorService;

/**
 * Ricalcolo dei layer automatici a fine import (oc:8827): i layer di tipologia filtrano le tracce
 * per attività, la tabella `layerables` va riallineata senza far scattare gli observer riga per
 * riga, e `properties.layers` deve essere aggiornato prima che il reindex della catena lo legga.
 *
 * Le righe di setup si scrivono con insert diretti: gli observer delle tassonomie accoderebbero
 * `SyncAutoLayerAfterTrackTaxonomyChangeJob`, che uno dei test verifica non venga mai accodato.
 * I tipi morph si leggono da `getMorphClass()`: i model del package si salvano come
 * `App\Models\<Nome>`, non con il nome della classe del package.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([
        GenerateOptimizedPBFChainJob::class,
        UpdateLayerGeometryJob::class,
        SyncAutoLayerAfterTrackTaxonomyChangeJob::class,
    ]);
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function recalcActivity(string $identifier): TaxonomyActivity
{
    return TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create([
        'identifier' => $identifier,
        'name' => $identifier,
    ]));
}

function recalcTrack(?TaxonomyActivity $activity = null): EcTrack
{
    $track = EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia', 'en' => 'Traccia'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        // properties sempre oggetto, come nei dati veri: un array JSON vuoto fa fallire jsonb_set().
        'properties' => ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((9 40.5 0, 9.1 40.5 0))', 4326)"),
    ]));

    if ($activity !== null) {
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $activity->id,
            'taxonomy_activityable_type' => (new EcTrack)->getMorphClass(),
            'taxonomy_activityable_id' => $track->id,
        ]);
    }

    return $track;
}

function recalcPoi(?TaxonomyActivity $activity = null, float $lon = 9.5): EcPoi
{
    $poi = EcPoi::withoutEvents(fn () => EcPoi::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('POINT Z({$lon} 40.5 0)', 4326)"),
    ]));

    if ($activity !== null) {
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $activity->id,
            'taxonomy_activityable_type' => (new EcPoi)->getMorphClass(),
            'taxonomy_activityable_id' => $poi->id,
        ]);
    }

    return $poi;
}

function recalcLayer(?TaxonomyActivity $activity = null): Layer
{
    $layer = Layer::factory()->createQuietly([
        'name' => ['it' => 'Sentieri', 'en' => 'Sentieri'],
        'properties' => [],
        'configuration' => [],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
    ]);

    if ($activity !== null) {
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $activity->id,
            'taxonomy_activityable_type' => (new Layer)->getMorphClass(),
            'taxonomy_activityable_id' => $layer->id,
        ]);
    }

    return $layer;
}

function recalcRow(Layer $layer, string $kind, int $id): void
{
    DB::table('layerables')->insert([
        'layer_id' => $layer->id,
        'layerable_type' => $kind,
        'layerable_id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function recalcTracks(Layer $layer): array
{
    return DB::table('layerables')
        ->where('layer_id', $layer->id)
        ->where('layerable_type', EcTrack::class)
        ->orderBy('layerable_id')
        ->pluck('layerable_id')
        ->all();
}

it('assegna a un layer le tracce con la sua attività e toglie le altre (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $itinerary = recalcActivity('sardegnasentieri:type:itinerario');
    $layer = recalcLayer($track);
    $first = recalcTrack($track);
    $second = recalcTrack($track);
    $other = recalcTrack($itinerary);
    recalcRow($layer, EcTrack::class, $other->id);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(recalcTracks($layer))->toBe([$first->id, $second->id]);
});

it('aggiorna properties.layers delle tracce prima di finire (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $itinerary = recalcActivity('sardegnasentieri:type:itinerario');
    $layer = recalcLayer($track);
    $first = recalcTrack($track);
    $second = recalcTrack($track);
    $other = recalcTrack($itinerary);
    recalcRow($layer, EcTrack::class, $other->id);
    // La traccia che non appartiene al layer lo porta già in properties.layers: va tolto.
    $other->properties = ['ref' => 'TEST', 'layers' => [$layer->id]];
    $other->saveQuietly();

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect($first->fresh()->properties['layers'] ?? [])->toContain($layer->id)
        ->and($second->fresh()->properties['layers'] ?? [])->toContain($layer->id)
        ->and($other->fresh()->properties['layers'] ?? [])->not->toContain($layer->id);
});

it('non svuota un layer automatico senza attività né aree (oc:8827)', function () {
    $layer = recalcLayer();
    $track = recalcTrack();
    recalcRow($layer, EcTrack::class, $track->id);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(recalcTracks($layer))->toBe([$track->id]);
});

it('lascia vuoti i POI dei layer di tipologia (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $layer = recalcLayer($track);
    recalcTrack($track);
    // Un POI con la stessa attività del layer: assignPoisByTaxonomy() lo aggancerebbe.
    $withActivity = recalcPoi($track);
    $old = recalcPoi();
    $old->properties = ['ref' => 'TEST', 'layers' => [$layer->id]];
    $old->saveQuietly();
    recalcRow($layer, (new EcPoi)->getMorphClass(), $old->id);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(DB::table('layerables')->where('layer_id', $layer->id)->where('layerable_type', (new EcPoi)->getMorphClass())->count())
        ->toBe(0)
        ->and($withActivity->fresh()->properties['layers'] ?? [])->not->toContain($layer->id)
        ->and($old->fresh()->properties['layers'] ?? [])->not->toContain($layer->id);
});

it('assegna i POI ai layer delle aree catastali (oc:8827)', function () {
    $area = TaxonomyWhere::withoutEvents(fn () => TaxonomyWhere::create([
        'name' => ['it' => 'Area B', 'en' => 'Area B'],
        'properties' => [],
    ]));
    DB::update(
        'UPDATE taxonomy_wheres SET geometry = ST_GeomFromText(?, 4326)::geography WHERE id = ?',
        ['POLYGON((9 40, 9 41, 10 41, 10 40, 9 40))', $area->id]
    );
    $layer = recalcLayer();
    Layer::withoutEvents(fn () => $layer->taxonomyWheres()->attach($area->id));
    recalcTrack();
    $inside = recalcPoi(null, 9.5);
    recalcPoi(null, 20);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(DB::table('layerables')->where('layer_id', $layer->id)->where('layerable_type', (new EcPoi)->getMorphClass())->pluck('layerable_id')->all())
        ->toBe([$inside->id]);
});

it('accoda PBF e geometria una volta per layer, non per riga (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    recalcLayer($track);
    recalcTrack($track);
    recalcTrack($track);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    Bus::assertDispatchedTimes(GenerateOptimizedPBFChainJob::class, 1);
    Bus::assertDispatchedTimes(UpdateLayerGeometryJob::class, 1);
    Bus::assertNotDispatched(SyncAutoLayerAfterTrackTaxonomyChangeJob::class);
});

it('rilanciato non cambia nulla (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $layer = recalcLayer($track);
    recalcTrack($track);
    recalcTrack($track);

    // Si confrontano id, tipo e feature, non i timestamp: sync() del package riscrive
    // created_at/updated_at delle righe esistenti a ogni giro (costo accettato, vedi il job).
    RecalculateSardegnaSentieriLayersJob::dispatchSync();
    $before = DB::table('layerables')->where('layer_id', $layer->id)->orderBy('id')->get(['id', 'layerable_type', 'layerable_id'])->all();

    RecalculateSardegnaSentieriLayersJob::dispatchSync();
    $after = DB::table('layerables')->where('layer_id', $layer->id)->orderBy('id')->get(['id', 'layerable_type', 'layerable_id'])->all();

    expect($after)->toEqual($before)->and($before)->toHaveCount(2);
});

it('rilanciato senza cambiamenti non riaccoda PBF né geometria (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    recalcLayer($track);
    recalcLayer();
    recalcTrack($track);
    recalcTrack($track);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();
    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    Bus::assertDispatchedTimes(GenerateOptimizedPBFChainJob::class, 1);
    Bus::assertDispatchedTimes(UpdateLayerGeometryJob::class, 1);
});

it('ripara properties.layers anche se il pivot è già corretto (oc:8827)', function () {
    // Il tentativo precedente è morto dopo aver scritto il pivot: le righe sono giuste,
    // properties.layers no. Il retry deve ripararlo e accodare geometria e PBF.
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $layer = recalcLayer($track);
    $track = recalcTrack($track);
    recalcRow($layer, EcTrack::class, $track->id);
    $poi = recalcPoi();
    $poi->properties = ['ref' => 'TEST', 'layers' => [$layer->id]];
    $poi->saveQuietly();

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect($track->fresh()->properties['layers'] ?? [])->toContain($layer->id)
        ->and($poi->fresh()->properties['layers'] ?? [])->not->toContain($layer->id);
    Bus::assertDispatchedTimes(GenerateOptimizedPBFChainJob::class, 1);
    Bus::assertDispatchedTimes(UpdateLayerGeometryJob::class, 1);

    // Riparato, un secondo giro non ha più nulla da fare.
    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    Bus::assertDispatchedTimes(GenerateOptimizedPBFChainJob::class, 1);
    Bus::assertDispatchedTimes(UpdateLayerGeometryJob::class, 1);
});

it('un layer i cui effetti falliscono non ferma gli altri (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $itinerary = recalcActivity('sardegnasentieri:type:itinerario');
    $broken = recalcLayer($track);
    $healthy = recalcLayer($itinerary);
    recalcTrack($track);
    $track = recalcTrack($itinerary);

    // Come generateWholeAppPbfsOptimized() su un'app senza tracce: la rigenerazione lancia.
    $this->mock(PBFGeneratorService::class)
        ->shouldReceive('regeneratePbfsForLayer')
        ->andReturnUsing(function (Layer $layer) use ($broken) {
            if ($layer->id === $broken->id) {
                throw new RuntimeException('PBF rotti');
            }
        });

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(recalcTracks($healthy))->toBe([$track->id])
        ->and($track->fresh()->properties['layers'] ?? [])->toContain($healthy->id);
});

it('non svuota i POI di un layer di tipologia con poi_mode manuale (oc:8827)', function () {
    $track = recalcActivity('sardegnasentieri:type:sentiero');
    $layer = recalcLayer($track);
    $layer->configuration = ['poi_mode' => 'manual'];
    $layer->saveQuietly();
    recalcTrack($track);
    $poi = recalcPoi();
    $poi->properties = ['ref' => 'TEST', 'layers' => [$layer->id]];
    $poi->saveQuietly();
    recalcRow($layer, (new EcPoi)->getMorphClass(), $poi->id);

    RecalculateSardegnaSentieriLayersJob::dispatchSync();

    expect(DB::table('layerables')->where('layer_id', $layer->id)->where('layerable_type', (new EcPoi)->getMorphClass())->pluck('layerable_id')->all())
        ->toBe([$poi->id])
        ->and($poi->fresh()->properties['layers'] ?? [])->toContain($layer->id);
});

it('non parte in parallelo a un altro ricalcolo (oc:8827)', function () {
    $middleware = (new RecalculateSardegnaSentieriLayersJob)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('sardegnasentieri-recalculate-layers')
        ->and($middleware[0]->releaseAfter)->toBeNull()
        ->and($middleware[0]->expiresAfter)->toBeGreaterThan((new RecalculateSardegnaSentieriLayersJob)->timeout);
});
