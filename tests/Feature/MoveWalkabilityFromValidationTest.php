<?php

declare(strict_types=1);

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Lo spostamento dei sentieri che oggi portano i due termini di terreno dentro
 * `validation-status` (oc:8989).
 *
 * Lavora per sentiero e non rinomina le righe dei termini: i termini della
 * percorribilita' esistono gia' — li crea il package — e il comando delle
 * tassonomie, trovando sia il vecchio sia il nuovo identifier, salterebbe la
 * rinomina con un avvertimento, lasciando i sentieri dov'erano.
 */
function moveWalkabilityTrack(string $validationIdentifier): EcTrack
{
    // La factory sta nel package: `App\Models\EcTrack::factory()` la cercherebbe
    // nel namespace dello shard, dove non c'e' (trappola di HasPackageFactory).
    $track = EcTrack::factory()->createQuietly(['osmid' => null]);
    $term = TaxonomyTerm::query()->where('identifier', $validationIdentifier)->sole();
    $track->taxonomyTerms()->attach($term->id);

    return $track->fresh();
}

function walkabilityOf(EcTrack $track): ?string
{
    return app(WalkabilityService::class)->current($track)?->identifier;
}

function validationOf(EcTrack $track): ?string
{
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    return $track->taxonomyTerms()
        ->where('taxonomy_terms.parent_id', $family->id)
        ->value('taxonomy_terms.identifier');
}

/**
 * I due termini di terreno dentro `validation-status`, che il comando delle tassonomie non
 * dichiara piu': sono proprio quelli che la migrazione deve trovare e svuotare. Ricrearli qui e'
 * il modo di mettere il database nello stato in cui il comando lo incontrera' la prima volta.
 */
function restoreLegacyWalkabilityTerms(): void
{
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->whereNull('parent_id')->sole();

    foreach ([
        ['identifier' => 'validation-status-walkable', 'name' => 'Percorribile', 'sort_order' => 1],
        ['identifier' => 'validation-status-not-walkable', 'name' => 'Non percorribile', 'sort_order' => 4],
    ] as $term) {
        TaxonomyTerm::query()->firstOrCreate(
            ['identifier' => $term['identifier']],
            [
                'parent_id' => $family->id,
                'name' => ['it' => $term['name'], 'en' => $term['name']],
                'sort_order' => $term['sort_order'],
            ],
        );
    }
}

beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    restoreLegacyWalkabilityTerms();
});

it('porta un sentiero percorribile nella nuova famiglia e segna la validazione come non verificata', function () {
    $track = moveWalkabilityTrack('validation-status-walkable');

    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();

    expect(walkabilityOf($track->fresh()))->toBe('walkability-status-walkable')
        ->and(validationOf($track->fresh()))->toBe('validation-status-unverified');
});

it('porta un sentiero non percorribile nella nuova famiglia', function () {
    $track = moveWalkabilityTrack('validation-status-not-walkable');

    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();

    expect(walkabilityOf($track->fresh()))->toBe('walkability-status-not-walkable')
        ->and(validationOf($track->fresh()))->toBe('validation-status-unverified');
});

it('dà percorribilità sconosciuta agli altri, senza toccarne la validazione', function () {
    $track = moveWalkabilityTrack('validation-status-validated');

    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();

    expect(walkabilityOf($track->fresh()))->toBe('walkability-status-unknown')
        ->and(validationOf($track->fresh()))->toBe('validation-status-validated');
});

it('registra l_evento con provenienza migrazione e senza autore', function () {
    $track = moveWalkabilityTrack('validation-status-walkable');

    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();

    $event = TrailStatusEvent::query()->where('ec_track_id', $track->id)->sole();

    expect($event->source)->toBe(TrailStatusEventSource::Migration)
        ->and($event->user_id)->toBeNull();
});

it('rilanciato una seconda volta non aggiunge eventi', function () {
    $track = moveWalkabilityTrack('validation-status-walkable');

    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();
    $this->artisan('sardegnasentieri:move-walkability')->assertSuccessful();

    expect(TrailStatusEvent::query()->where('ec_track_id', $track->id)->count())->toBe(1);
});

it('con --dry-run non scrive niente', function () {
    $track = moveWalkabilityTrack('validation-status-walkable');

    $this->artisan('sardegnasentieri:move-walkability --dry-run')->assertSuccessful();

    expect(TrailStatusEvent::count())->toBe(0)
        ->and(validationOf($track->fresh()))->toBe('validation-status-walkable');
});
