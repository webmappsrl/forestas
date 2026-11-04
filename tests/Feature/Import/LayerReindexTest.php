<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('wmfe');
    $this->app1 = App::factory()->createQuietly();
    $taxonomy = TaxonomyTerm::create(['identifier' => 'route-type', 'name' => ['it' => 'Tipo percorso']]);
    $this->trail = TaxonomyTerm::create(['identifier' => 'route-type-trail', 'name' => ['it' => 'Sentiero'], 'parent_id' => $taxonomy->id]);
    $this->walk = TaxonomyTerm::create(['identifier' => 'route-type-walk', 'name' => ['it' => 'Cammino'], 'parent_id' => $taxonomy->id]);
});

it('reindicizza anche le tracce che il nuovo filtro lascia fuori', function () {
    $staysOut = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $comesIn = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $staysOut->taxonomyTerms()->attach($this->walk->id);
    $comesIn->taxonomyTerms()->attach($this->trail->id);

    // Il layer prima prendeva i cammini, e la traccia era dentro con il suo properties.layers.
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach($this->walk->id);
    // Il job rigenera i PBF, che in test toccano Redis: la parte che interessa è il pivot e
    // properties.layers, quindi si gira senza gli eventi del pivot e senza PBF.
    eseguiSenzaPbf($layer->id);
    expect($staysOut->fresh()->properties['layers'] ?? [])->toBe([$layer->id]);

    // Ora il filtro cambia: quella traccia esce.
    $layer->taxonomyTerms()->sync([$this->trail->id]);
    eseguiSenzaPbf($layer->id);

    expect($layer->fresh()->ecTracks->pluck('id')->all())->toBe([$comesIn->id])
        // Il punto: chi esce non deve restare con il layer addosso, o l'indice lo mostra lì.
        ->and($staysOut->fresh()->properties['layers'] ?? [])->toBe([])
        ->and($comesIn->fresh()->properties['layers'] ?? [])->toBe([$layer->id]);
});

/** Il job con il generatore di PBF spento: in test scriverebbe su Redis e su disco. */
function eseguiSenzaPbf(int $layerId): void
{
    $pbf = Mockery::mock(PBFGeneratorService::class);
    $pbf->shouldReceive('regeneratePbfsForLayer')->andReturnNull();

    // L'observer del pivot rigenera anche lui i PBF: in produzione gira, qui si spegne.
    Layerable::withoutEvents(
        fn () => (new SyncAutoLayerAfterTrackTaxonomyChangeJob($layerId))
            ->handle(app(LayerService::class), $pbf)
    );
}
