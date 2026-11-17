<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Ricalcola solo i collegamenti fra POI e percorsi (`ec_poi_ec_track`) dell'app di import (oc:8959):
 * lo accoda `AppNearbyTracksDistanceObserver` quando in Nova cambia la distanza dei percorsi vicini.
 *
 * A differenza di `CalculateNearbyTracksJob` non svuota né ricalcola i sentieri correlati fra
 * tracce, che non dipendono da quella distanza: un ricalcolo sovrapposto all'import non può lasciare
 * tracce senza correlati. Alla fine del batch, anche se un blocco è fallito, parte la stessa
 * rigenerazione (file delle tracce, che elencano i loro POI, indice e `pois.geojson`).
 *
 * Unico finché non parte: più salvataggi ravvicinati in Nova accodano un ricalcolo solo, che legge
 * l'ultimo valore salvato.
 */
class RecalculatePoiTracksForDistanceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import` (360 in
     * config/horizon.php), come gli altri job della coda di import.
     */
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    public function handle(): void
    {
        $chunks = CalculatePoiTracksChunkJob::forImportApp();

        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Percorsi dei POI, ricalcolo per la distanza cambiata: %d blocchi.',
            count($chunks)
        ));

        if ($chunks === []) {
            CalculateNearbyTracksJob::regenerateOutputs();

            return;
        }

        Bus::batch($chunks)
            ->name('sardegnasentieri poi nearby tracks')
            ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)
            ->allowFailures()
            ->finally(static fn () => CalculateNearbyTracksJob::regenerateOutputs())
            ->dispatch();
    }
}
