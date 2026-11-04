<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Jobs\Layer\SyncAutoLayerAfterTrackTaxonomyChangeJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('wmfe');
    $this->app1 = App::factory()->createQuietly();
    $this->taxonomy = TaxonomyTerm::create(['identifier' => 'route-type', 'name' => ['it' => 'Tipo percorso']]);
    $this->trail = TaxonomyTerm::create(['identifier' => 'route-type-trail', 'name' => ['it' => 'Sentiero'], 'parent_id' => $this->taxonomy->id]);
    $this->walk = TaxonomyTerm::create(['identifier' => 'route-type-walk', 'name' => ['it' => 'Cammino'], 'parent_id' => $this->taxonomy->id]);
    // I campi mostrano solo le tassonomie accese nell'app del layer.
    $this->taxonomy->apps()->attach($this->app1->id);
});

it('riempie un layer con le tracce che hanno il termine, come fa con le attività', function () {
    $withTerm = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $withOther = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $withTerm->taxonomyTerms()->attach($this->trail->id);
    $withOther->taxonomyTerms()->attach($this->walk->id);

    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach($this->trail->id);

    Layerable::withoutEvents(fn () => app(LayerService::class)->assignTracksByTaxonomy($layer->fresh()));

    expect($layer->fresh()->ecTracks->pluck('id')->all())->toBe([$withTerm->id]);
});

it('svuota il layer che non ha nessun filtro, neanche un termine', function () {
    $track = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    Layerable::withoutEvents(fn () => $layer->ecTracks()->attach($track->id));

    Layerable::withoutEvents(fn () => app(LayerService::class)->assignTracksByTaxonomy($layer->fresh()));

    expect($layer->fresh()->ecTracks)->toBeEmpty();
});

it('somma i criteri: con attività e termine insieme tiene solo chi ha entrambi', function () {
    $activity = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo']]);
    $both = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $onlyTerm = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $both->taxonomyTerms()->attach($this->trail->id);
    TaxonomyActivityable::withoutEvents(fn () => $both->taxonomyActivities()->attach($activity->id));
    $onlyTerm->taxonomyTerms()->attach($this->trail->id);

    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach($this->trail->id);
    TaxonomyActivityable::withoutEvents(fn () => $layer->taxonomyActivities()->attach($activity->id));

    Layerable::withoutEvents(fn () => app(LayerService::class)->assignTracksByTaxonomy($layer->fresh()));

    expect($layer->fresh()->ecTracks->pluck('id')->all())->toBe([$both->id]);
});

it('dentro una tassonomia i termini sono in OR', function () {
    $trail = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $walk = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $trail->taxonomyTerms()->attach($this->trail->id);
    $walk->taxonomyTerms()->attach($this->walk->id);

    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach([$this->trail->id, $this->walk->id]);

    Layerable::withoutEvents(fn () => app(LayerService::class)->assignTracksByTaxonomy($layer->fresh()));

    // Sentiero «oppure» Cammino: entrano tutte e due.
    expect($layer->fresh()->ecTracks->pluck('id')->sort()->values()->all())
        ->toBe(collect([$trail->id, $walk->id])->sort()->values()->all());
});

it('fra tassonomie diverse i termini sono in AND', function () {
    $difficulty = TaxonomyTerm::create(['identifier' => 'mtb-difficulty', 'name' => ['it' => 'Difficoltà MTB']]);
    $bc = TaxonomyTerm::create(['identifier' => 'mtb-difficulty-bc', 'name' => ['it' => 'BC'], 'parent_id' => $difficulty->id]);

    $both = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $onlyType = EcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $both->taxonomyTerms()->attach([$this->trail->id, $bc->id]);
    $onlyType->taxonomyTerms()->attach($this->trail->id);

    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach([$this->trail->id, $bc->id]);

    Layerable::withoutEvents(fn () => app(LayerService::class)->assignTracksByTaxonomy($layer->fresh()));

    // Tipo percorso «e» difficoltà: entra solo chi ha entrambi.
    expect($layer->fresh()->ecTracks->pluck('id')->all())->toBe([$both->id]);
});

it('cambiare un filtro del layer accoda il ricalcolo: tracce, mappa e indice', function () {
    Bus::fake([SyncAutoLayerAfterTrackTaxonomyChangeJob::class]);
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);

    $resource = new Wm\WmPackage\Nova\Layer($layer);
    $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'PUT', [
        'taxonomy_route_type' => (string) $this->trail->id,
    ]);
    $field = campoFiltroDelLayer($resource, 'taxonomy_route_type');
    ($field->fill($request, $layer))();

    expect($layer->fresh()->taxonomyTerms->pluck('id')->all())->toBe([$this->trail->id]);
    Bus::assertDispatched(SyncAutoLayerAfterTrackTaxonomyChangeJob::class);
});

it('non accoda niente se la scelta non è cambiata', function () {
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);
    $layer->taxonomyTerms()->attach($this->trail->id);
    Bus::fake([SyncAutoLayerAfterTrackTaxonomyChangeJob::class]);

    $resource = new Wm\WmPackage\Nova\Layer($layer->fresh());
    $request = NovaRequest::create('/nova-api/layers/'.$layer->id, 'PUT', [
        'taxonomy_route_type' => (string) $this->trail->id,
    ]);
    $field = campoFiltroDelLayer($resource, 'taxonomy_route_type');
    ($field->fill($request, $layer->fresh()))();

    Bus::assertNotDispatched(SyncAutoLayerAfterTrackTaxonomyChangeJob::class);
});

/** Il campo del form con quell'attributo, dentro la sezione dei filtri del layer. */
function campoFiltroDelLayer($resource, string $attribute)
{
    foreach ($resource->fields(NovaRequest::create('/nova-api/layers', 'GET')) as $f) {
        if (! $f instanceof TabsGroup) {
            continue;
        }
        foreach ($f->tabs as $t) {
            foreach ($t->data as $c) {
                if (($c->attribute ?? '') === $attribute && ($c->showOnUpdate ?? false)) {
                    return $c;
                }
            }
        }
    }

    return null;
}
