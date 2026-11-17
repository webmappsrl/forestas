<?php

namespace App\Nova;

use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Laravel\Nova\Fields\Number;
use Wm\WmPackage\Nova\App as WmNovaApp;

class App extends WmNovaApp
{
    /**
     * I campi della tab `pois` del package, più la distanza dei percorsi vicini al POI (oc:8959):
     * entro quanti metri una traccia è collegata a un POI. Salvarla con un valore diverso ricalcola
     * i collegamenti (`AppNearbyTracksDistanceObserver`). Nessun massimo, per decisione del dev.
     * Solo sull'App dell'import: è l'unica i cui POI sono collegati per vicinanza.
     */
    protected function pois_tab(): array
    {
        return [
            ...parent::pois_tab(),

            Number::make(__('Maximum distance of tracks near the POI (m)'), PoiNearbyTracksDistance::COLUMN)
                ->canSee(fn () => (int) ($this->resource->id ?? 0) === SardegnaSentieriImportService::IMPORT_APP_ID)
                ->rules('required', 'integer', 'min:1')
                ->step(1)
                ->default(PoiNearbyTracksDistance::DEFAULT_METERS)
                ->hideFromIndex()
                ->help(__('Straight-line distance within which a track is linked to the POI. Saving a different value recalculates the nearby tracks of every POI and rewrites the track files: for a few minutes a POI detail may show the new distance with the old tracks.')),
        ];
    }
}
