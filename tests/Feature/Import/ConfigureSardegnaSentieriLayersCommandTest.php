<?php

use App\Console\Commands\ConfigureSardegnaSentieriLayersCommand;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\UpdateLayeredFeaturesJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Comando di configurazione dei layer di tipologia (oc:8827, rifatto sui termini in oc:8910):
 * lega ogni layer ai termini che lo definiscono e accoda ricalcolo e reindex. Un layer assente
 * si salta con un avvertimento, perché gli ambienti non hanno tutti e quattro i layer.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    // I termini li crea il comando delle tassonomie: è la stessa sequenza dell'import.
    Artisan::call('sardegnasentieri:configure-taxonomy-terms');
});

function makeTypeLayer(string $name): Layer
{
    return Layer::factory()->createQuietly([
        'name' => ['it' => $name, 'en' => $name],
        'properties' => [],
        'configuration' => [],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
    ]);
}

function makeAllTypeLayers(): void
{
    foreach (array_keys(ConfigureSardegnaSentieriLayersCommand::LAYER_TERMS) as $name) {
        makeTypeLayer($name);
    }
}

function layerByName(string $name): Layer
{
    return Layer::query()->get()->first(fn ($l) => $l->getTranslation('name', 'it') === $name);
}

function layerTermRows(): array
{
    return DB::table('taxonomy_termables')
        ->where('termable_type', (new Layer)->getMorphClass())
        ->orderBy('id')
        ->get(['id', 'taxonomy_term_id', 'termable_id'])
        ->map(fn ($r) => (array) $r)
        ->all();
}

it('associa a ogni layer di tipologia solo i suoi termini (oc:8910)', function () {
    makeAllTypeLayers();
    $foreign = TaxonomyTerm::query()->where('identifier', 'route-type-walk')->firstOrFail();
    $trailsLayer = layerByName('Sentieri');
    $trailsLayer->taxonomyTerms()->attach($foreign->id);

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    foreach (ConfigureSardegnaSentieriLayersCommand::LAYER_TERMS as $name => $identifiers) {
        $expected = TaxonomyTerm::query()->whereIn('identifier', $identifiers)->pluck('id')->sort()->values()->all();
        expect(layerByName($name)->taxonomyTerms()->pluck('taxonomy_terms.id')->sort()->values()->all())
            ->toBe($expected);
    }
});

it('il layer dei percorsi accessibili prende tutta la scala di accessibilità (oc:8910)', function () {
    makeAllTypeLayers();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    expect(layerByName('Sentieri Accessibili')->taxonomyTerms()->pluck('identifier')->sort()->values()->all())
        ->toBe(['inclusivity-difficulty-ae', 'inclusivity-difficulty-aee', 'inclusivity-difficulty-at']);
});

it('scrive la tassonomia sulla relazione, cosi il filtro per famiglia funziona (oc:8910)', function () {
    makeAllTypeLayers();
    $routeType = TaxonomyTerm::query()->where('identifier', 'route-type')->firstOrFail();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    expect(DB::table('taxonomy_termables')
        ->where('termable_type', (new Layer)->getMorphClass())
        ->where('termable_id', layerByName('Sentieri')->id)
        ->value('parent_id'))->toBe($routeType->id);
});

it('rilanciato non cambia nulla (oc:8827)', function () {
    makeAllTypeLayers();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);
    $before = layerTermRows();
    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    expect($before)->toHaveCount(6)
        ->and(layerTermRows())->toBe($before);
});

it('salta i layer che su questo ambiente non esistono, senza fallire (oc:8910)', function () {
    // In locale ci sono due layer dei quattro: far fallire tutto lascerebbe senza filtri
    // anche quelli presenti.
    makeTypeLayer('Sentieri');
    makeTypeLayer('Cammini di Sardegna');

    $this->artisan('sardegnasentieri:configure-layers')
        ->expectsOutputToContain('Layer saltati')
        ->assertExitCode(0);

    expect(layerTermRows())->toHaveCount(2);
});

it('fallisce senza toccare nulla se mancano i termini (oc:8910)', function () {
    makeAllTypeLayers();
    TaxonomyTerm::query()->where('identifier', 'route-type-trail')->delete();

    $this->artisan('sardegnasentieri:configure-layers')
        ->expectsOutputToContain('configure-taxonomy-terms')
        ->assertExitCode(1);

    expect(layerTermRows())->toBe([]);
    Bus::assertNothingDispatched();
});

it('fallisce se nessun layer di tipologia esiste (oc:8910)', function () {
    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(1);

    expect(layerTermRows())->toBe([]);
    Bus::assertNothingDispatched();
});

it('con un nome ambiguo salta quel layer e scrive gli altri (oc:8910)', function () {
    makeAllTypeLayers();
    makeTypeLayer('Sentieri');

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    expect(layerByName('Cammini di Sardegna')->taxonomyTerms()->count())->toBe(1)
        ->and(layerTermRows())->toHaveCount(5);
});

it('accoda ricalcolo e reindex, e con --no-recalc nemmeno quelli (oc:8910)', function () {
    // Con gli observer accesi il package scriverebbe le icone: senza fake il test morirebbe
    // sulla configurazione S3, non sull'asserzione dei job.
    Storage::fake('wmfe');
    makeAllTypeLayers();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    Bus::assertChained([
        RecalculateSardegnaSentieriLayersJob::class,
        RegenerateTaxonomyWhereOutputsJob::class,
    ]);
    Bus::assertDispatchedTimes(RecalculateSardegnaSentieriLayersJob::class, 1);
    Bus::assertNotDispatched(ImportSardegnaSentieriTrackJob::class);
    // L'observer del package, su un Layer, accoderebbe UpdateLayeredFeaturesJob.
    Bus::assertNotDispatched(UpdateLayeredFeaturesJob::class);
    expect(collect(Bus::dispatched(RecalculateSardegnaSentieriLayersJob::class))->first()->queue)
        ->toBe(ImportSardegnaSentieriTrackJob::QUEUE);
});

it('con --no-recalc non accoda nulla (oc:8910)', function () {
    Storage::fake('wmfe');
    makeAllTypeLayers();

    $this->artisan('sardegnasentieri:configure-layers', ['--no-recalc' => true])->assertExitCode(0);

    Bus::assertNothingDispatched();
    expect(layerTermRows())->toHaveCount(6);
});
