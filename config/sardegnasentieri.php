<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raggio dei sentieri vicini (oc:8932)
    |--------------------------------------------------------------------------
    |
    | Distanza in metri entro cui due percorsi si considerano vicini e quindi
    | correlati. Lo leggono i blocchi di CalculateNearbyTracksJob
    | (CalculateNearbyTracksChunkJob) a fine import. Va letto da
    | qui e non con env(): il deploy esegue `php artisan optimize`.
    |
    */

    'nearby_tracks_radius_meters' => 50,

    /*
    |--------------------------------------------------------------------------
    | Dimensione del blocco dei sentieri vicini (oc:8932)
    |--------------------------------------------------------------------------
    |
    | Tracce di ogni blocco (CalculateNearbyTracksChunkJob) che CalculateNearbyTracksJob
    | accoda nel batch (oc:8959). Misurato sul DB reale: 100 tracce impiegano 124 s (le prime per
    | id sono fra le piu' onerose). Con 50 il margine sul timeout della coda
    | (360 s) e' di circa 6 volte.
    |
    */

    'nearby_tracks_chunk_size' => 50,

    /*
    |--------------------------------------------------------------------------
    | Raggio dei percorsi collegati ai POI (oc:8959)
    |--------------------------------------------------------------------------
    |
    | Distanza in metri, dal tratto più vicino, entro cui un percorso è collegato a
    | un POI in `ec_poi_ec_track`: sono i percorsi del POI e, nell'altro verso, i
    | POI correlati della traccia. Sostituisce i collegamenti scritti su Drupal.
    | 1 km (decisione del dev del 12/11): in locale 4.283 collegamenti, 5,6 per
    | traccia, contro i 2.452 di Drupal (3,2 per traccia; 300 m ne darebbero
    | altrettanti). Separato dai 50 m dei sentieri correlati fra tracce. Lo usano i
    | blocchi di CalculatePoiTracksChunkJob, nel batch dei sentieri correlati.
    |
    */

    'poi_tracks_radius_meters' => 1000,

];
