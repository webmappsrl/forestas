<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Un blocco del calcolo dei collegamenti fra POI e percorsi per vicinanza (oc:8959): per ogni POI
 * del blocco scrive in `ec_poi_ec_track` le tracce della stessa app entro
 * `sardegnasentieri.poi_tracks_radius_meters` (1 km), al posto dei collegamenti scritti su Drupal,
 * come i sentieri correlati fra tracce. `order` è la distanza in metri dal tratto più vicino: le
 * tracce elencano i loro POI correlati (`EcTrack::ecPois()`, ordinata per `order`) dal più vicino.
 *
 * Il dettaglio del POI legge la tabella dal vivo, quindi non si svuota mai tutta: ogni blocco
 * cancella e riscrive le righe dei soli suoi POI in una transazione. Senza eventi: il pivot del
 * package (`EcPoiEcTrack`) ha un observer che aggiungerebbe i POI ai layer di ogni traccia, mentre
 * per questa app i POI dei layer li decide `RecalculateSardegnaSentieriLayersJob` (solo layer delle
 * aree). Lo accoda `CalculateNearbyTracksJob` nello stesso batch dei sentieri correlati.
 */
class CalculatePoiTracksChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public const OVERLAP_KEY = 'sardegnasentieri-poi-tracks';

    /**
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import` (360 in
     * config/horizon.php), come gli altri job della coda di import.
     */
    public int $timeout = 300;

    /**
     * @param  list<int>  $poiIds  i POI del blocco, in ordine di id
     */
    public function __construct(public array $poiIds)
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Mai due esecuzioni dello stesso blocco insieme; blocchi diversi girano in parallelo.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY.'-'.($this->poiIds[0] ?? 0)))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled() || $this->poiIds === []) {
            return;
        }

        $radius = (float) config('sardegnasentieri.poi_tracks_radius_meters', 1000);
        $placeholders = implode(',', array_fill(0, count($this->poiIds), '?'));

        // Le colonne sono `geography`: distanza e raggio in metri. Il cast resta esplicito per
        // proteggere da un cambio a `::geometry`, che darebbe gradi.
        $rows = DB::select(
            <<<SQL
            SELECT p.id AS ec_poi_id, t.id AS ec_track_id,
                   ST_Distance(t.geometry::geography, p.geometry::geography) AS distance
            FROM ec_pois p
            JOIN ec_tracks t
              ON t.app_id = p.app_id
             AND ST_DWithin(t.geometry::geography, p.geometry::geography, ?)
            WHERE p.id IN ({$placeholders})
            SQL,
            [$radius, ...$this->poiIds]
        );

        $insert = array_map(fn ($row) => [
            'ec_poi_id' => (int) $row->ec_poi_id,
            'ec_track_id' => (int) $row->ec_track_id,
            'order' => (int) round((float) $row->distance),
        ], $rows);

        DB::transaction(function () use ($insert) {
            DB::table('ec_poi_ec_track')->whereIn('ec_poi_id', $this->poiIds)->delete();
            foreach (array_chunk($insert, 1000) as $chunk) {
                DB::table('ec_poi_ec_track')->insert($chunk);
            }
        });

        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Percorsi dei POI: blocco %s, %d POI, %d collegamenti entro %s m.',
            $this->range(),
            count($this->poiIds),
            count($insert),
            $radius
        ));
    }

    /**
     * Un blocco saltato lascia ai suoi POI i collegamenti di prima; il batch arriva comunque alla
     * rigenerazione. Per rifarlo: `CalculatePoiTracksChunkJob::dispatch([<id>, …])` da tinker.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('import')->error(sprintf(
            '[sardegnasentieri] Percorsi dei POI: blocco %s saltato (%s). Quei POI tengono i collegamenti di prima.',
            $this->range(),
            $exception?->getMessage() ?? 'errore sconosciuto'
        ));
    }

    private function range(): string
    {
        return sprintf('da id %d a id %d', $this->poiIds[0] ?? 0, $this->poiIds[count($this->poiIds) - 1] ?? 0);
    }
}
