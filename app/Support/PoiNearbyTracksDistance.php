<?php

declare(strict_types=1);

namespace App\Support;

use Wm\WmPackage\Models\App;

/**
 * La distanza massima dei percorsi vicini al POI (oc:8959): entro quanti metri una traccia è
 * collegata a un POI in `ec_poi_ec_track`. Sta in una colonna di `apps`, modificabile in Nova, e il
 * dettaglio del POI la espone accanto ai `nearby_tracks` calcolati con lei.
 */
final class PoiNearbyTracksDistance
{
    public const COLUMN = 'ec_poi_nearby_ec_tracks_max_distance_meters';

    /**
     * Il raggio usato prima che fosse configurabile, e il default della colonna.
     */
    public const DEFAULT_METERS = 1000;

    /**
     * Il valore dell'App, o il default se manca: mai 0, che ridurrebbe i collegamenti alle sole
     * tracce che passano sul POI.
     */
    public static function forApp(?App $app): int
    {
        $meters = (int) ($app?->getAttribute(self::COLUMN) ?? 0);

        return $meters > 0 ? $meters : self::DEFAULT_METERS;
    }
}
