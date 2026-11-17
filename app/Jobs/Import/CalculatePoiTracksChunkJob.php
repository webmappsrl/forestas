<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\App;

/**
 * Un blocco del calcolo dei collegamenti fra POI e percorsi per vicinanza (oc:8959): per ogni POI
 * del blocco scrive in `ec_poi_ec_track` le tracce della stessa app entro la distanza della sua
 * App (`PoiNearbyTracksDistance`, 1 km di default, modificabile in Nova), al posto dei collegamenti
 * scritti su Drupal, come i sentieri correlati fra tracce. `order` è la distanza in metri dal tratto
 * più vicino: le tracce elencano i loro POI correlati (`EcTrack::ecPois()`, ordinata per `order`)
 * dal più vicino.
 *
 * Il dettaglio del POI legge la tabella dal vivo, quindi non si svuota mai tutta: ogni blocco
 * cancella e riscrive le righe dei soli suoi POI in una transazione. Senza eventi: il pivot del
 * package (`EcPoiEcTrack`) ha un observer che aggiungerebbe i POI ai layer di ogni traccia, mentre
 * per questa app i POI dei layer li decide `RecalculateSardegnaSentieriLayersJob` (solo layer delle
 * aree). Lo accodano `CalculateNearbyTracksJob`, nel batch dei sentieri correlati a fine import, e
 * `RecalculatePoiTracksForDistanceJob`, quando in Nova cambia la distanza.
 */
class CalculatePoiTracksChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

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
     * I blocchi dei POI dell'app di import, in ordine di id, da `nearby_tracks_chunk_size` POI: li
     * usano sia il batch di fine import sia il ricalcolo per la distanza cambiata.
     *
     * @return list<self>
     */
    public static function forImportApp(): array
    {
        $chunkSize = max(1, (int) config('sardegnasentieri.nearby_tracks_chunk_size', 50));

        return DB::table('ec_pois')
            ->where('app_id', SardegnaSentieriImportService::IMPORT_APP_ID)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->chunk($chunkSize)
            ->map(fn ($chunk) => new self($chunk->values()->all()))
            ->values()
            ->all();
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled() || $this->poiIds === []) {
            return;
        }

        $radii = [];
        $insert = DB::transaction(function () use (&$radii) {
            // Prima si bloccano le righe dei POI, in ordine di id (niente deadlock). Due ricalcoli
            // possono girare insieme (un cambio in Nova durante l'import) con blocchi dai confini
            // diversi: senza il blocco delle righe, il secondo cancellerebbe con uno snapshot che
            // non vede le righe appena scritte dal primo e le duplicherebbe, perché
            // `ec_poi_ec_track` non ha un vincolo unico. Il blocco mette in fila anche due
            // esecuzioni dello stesso blocco, e dopo di lui si legge il raggio salvato per ultimo.
            $appIds = DB::table('ec_pois')
                ->whereIn('id', $this->poiIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('app_id', 'id');

            $insert = [];
            foreach ($appIds->groupBy(fn ($appId) => (int) $appId, preserveKeys: true) as $appId => $pois) {
                $radius = PoiNearbyTracksDistance::forApp(App::query()->find($appId));
                $radii[] = $radius;
                $insert = [...$insert, ...$this->linksWithin($pois->keys()->map(fn ($id) => (int) $id)->all(), $radius)];
            }

            DB::table('ec_poi_ec_track')->whereIn('ec_poi_id', $this->poiIds)->delete();
            foreach (array_chunk($insert, 1000) as $chunk) {
                DB::table('ec_poi_ec_track')->insert($chunk);
            }

            return $insert;
        });

        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Percorsi dei POI: blocco %s, %d POI, %d collegamenti entro %s m.',
            $this->range(),
            count($this->poiIds),
            count($insert),
            $radii === [] ? '?' : implode(', ', array_unique($radii))
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

    /**
     * Le righe di `ec_poi_ec_track` dei POI indicati: le tracce della stessa app entro $radius metri.
     *
     * @param  list<int>  $poiIds
     * @return list<array{ec_poi_id: int, ec_track_id: int, order: int}>
     */
    private function linksWithin(array $poiIds, int $radius): array
    {
        $placeholders = implode(',', array_fill(0, count($poiIds), '?'));

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
            [$radius, ...$poiIds]
        );

        return array_map(fn ($row) => [
            'ec_poi_id' => (int) $row->ec_poi_id,
            'ec_track_id' => (int) $row->ec_track_id,
            'order' => (int) round((float) $row->distance),
        ], $rows);
    }

    private function range(): string
    {
        return sprintf('da id %d a id %d', $this->poiIds[0] ?? 0, $this->poiIds[count($this->poiIds) - 1] ?? 0);
    }
}
