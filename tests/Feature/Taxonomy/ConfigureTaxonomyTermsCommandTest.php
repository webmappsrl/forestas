<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;

uses(RefreshDatabase::class);

it('crea gli undici tassonomie e i loro termini', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::taxonomies()->count())->toBe(11)
        // Le avvertenze: la tassonomia nasce qui, i termini li porta l'import (oc:8910).
        ->and(TaxonomyTerm::where('identifier', 'warnings')->first()->children)->toHaveCount(0)
        ->and(TaxonomyTerm::where('identifier', 'warnings')->first()->icon)->toBe('txn-alert')
        ->and(TaxonomyTerm::where('identifier', 'inclusivity-difficulty')->first()->single_choice)->toBeTrue()
        ->and(TaxonomyTerm::where('identifier', 'inclusivity-disabilities')->first()->single_choice)->toBeFalse()
        ->and(TaxonomyTerm::where('identifier', 'mtb-difficulty')->first()->children)->toHaveCount(5)
        ->and(TaxonomyTerm::where('identifier', 'sardegnasentieri:tid:4909')->first()->getTranslation('name', 'it'))->toBe('MC')
        ->and(TaxonomyTerm::where('identifier', 'inclusivity-disabilities-sensory')->first()->icon)->toBeNull()
        ->and(TaxonomyTerm::where('identifier', 'inclusivity-disabilities-physical')->first()->icon)->toBe('txn-mobility-disability');
});

it('è rilanciabile e non duplica niente, id compresi', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $ids = TaxonomyTerm::orderBy('identifier')->pluck('id', 'identifier')->all();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::count())->toBe(count($ids))
        ->and(TaxonomyTerm::orderBy('identifier')->pluck('id', 'identifier')->all())->toBe($ids);
});

it('riallinea il nome di una riga di sistema e non tocca una tassonomia creata a mano', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    TaxonomyTerm::where('identifier', 'hiking-difficulty')->update(['name' => ['it' => 'Rinominata']]);
    $custom = TaxonomyTerm::create(['identifier' => 'custom-taxonomy', 'name' => ['it' => 'Mia']]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    expect(TaxonomyTerm::where('identifier', 'hiking-difficulty')->first()->getTranslation('name', 'it'))->toBe('Difficoltà escursionistica')
        ->and(TaxonomyTerm::find($custom->id)->getTranslation('name', 'it'))->toBe('Mia');
});

it('con --fresh riparte da vuoto', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $custom = TaxonomyTerm::create(['identifier' => 'custom-taxonomy', 'name' => ['it' => 'Mia']]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms --fresh')->assertSuccessful();

    expect(TaxonomyTerm::find($custom->id))->toBeNull()
        ->and(TaxonomyTerm::taxonomies()->count())->toBe(11);
});

it('con --dry-run non scrive niente', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms --dry-run')->assertSuccessful();
    expect(TaxonomyTerm::count())->toBe(0);
});

it('riallinea l\'icona delle tassonomie già create senza duplicare', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    TaxonomyTerm::where('identifier', 'hiking-difficulty')->update(['icon' => null]);
    $count = TaxonomyTerm::count();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::count())->toBe($count)
        ->and(TaxonomyTerm::where('identifier', 'hiking-difficulty')->first()->icon)->toBe('txn-trekking')
        ->and(TaxonomyTerm::where('identifier', 'route-type')->first()->icon)->toBeNull()
        ->and(TaxonomyTerm::where('identifier', 'sardegnasentieri:tid:4066')->first()->icon)->toBeNull();
});

it('porta il tipo percorso a scelta singola con i tre termini in ordine, aggiornando la tassonomia esistente', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    TaxonomyTerm::where('identifier', 'route-type')->update(['single_choice' => false]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    $taxonomy = TaxonomyTerm::where('identifier', 'route-type')->first();
    expect(TaxonomyTerm::where('identifier', 'route-type')->count())->toBe(1)
        ->and($taxonomy->single_choice)->toBeTrue()
        ->and($taxonomy->children()->orderBy('sort_order')->pluck('identifier')->all())
        ->toBe(['route-type-trail', 'route-type-itinerary', 'route-type-walk']);
});

it('rinomina una chiave cambiata invece di creare un doppione', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    // Si riporta indietro la chiave, come su un database allineato prima della rinomina.
    TaxonomyTerm::where('identifier', 'educational-trails-difficulty')->update(['identifier' => 'educational-trail-difficulty']);
    $before = TaxonomyTerm::where('identifier', 'educational-trail-difficulty')->first();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::where('identifier', 'educational-trail-difficulty')->count())->toBe(0)
        // Stessa riga, non una nuova: le relazioni dei suoi termini restano dove sono.
        ->and(TaxonomyTerm::where('identifier', 'educational-trails-difficulty')->value('id'))->toBe($before->id)
        ->and(TaxonomyTerm::taxonomies()->count())->toBe(11);
});

it('dà alle attività il nome italiano, senza toccare quelle che ne hanno già uno', function () {
    $horse = TaxonomyActivity::create(['identifier' => 'horse', 'name' => ['it' => 'horse']]);
    $custom = TaxonomyActivity::create(['identifier' => 'mtb', 'name' => ['it' => 'La mia bici']]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect($horse->fresh()->getTranslation('name', 'it', false))->toBe('Ippovia')
        ->and($custom->fresh()->getTranslation('name', 'it', false))->toBe('La mia bici');
});
