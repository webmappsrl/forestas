<?php

use App\Models\User;
use App\Nova\TaxonomyTerm as TaxonomyTermResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\Filters\TaxonomyTermKindFilter;
use Wm\WmPackage\Nova\Filters\TermsByTaxonomyFilter;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Support\TaxonomyTermLabel;

uses(RefreshDatabase::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    $this->taxonomy = TaxonomyTerm::create([
        'identifier' => 'hiking-difficulty',
        'name' => ['it' => 'Difficoltà escursionistica'],
    ]);
    $this->term = TaxonomyTerm::create([
        'parent_id' => $this->taxonomy->id,
        'identifier' => 'hiking-difficulty-t',
        'name' => ['it' => 'Turistico'],
    ]);
});

it('mette l\'etichetta italiana e l\'identifier nel titolo della riga', function () {
    // Il nome è salvato solo in italiano: in un\'interfaccia inglese il fallback deve reggere.
    app()->setLocale('en');

    expect(TaxonomyTermLabel::for($this->term))->toBe('Turistico');
});

it('separa tassonomie e termini con il filtro Tipo', function () {
    $filter = new TaxonomyTermKindFilter;
    $request = NovaRequest::create('/nova-api/taxonomy-terms', 'GET');

    $taxonomies = $filter->apply($request, TaxonomyTerm::query(), 'taxonomies')->pluck('identifier');
    $terms = $filter->apply($request, TaxonomyTerm::query(), 'terms')->pluck('identifier');

    expect($taxonomies->all())->toBe(['hiking-difficulty'])
        ->and($terms->all())->toBe(['hiking-difficulty-t']);
});

it('elenca le tassonomie nel filtro Tipo e ne mostra solo i termini', function () {
    $filter = new TermsByTaxonomyFilter;
    $request = NovaRequest::create('/nova-api/taxonomy-terms', 'GET');

    expect($filter->options($request))
        ->toBe(['Difficoltà escursionistica' => $this->taxonomy->id]);

    expect($filter->apply($request, TaxonomyTerm::query(), $this->taxonomy->id)->pluck('identifier')->all())
        ->toBe(['hiking-difficulty-t']);
});

it('espone i due filtri sulla Resource', function () {
    $filters = (new TaxonomyTermResource(new TaxonomyTerm))
        ->filters(NovaRequest::create('/nova-api/taxonomy-terms', 'GET'));

    expect(array_map(fn ($f) => get_class($f), $filters))
        ->toBe([TaxonomyTermKindFilter::class, TermsByTaxonomyFilter::class]);
});

function taxonomyAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Administrator');

    return $user;
}

it('l\'elenco Nova mostra il nome e applica i filtri', function () {
    $index = $this->actingAs(taxonomyAdmin())
        ->getJson('/nova-api/taxonomy-terms')
        ->assertOk();

    // Il titolo della riga è quello che Nova mostra nelle relazioni e nella ricerca.
    expect(collect($index->json('resources'))->pluck('title')->all())
        ->toContain('Difficoltà escursionistica');

    $filtered = $this->actingAs(taxonomyAdmin())
        ->getJson('/nova-api/taxonomy-terms?filters='.base64_encode(json_encode([
            [TaxonomyTermKindFilter::class => 'taxonomies'],
        ])))
        ->assertOk();

    expect(collect($filtered->json('resources'))->count())->toBe(1);
});

it('sul dettaglio di un termine elenca i contenuti collegati, non su una tassonomia', function () {
    $track = EcTrack::factory()->createQuietly();
    $track->taxonomyTerms()->attach($this->term->id);

    $detail = $this->actingAs(taxonomyAdmin())
        ->getJson("/nova-api/taxonomy-terms/{$this->term->id}")
        ->assertOk();

    $relations = collect($detail->json('resource.fields'))
        ->pluck('attribute')
        ->intersect(['ecTracks', 'ecPois'])
        ->values();

    expect($relations->all())->toBe(['ecTracks', 'ecPois']);

    // La lista dei contenuti di una tassonomia sarebbe vuota per costruzione: non va mostrata.
    $taxonomy = $this->actingAs(taxonomyAdmin())
        ->getJson("/nova-api/taxonomy-terms/{$this->taxonomy->id}")
        ->assertOk();

    expect(collect($taxonomy->json('resource.fields'))->pluck('attribute')->intersect(['ecTracks', 'ecPois'])->all())
        ->toBe([]);
});
