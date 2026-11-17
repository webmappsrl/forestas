<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Import\RecalculatePoiTracksForDistanceJob;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Wm\WmPackage\Models\App;

/**
 * Quando in Nova cambia la distanza dei percorsi vicini al POI dell'app di import, ricalcola i
 * collegamenti dei POI (oc:8959). L'import orario lo farebbe solo se da Drupal arriva qualcosa, e il
 * reset notturno c'è solo su UAT: senza questo il valore nuovo potrebbe non avere effetto per ore.
 */
class AppNearbyTracksDistanceObserver
{
    public function saved(App $app): void
    {
        if ((int) $app->id !== SardegnaSentieriImportService::IMPORT_APP_ID
            || ! $app->wasChanged(PoiNearbyTracksDistance::COLUMN)) {
            return;
        }

        // Dopo il commit: Nova salva dentro una transazione, e il worker deve leggere il valore nuovo.
        RecalculatePoiTracksForDistanceJob::dispatch()->afterCommit();
    }
}
