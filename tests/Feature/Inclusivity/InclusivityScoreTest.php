<?php

declare(strict_types=1);

use App\Models\EcTrack;
use App\Support\InclusivityScore;
use Illuminate\Support\Facades\Schema;

/**
 * Punteggio e classi di inclusività (oc:8910): derivati, mai in colonna.
 */
function trackInclusivita(?int $fisica, ?int $ausili, ?int $digitale): EcTrack
{
    return new EcTrack([
        'forestas_inclusivity_physical' => $fisica,
        'forestas_inclusivity_aids' => $ausili,
        'forestas_inclusivity_digital' => $digitale,
    ]);
}

it('somma i tre interi e assegna la classe', function () {
    expect(InclusivityScore::for(trackInclusivita(55, 23, 22)))->toBe(['score' => 100, 'class' => 'A1'])
        ->and(InclusivityScore::for(trackInclusivita(40, 20, 20)))->toBe(['score' => 80, 'class' => 'A1'])
        ->and(InclusivityScore::for(trackInclusivita(40, 20, 19)))->toBe(['score' => 79, 'class' => 'A2'])
        ->and(InclusivityScore::for(trackInclusivita(40, 10, 7))['class'])->toBe('A2');
});

it('non produce punteggio né classe se manca anche uno solo dei tre valori', function () {
    expect(InclusivityScore::for(trackInclusivita(11, null, null)))->toBeNull()
        ->and(InclusivityScore::for(trackInclusivita(null, 5, 5)))->toBeNull()
        ->and(InclusivityScore::for(trackInclusivita(30, 5, null)))->toBeNull()
        ->and(InclusivityScore::for(trackInclusivita(30, 5, 0)))->not->toBeNull();
});

it('le soglie vengono dalla configurazione', function () {
    config(['forestas.inclusivity_classes' => ['X' => ['min' => 0, 'max' => 100]]]);

    expect(InclusivityScore::for(trackInclusivita(55, 23, 22))['class'])->toBe('X');
});

it('deposita punteggio e classe nell\'indice e non in una colonna', function () {
    $track = trackInclusivita(55, 23, 22);
    $track->id = 1;
    $track->app_id = 1;
    $track->setRawAttributes(array_merge($track->getAttributes(), ['geometry' => null]));

    $indexed = $track->toSearchableArray();

    expect($indexed['inclusivity_score'])->toBe(100)
        ->and($indexed['inclusivity_class'])->toBe('A1')
        ->and(Schema::hasColumn('ec_tracks', 'inclusivity_score'))->toBeFalse();
});

it('nell\'indice un sentiero non misurato ha punteggio e classe null', function () {
    $track = trackInclusivita(11, null, null);
    $track->id = 1;
    $track->app_id = 1;

    expect($track->toSearchableArray())
        ->toMatchArray(['inclusivity_score' => null, 'inclusivity_class' => null]);
});
