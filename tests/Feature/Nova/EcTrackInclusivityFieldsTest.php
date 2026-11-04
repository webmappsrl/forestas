<?php

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Nova\EcPoi as EcPoiResource;
use App\Nova\EcTrack as EcTrackResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\TaxonomyTerm;

uses(RefreshDatabase::class);

/** @return array<int, Tab> */
function novaTabs($resource): array
{
    $tabs = [];
    foreach ($resource->fields(NovaRequest::create('/nova-api/ec-tracks', 'GET')) as $field) {
        if ($field instanceof TabsGroup) {
            foreach ($field->tabs as $tab) {
                $tabs[] = $tab;
            }
        }
    }

    return $tabs;
}

function novaTabNames($resource): array
{
    return array_map(fn (Tab $t) => (string) $t->name, novaTabs($resource));
}

/**
 * Il campo del form con quell'attributo. Ogni tassonomia ne produce due: quello con cui si
 * sceglie e quello di sola lettura del dettaglio, che ha lo stesso attributo.
 */
function novaTabField($resource, string $tab, string $attribute, bool $soloForm = true)
{
    foreach (novaTabs($resource) as $t) {
        if (((string) $t->name) !== $tab) {
            continue;
        }
        foreach ($t->data as $field) {
            if ($field->attribute !== $attribute) {
                continue;
            }
            if ($soloForm && ! ($field->showOnUpdate ?? false)) {
                continue;
            }

            return $field;
        }
    }

    return null;
}

beforeEach(fn () => $this->artisan('sardegnasentieri:configure-taxonomy-terms'));

it('mostra il blocco Inclusivity sulle tracce e non sui POI', function () {
    $trackTabs = novaTabNames(new EcTrackResource(new EcTrack));
    $poiTabs = novaTabNames(new EcPoiResource(new EcPoi));

    expect($trackTabs)->toContain('Inclusivity');
    expect($trackTabs)->not->toContain('Accessibility');
    expect($poiTabs)->not->toContain('Inclusivity');
    expect($poiTabs)->not->toContain('Accessibility');
});

it('limita i due testi a 30 caratteri', function () {
    foreach (['inclusivity_independent_use', 'inclusivity_assistance'] as $key) {
        $field = novaTabField(new EcTrackResource(new EcTrack), __('Inclusivity'), 'properties->forestas->'.$key);
        expect($field)->not->toBeNull()
            ->and(collect($field->rules)->flatten()->all())->toContain('max:30');
    }
});

