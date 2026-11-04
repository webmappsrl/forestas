<?php

use App\Models\EcTrack;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as PackageEcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Observers\AppObserver;

uses(RefreshDatabase::class);

/** Gli attributi distinti dei campi: ogni tassonomia ne produce due, form e dettaglio. */
function taxonomiesInFields($resource, array $identifiers): array
{
    return array_values(array_unique(array_map(
        fn ($f) => $f->attribute,
        array_filter(
            $resource->makeTermFieldsForTaxonomies($identifiers),
            fn ($f) => is_string($f->attribute)
        )
    )));
}

beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $this->app1 = App::factory()->createQuietly();
});

it('accende le tassonomie dello shard in tutte le sue app, senza spegnerne altre', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect($this->app1->taxonomies()->count())->toBe(11);
});

it('una tassonomia custom nasce spenta e non compare in un\'app che non l\'ha accesa', function () {
    $other = App::factory()->createQuietly();
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    $custom = TaxonomyTerm::create(['identifier' => 'solo-app-uno', 'name' => ['it' => 'Solo app uno']]);

    expect($custom->apps()->count())->toBe(0);

    $custom->apps()->attach($this->app1->id);

    expect(TaxonomyTerm::query()->taxonomies()->forApp($this->app1->id)->pluck('identifier')->all())
        ->toContain('solo-app-uno')
        ->and(TaxonomyTerm::query()->taxonomies()->forApp($other->id)->pluck('identifier')->all())
        ->not->toContain('solo-app-uno');
});

it('una tassonomia di sistema si accende da sé in un\'app creata dopo', function () {
    TaxonomyTerm::create(['identifier' => 'dal-package', 'name' => ['it' => 'Dal package'], 'is_system' => true]);

    // createQuietly salta gli observer (AppObserver::saved scrive la config su storage, che in
    // test non c'è): il gancio della nascita si invoca a mano, è quello che si vuole provare.
    $new = App::factory()->createQuietly();
    (new AppObserver)->created($new);

    expect($new->taxonomies()->pluck('identifier')->all())->toBe(['dal-package']);
});

it('la scheda del sentiero mostra solo le tassonomie accese nella sua app', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $track = PackageEcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);

    // Ogni tassonomia produce due campi: quello del form e quello di sola lettura del dettaglio.
    $allEnabled = count(taxonomiesInFields(new EcTrackResource($track), ['warnings', 'route-type']));

    TaxonomyTerm::where('identifier', 'warnings')->first()->apps()->detach($this->app1->id);

    expect($allEnabled)->toBe(2)
        ->and(taxonomiesInFields(new EcTrackResource($track), ['warnings', 'route-type']))->toHaveCount(1);
});

it('un contenuto senza app vede tutto: in creazione il form non deve nascere vuoto', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    expect(taxonomiesInFields(new EcTrackResource(new EcTrack), ['warnings', 'route-type']))->toHaveCount(2);
});

it('l\'indice Elasticsearch non porta i termini di una tassonomia spenta', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $track = PackageEcTrack::factory()->createQuietly(['app_id' => $this->app1->id]);
    $taxonomy = TaxonomyTerm::where('identifier', 'hiking-difficulty')->first();
    $track->taxonomyTerms()->attach($taxonomy->children->first()->id);

    expect($track->fresh()->toSearchableArray()['terms'])->toHaveKey('hiking-difficulty');

    $taxonomy->apps()->detach($this->app1->id);

    expect($track->fresh()->toSearchableArray()['terms'])->not->toHaveKey('hiking-difficulty');
});

it('si accende e si spegne dalla scheda dell\'app, per tipo di contenuto', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $resource = new Wm\WmPackage\Nova\App($this->app1);

    // Due campi, uno per tipo di contenuto: la stessa tassonomia può valere sulle EC Track di
    // un'app e sugli EC Poi di un'altra (oc:8910).
    $method = new ReflectionMethod($resource, 'taxonomies_tab');
    $method->setAccessible(true);
    $fields = collect($method->invoke($resource));
    expect($fields->pluck('attribute')->all())->toBe(['taxonomies_ec_track', 'taxonomies_ec_poi']);

    // Il form manda gli id scelti: resta accesa solo la tassonomia selezionata.
    $warnings = TaxonomyTerm::where('identifier', 'warnings')->value('id');
    $request = NovaRequest::create('/nova-api/apps/'.$this->app1->id, 'PUT', [
        'taxonomies_ec_track' => json_encode([$warnings]),
    ]);
    ($fields->first()->fill($request, $this->app1))();

    expect($this->app1->fresh()->taxonomies()->pluck('identifier')->all())->toBe(['warnings']);
});
