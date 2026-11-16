<?php

declare(strict_types=1);

use App\Services\TrailRegistry\InconsistencyTypes\MissingWalkability;
use App\Services\TrailRegistry\WalkabilityInconsistencyService;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * L'incongruenza dei sentieri validati di cui non si conosce la percorribilità
 * (oc:8989).
 */
function trackWithValidation(string $validationIdentifier): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $term = TaxonomyTerm::query()->where('identifier', $validationIdentifier)->sole();
    $track->taxonomyTerms()->attach($term->id);

    return $track->fresh();
}

function inconsistenciesOf(EcTrack $track): int
{
    return TrailRegistryAnomaly::query()
        ->where('ec_track_id', $track->id)
        ->where('source', TrailRegistryInconsistenciesCard::SOURCE)
        ->where('type', MissingWalkability::TYPE)
        ->count();
}

beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $this->service = app(WalkabilityInconsistencyService::class);
});

it('segnala un sentiero validato senza percorribilità nota', function () {
    $track = trackWithValidation('validation-status-validated');
    app(WalkabilityService::class)->set($track, 'walkability-status-unknown', null, null, TrailStatusEventSource::Migration);

    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(1);
});

it('segnala anche un sentiero certificato', function () {
    $track = trackWithValidation('validation-status-certified');
    app(WalkabilityService::class)->set($track, 'walkability-status-unknown', null, null, TrailStatusEventSource::Migration);

    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(1);
});

it('non segnala un sentiero ancora in revisione', function () {
    $track = trackWithValidation('validation-status-under-review');
    app(WalkabilityService::class)->set($track, 'walkability-status-unknown', null, null, TrailStatusEventSource::Migration);

    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(0);
});

it('non segnala un sentiero validato di cui si conosce la percorribilità', function () {
    $track = trackWithValidation('validation-status-validated');
    app(WalkabilityService::class)->set($track, 'walkability-status-walkable', null, null, TrailStatusEventSource::Manual);

    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(0);
});

it('toglie la segnalazione appena qualcuno inserisce la percorribilità', function () {
    $track = trackWithValidation('validation-status-validated');
    app(WalkabilityService::class)->set($track, 'walkability-status-unknown', null, null, TrailStatusEventSource::Migration);
    $this->service->recalculate();

    app(WalkabilityService::class)->set($track->fresh(), 'walkability-status-walkable', 'percorso ieri', null, TrailStatusEventSource::Manual);
    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(0);
});

it('non duplica la segnalazione a ogni ricalcolo', function () {
    $track = trackWithValidation('validation-status-validated');
    app(WalkabilityService::class)->set($track, 'walkability-status-unknown', null, null, TrailStatusEventSource::Migration);

    $this->service->recalculate();
    $this->service->recalculate();

    expect(inconsistenciesOf($track->fresh()))->toBe(1);
});

it('non esplode su un sentiero senza alcun termine', function () {
    EcTrack::factory()->createQuietly(['osmid' => null]);

    expect(fn () => $this->service->recalculate())->not->toThrow(Exception::class);
});
