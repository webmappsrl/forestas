<?php

declare(strict_types=1);

use App\Models\Ente;
use App\Nova\Ente as EnteResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Marshmallow\Tiptap\Tiptap;

/**
 * Il Tiptap di descrizione e contatti ha i pulsanti della whitelist HTML (oc:8965): senza, un
 * salvataggio da Nova di una descrizione importata ne perderebbe titoli, elenchi e link.
 */
uses(RefreshDatabase::class);

it('dà a descrizione e contatti i pulsanti della whitelist HTML', function () {
    $tiptaps = collect((new EnteResource(new Ente))->fields(NovaRequest::create('/nova-api/x', 'GET')))
        ->filter(fn ($field) => $field instanceof NovaTabTranslatable)
        ->flatMap(fn ($tab) => $tab->data)
        ->filter(fn ($field) => $field instanceof Tiptap);

    expect($tiptaps)->not->toBeEmpty();
    foreach ($tiptaps as $tiptap) {
        expect($tiptap->meta['buttons'] ?? null)
            ->toBe(['heading', 'bold', 'italic', 'link', 'bulletList', 'orderedList'])
            ->and($tiptap->meta['headingLevels'] ?? null)->toBe([2, 3, 4, 5, 6]);
    }
});

/** Il campo Slug della Resource Ente. */
function enteSlugField(): Field
{
    return collect((new EnteResource(new Ente))->fields(NovaRequest::create('/nova-api/x', 'GET')))
        ->first(fn ($field) => $field instanceof Field && $field->attribute === 'slug');
}

it('rende lo slug ASCII anche quando lo scrive la redazione', function (string $input, string $expected) {
    // Gli URL di WordPress vengono da sanitize_title(), che toglie gli accenti (oc:8965).
    $ente = new Ente(['name' => ['it' => 'Comune di Oliena']]);
    $request = NovaRequest::create('/nova-api/entes', 'POST', ['slug' => $input]);

    enteSlugField()->fill($request, $ente);

    expect($ente->slug)->toBe($expected);
})->with([
    'accentato' => ['Comune di Lodè', 'comune-di-lode'],
    'vuoto, dal nome' => ['', 'comune-di-oliena'],
]);

it('cerca gli enti in Nova sul database, non su Elasticsearch', function () {
    // Con il trait Searchable sul modello Nova passerebbe per Scout: niente ricerca per
    // sardegnasentieri_id, errori con le parentesi e 500 prima di scout:import (review oc:8965).
    expect(EnteResource::usesScout())->toBeFalse();
});

it('vuole uno slug unico anche dopo la normalizzazione', function () {
    Ente::create(['sardegnasentieri_id' => '82', 'slug' => 'comune-di-lode', 'name' => ['it' => 'Comune di Lodè'], 'properties' => []]);
    $request = NovaRequest::create('/nova-api/entes', 'POST', ['slug' => 'Comune di Lodè']);

    $rules = enteSlugField()->getCreationRules($request);
    $validator = Validator::make(['slug' => 'Comune di Lodè'], $rules);

    expect($validator->fails())->toBeTrue();
});
