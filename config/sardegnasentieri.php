<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raggio dei sentieri vicini (oc:8932)
    |--------------------------------------------------------------------------
    |
    | Distanza in metri entro cui due percorsi si considerano vicini e quindi
    | correlati. Lo legge CalculateNearbyTracksJob a fine import. Va letto da
    | qui e non con env(): il deploy esegue `php artisan optimize`.
    |
    */

    'nearby_tracks_radius_meters' => 50,

    /*
    |--------------------------------------------------------------------------
    | Dimensione del blocco dei sentieri vicini (oc:8932)
    |--------------------------------------------------------------------------
    |
    | Tracce elaborate da ogni esecuzione di CalculateNearbyTracksJob prima di
    | riaccodarsi. Misurato sul DB reale: 100 tracce impiegano 124 s (le prime per
    | id sono fra le piu' onerose). Con 50 il margine sul timeout della coda
    | (360 s) e' di circa 6 volte.
    |
    */

    'nearby_tracks_chunk_size' => 50,

];
