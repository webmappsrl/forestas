<?php

declare(strict_types=1);

use App\Models\EcTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * La data dell'avviso (oc:8981) è scritta dal saving del modello del package: deve scattare
 * anche per App\Models\EcTrack, dove l'observer del package non arriva.
 */
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('writes the date on App\Models\EcTrack', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'UTC'));

    $track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly(['properties' => ['not_accessible' => false]]);
    $track = EcTrack::find($track->id);
    $track->properties = [...$track->properties, 'not_accessible' => true];
    $track->save();

    expect(EcTrack::find($track->id)->properties['not_accessible_updated_at'])
        ->toBe('2026-11-17T10:00:00+00:00');
});
