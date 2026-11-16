<?php

declare(strict_types=1);

use App\Nova\Filters\EcTrackValidationStatusFilter;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\Services\TrailStatusService;

/**
 * Lo stato di validazione lascia la sua riga nello storico, e i due termini di terreno usciti
 * dalla famiglia non si ripresentano come scelte (oc:8989).
 */
beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $this->validationTrack = EcTrack::factory()->createQuietly(['osmid' => null]);
});

it('registra un evento quando lo stato di validazione cambia', function () {
    app(TrailStatusService::class)->set(
        $this->validationTrack,
        'validation-status',
        'validation-status-validated',
        null,
        null,
        TrailStatusEventSource::Import,
    );

    $event = TrailStatusEvent::query()->where('ec_track_id', $this->validationTrack->id)->sole();

    expect($event->source)->toBe(TrailStatusEventSource::Import)
        ->and($event->toTerm->identifier)->toBe('validation-status-validated');
});

it('non scrive un evento se lo stato non cambia', function () {
    foreach ([1, 2] as $ignored) {
        app(TrailStatusService::class)->set(
            $this->validationTrack,
            'validation-status',
            'validation-status-validated',
            null,
            null,
            TrailStatusEventSource::Import,
        );
    }

    expect(TrailStatusEvent::query()->where('ec_track_id', $this->validationTrack->id)->count())->toBe(1);
});

it('non offre fra gli stati i due termini di terreno rimasti in tabella', function () {
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    // Un residuo come quelli che un database aggiornato può ancora avere: la storia che li
    // nomina impedisce di cancellarli, e devono comunque restare fuori dalle scelte.
    TaxonomyTerm::query()->create([
        'parent_id' => $family->id,
        'identifier' => 'validation-status-not-walkable',
        'name' => ['it' => 'Non percorribile', 'en' => 'Not walkable'],
        'sort_order' => 4,
    ]);

    $identifiers = EcTrackValidationStatusFilter::statuses()->pluck('identifier')->all();

    expect($identifiers)->not->toContain('validation-status-not-walkable')
        ->and($identifiers)->toContain('validation-status-validated');
});

it('toglie un termine ritirato che non usa più nessuno', function () {
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    TaxonomyTerm::query()->create([
        'parent_id' => $family->id,
        'identifier' => 'validation-status-walkable',
        'name' => ['it' => 'Percorribile', 'en' => 'Walkable'],
        'sort_order' => 1,
    ]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::query()->where('identifier', 'validation-status-walkable')->exists())->toBeFalse();
});

it('non toglie un termine ritirato che la storia nomina', function () {
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    $term = TaxonomyTerm::query()->create([
        'parent_id' => $family->id,
        'identifier' => 'validation-status-walkable',
        'name' => ['it' => 'Percorribile', 'en' => 'Walkable'],
        'sort_order' => 1,
    ]);

    TrailStatusEvent::query()->create([
        'ec_track_id' => $this->validationTrack->id,
        'taxonomy_id' => $family->id,
        'from_term_id' => null,
        'to_term_id' => $term->id,
        'user_id' => null,
        'source' => TrailStatusEventSource::Migration,
        'created_at' => now(),
    ]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::query()->where('identifier', 'validation-status-walkable')->exists())->toBeTrue();
});
