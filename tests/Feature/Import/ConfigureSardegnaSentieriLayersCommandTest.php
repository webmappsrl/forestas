<?php

use App\Console\Commands\ConfigureSardegnaSentieriLayersCommand;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\UpdateLayeredFeaturesJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity;

/**
 * Comando di configurazione dei layer di tipologia (oc:8827): lega ogni layer alla sua attività
 * con insert diretti e senza observer (che farebbero ricalcoli sincroni e scritture su S3), poi
 * accoda ricalcolo e reindex. Il setup scrive con `withoutEvents()` per lo stesso motivo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function configLayerAttivita(string $identifier): TaxonomyActivity
{
    return TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::create([
        'identifier' => $identifier,
        'name' => $identifier,
    ]));
}

function configLayerLayer(string $nome): Layer
{
    return Layer::factory()->createQuietly([
        'name' => ['it' => $nome, 'en' => $nome],
        'properties' => [],
        'configuration' => [],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
    ]);
}

/** @return array<string, TaxonomyActivity> identifier => attività */
function configLayerScenario(): array
{
    $attivita = [];
    foreach (ConfigureSardegnaSentieriLayersCommand::LAYER_ACTIVITIES as $nome => $identifier) {
        configLayerLayer($nome);
        $attivita[$identifier] = configLayerAttivita($identifier);
    }

    return $attivita;
}

function configLayerLegami(): array
{
    return DB::table('taxonomy_activityables')
        ->where('taxonomy_activityable_type', (new Layer)->getMorphClass())
        ->orderBy('id')
        ->get(['id', 'taxonomy_activity_id', 'taxonomy_activityable_id'])
        ->map(fn ($r) => (array) $r)
        ->all();
}

it('associa a ogni layer di tipologia solo la sua attività (oc:8827)', function () {
    $attivita = configLayerScenario();
    $estranea = configLayerAttivita('altra-attivita');
    $sentieri = Layer::query()->get()->first(fn ($l) => $l->getTranslation('name', 'it') === 'Sentieri');
    DB::table('taxonomy_activityables')->insert([
        'taxonomy_activity_id' => $estranea->id,
        'taxonomy_activityable_type' => (new Layer)->getMorphClass(),
        'taxonomy_activityable_id' => $sentieri->id,
    ]);

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    foreach (ConfigureSardegnaSentieriLayersCommand::LAYER_ACTIVITIES as $nome => $identifier) {
        $layer = Layer::query()->get()->first(fn ($l) => $l->getTranslation('name', 'it') === $nome);
        expect($layer->taxonomyActivities()->pluck('taxonomy_activities.id')->all())
            ->toBe([$attivita[$identifier]->id]);
    }
});

it('rilanciato non cambia nulla (oc:8827)', function () {
    configLayerScenario();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);
    $prima = configLayerLegami();
    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    expect($prima)->toHaveCount(4)
        ->and(configLayerLegami())->toBe($prima);
});

it('fallisce senza toccare nulla se manca un attività (oc:8827)', function () {
    configLayerScenario();
    TaxonomyActivity::withoutEvents(fn () => TaxonomyActivity::query()
        ->where('identifier', SardegnaSentieriImportService::CAMMINO_ACTIVITY_IDENTIFIER)->delete());

    $this->artisan('sardegnasentieri:configure-layers')
        ->expectsOutputToContain('sardegnasentieri:import --force')
        ->assertExitCode(1);

    expect(configLayerLegami())->toBe([]);
    Bus::assertNothingDispatched();
});

it('fallisce se un layer manca o il nome è ambiguo (oc:8827)', function () {
    configLayerScenario();
    configLayerLayer('Sentieri');

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(1);

    expect(configLayerLegami())->toBe([]);
    Bus::assertNothingDispatched();
});

it('accoda ricalcolo e reindex senza passare dagli observer (oc:8827)', function () {
    // Con gli observer accesi il package scriverebbe le icone: senza fake il test morirebbe
    // sulla configurazione S3, non sull'asserzione dei job.
    Storage::fake('wmfe');
    configLayerScenario();

    $this->artisan('sardegnasentieri:configure-layers')->assertExitCode(0);

    Bus::assertChained([
        RecalculateSardegnaSentieriLayersJob::class,
        RegenerateTaxonomyWhereOutputsJob::class,
    ]);
    Bus::assertDispatchedTimes(RecalculateSardegnaSentieriLayersJob::class, 1);
    Bus::assertDispatchedTimes(RegenerateTaxonomyWhereOutputsJob::class, 0);
    Bus::assertNotDispatched(ImportSardegnaSentieriTrackJob::class);
    // L'observer del package, su un Layer, accoderebbe UpdateLayeredFeaturesJob.
    Bus::assertNotDispatched(UpdateLayeredFeaturesJob::class);
    expect(collect(Bus::dispatched(RecalculateSardegnaSentieriLayersJob::class))->first()->queue)
        ->toBe(ImportSardegnaSentieriTrackJob::QUEUE);
});