it('mette un tassonomia creata da Nova nel tab Classifications senza toccare codice', function () {
    TaxonomyTerm::create(['identifier' => 'tassonomia-nuova', 'name' => ['it' => 'Nuova']]);

    expect(novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_tassonomia_nuova'))->not->toBeNull()
        ->and(novaTabField(new EcTrackResource(new EcTrack), __('Inclusivity'), 'taxonomy_inclusivity_difficulty'))->not->toBeNull()
        ->and(novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_inclusivity_difficulty'))->toBeNull();
});

/** Applica le dipendenze del campo a una richiesta con il tipo di percorso dato; torna se il campo è visibile. */
function itineraryTypeVisibleWith(?string $routeTypeIdentifier): bool
{
    $field = novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_itinerary_type');
    $routeTypeId = $routeTypeIdentifier ? TaxonomyTerm::where('identifier', $routeTypeIdentifier)->value('id') : null;
    $request = NovaRequest::create('/nova-api/ec-tracks/field/x', 'PATCH', ['taxonomy_route_type' => $routeTypeId]);

    $dependencies = (new ReflectionProperty($field, 'fieldDependencies'))->getValue($field);
    foreach ($dependencies as $dependent) {
        $dependent->handle($field, $request);
    }

    return $field->visible ?? true;
}

it('mostra Tipologia itinerario per itinerari e cammini e la nasconde per i sentieri', function () {
    $field = novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_itinerary_type');
    expect($field)->not->toBeNull();

    expect(itineraryTypeVisibleWith('route-type-itinerary'))->toBeTrue()
        ->and(itineraryTypeVisibleWith('route-type-walk'))->toBeTrue()
        ->and(itineraryTypeVisibleWith('route-type-trail'))->toBeFalse();
});

/** Invoca le rules del campo e restituisce il messaggio di errore, o null se passa. */
function erroreValidazione(string $attribute, array $input): ?string
{
    $field = novaTabField(new EcTrackResource(new EcTrack), __('General'), $attribute);
    $request = NovaRequest::create('/nova-api/ec-tracks', 'POST', $input);
    app()->instance('request', $request);

    $message = null;
    foreach (collect($field->rules)->flatten() as $rule) {
        if ($rule instanceof Closure) {
            $rule($attribute, $input[$attribute] ?? null, function ($m) use (&$message) {
                $message = $m;
            });
        }
    }

    return $message;
}

it('rifiuta al salvataggio una tipologia itinerario su un sentiero, non solo la nasconde', function () {
    $sentiero = TaxonomyTerm::where('identifier', 'route-type-trail')->value('id');
    $itinerario = TaxonomyTerm::where('identifier', 'route-type-itinerary')->value('id');
    $tipologia = TaxonomyTerm::where('identifier', 'sardegnasentieri:tid:4725')->value('id');

    expect(erroreValidazione('taxonomy_itinerary_type', [
        'taxonomy_route_type' => $sentiero,
        'taxonomy_itinerary_type' => [$tipologia],
    ]))->not->toBeNull();

    expect(erroreValidazione('taxonomy_itinerary_type', [
        'taxonomy_route_type' => $itinerario,
        'taxonomy_itinerary_type' => [$tipologia],
    ]))->toBeNull();
});

it('prende la regola dai dati: un tassonomia nuova con depends_on si comporta uguale', function () {
    $source = TaxonomyTerm::where('identifier', 'route-type')->first();
    $cammino = TaxonomyTerm::where('identifier', 'route-type-walk')->value('id');
    $sentiero = TaxonomyTerm::where('identifier', 'route-type-trail')->value('id');

    $taxonomy = TaxonomyTerm::create([
        'identifier' => 'tappe',
        'name' => ['it' => 'Tappe'],
        'properties' => ['depends_on' => ['taxonomy' => $source->identifier, 'terms' => ['route-type-walk']]],
    ]);
    TaxonomyTerm::create(['identifier' => 'tappe-si', 'name' => ['it' => 'Sì'], 'parent_id' => $taxonomy->id]);

    expect(erroreValidazione('taxonomy_tappe', [
        'taxonomy_route_type' => $sentiero,
        'taxonomy_tappe' => [TaxonomyTerm::where('identifier', 'tappe-si')->value('id')],
    ]))->not->toBeNull();

    expect(erroreValidazione('taxonomy_tappe', [
        'taxonomy_route_type' => $cammino,
        'taxonomy_tappe' => [TaxonomyTerm::where('identifier', 'tappe-si')->value('id')],
    ]))->toBeNull();
});

it('mette il tassonomia nel tab che dichiara lui, non in un elenco nel codice', function () {
    // Nasce senza dirlo: finisce nel tab di default.
    $v = TaxonomyTerm::create(['identifier' => 'prova-tab', 'name' => ['it' => 'Prova tab']]);
    TaxonomyTerm::create(['identifier' => 'prova-tab-uno', 'name' => ['it' => 'Uno'], 'parent_id' => $v->id]);

    expect(novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_prova_tab'))->not->toBeNull()
        ->and(novaTabField(new EcTrackResource(new EcTrack), __('Inclusivity'), 'taxonomy_prova_tab'))->toBeNull();

    // Lo dice: si sposta, senza toccare codice.
    $v->update(['properties' => ['tab' => 'Inclusivity']]);

    expect(novaTabField(new EcTrackResource(new EcTrack), __('Inclusivity'), 'taxonomy_prova_tab'))->not->toBeNull()
        ->and(novaTabField(new EcTrackResource(new EcTrack), __('General'), 'taxonomy_prova_tab'))->toBeNull();
});
