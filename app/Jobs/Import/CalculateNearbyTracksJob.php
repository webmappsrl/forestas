<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;

/**
 * Calcola dalla geometria i sentieri correlati (oc:8932).
 *
 * Due percorsi dell'app di import sono correlati se distano al massimo
 * `sardegnasentieri.nearby_tracks_radius_meters` metri. Il calcolo sostituisce
 * l'import dei correlati di Drupal: quelli erano contiguita', e un dato
 * calcolato vale anche per i sentieri creati dopo lo spegnimento di Drupal.
 *
 * Questo job svuota la tabella e accoda un batch di `CalculateNearbyTracksChunkJob`, uno ogni
 * `sardegnasentieri.nearby_tracks_chunk_size` tracce (ordinate per id), cosi' ogni blocco sta
 * largamente dentro il timeout della coda. Alla fine del batch, anche se un blocco e' fallito,
 * parte `RegenerateTaxonomyWhereOutputsJob`, che riscrive i file delle tracce e l'indice.
 *
 * La rigenerazione sta nel `finally` del batch e non dopo questo job nella catena dell'import:
 * i file delle tracce portano i sentieri correlati (`properties.forestas.nearby_tracks`), e
 * rigenerati prima della fine dei blocchi uscivano vuoti o parziali fino al giro successivo
 * (oc:8959).
 */
class CalculateNearbyTracksJob implements ShouldQueue
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
        $chunkSize = max(1, (int) config('sardegnasentieri.nearby_tracks_chunk_size', 50));

        $ids = DB::table('ec_tracks')
            ->where('app_id', SardegnaSentieriImportService::IMPORT_APP_ID)
            ->whereNotNull('geometry')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        DB::table('ec_track_related_tracks')->delete();

        $chunks = $ids->chunk($chunkSize)
            ->map(fn ($chunk) => new CalculateNearbyTracksChunkJob($chunk->values()->all()))
            ->values()
            ->all();

        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Sentieri vicini: %d tracce in %d blocchi.',
            $ids->count(),
            count($chunks)
        ));

        if ($chunks === []) {
            self::regenerateOutputs();

            return;
        }

        // Ultima istruzione: un nuovo tentativo di questo job dopo l'accodamento svuoterebbe le
        // righe gia' scritte dai blocchi.
        Bus::batch($chunks)
            ->name('sardegnasentieri nearby tracks')
            ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)
            ->allowFailures()
            ->finally(static fn () => self::regenerateOutputs())
            ->dispatch();
    }

    /**
     * File delle tracce, indice e `pois.geojson` dell'app di import, sulla coda dell'import come
     * nella catena di fine import.
     */
    public static function regenerateOutputs(): void
    {
        RegenerateTaxonomyWhereOutputsJob::dispatch(SardegnaSentieriImportService::IMPORT_APP_ID)
            ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }
}
