<?php

declare(strict_types=1);

use App\Services\Import\SardegnaSentieriImportService;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Lo smistamento dei due assi all'import (oc:8989).
 *
 * In Drupal lo stato è uno solo e due dei suoi sette valori parlano del
 * terreno: quelli vanno nella percorribilità, e la validazione di quei sentieri
 * diventa «Non verificato» — scritta, non lasciata vuota.
 */
it('manda i due tid di terreno alla percorribilità, non alla validazione', function () {
    $map = SardegnaSentieriImportService::VALIDATION_STATUS_TERMS;

    expect($map)->not->toHaveKey('4870')
        ->and($map)->not->toHaveKey('4187');
});

it('tiene i cinque tid della pratica catastale sulla validazione', function () {
    $map = SardegnaSentieriImportService::VALIDATION_STATUS_TERMS;

    expect(array_values($map))->toEqualCanonicalizing([
        'validation-status-unverified',
        'validation-status-under-review',
        'validation-status-pre-registration',
        'validation-status-validated',
        'validation-status-certified',
    ]);
});

it('mappa i due tid di terreno sugli stati di percorribilità', function () {
    $map = SardegnaSentieriImportService::WALKABILITY_STATUS_TERMS;

    expect($map['4870'])->toBe('walkability-status-not-walkable')
        ->and($map['4187'])->toBe('walkability-status-walkable');
});

it('lascia a validation-status i soli cinque stati di pratica dopo la configurazione', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    expect($family->children()->pluck('identifier')->all())->toEqualCanonicalizing([
        'validation-status-unverified',
        'validation-status-under-review',
        'validation-status-pre-registration',
        'validation-status-validated',
        'validation-status-certified',
    ]);
});
