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
 * Un blocco del calcolo dei sentieri correlati: per ogni traccia del blocco scrive le coppie con le
 * tracce di id maggiore entro il raggio. Lo accoda `CalculateNearbyTracksJob` in un batch, che ha
 * gia' svuotato la tabella: le scritture sono idempotenti (`insertOrIgnore`).
 */
class CalculateNearbyTracksChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public const OVERLAP_KEY = 'sardegnasentieri-nearby-tracks';

    /**
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import` (360 in
     * config/horizon.php), come gli altri job della coda di import.
     */
    public int $timeout = 300;

    /**
     * @param  list<int>  $trackIds  le tracce del blocco, in ordine di id
     */
    public function __construct(public array $trackIds)
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Mai due esecuzioni dello stesso blocco insieme: `retry_after` della connessione redis (90 s)
     * puo' far ri-riservare da un secondo worker un blocco ancora in corso. La chiave e' per
     * blocco, cosi' blocchi diversi girano in parallelo.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY.'-'.($this->trackIds[0] ?? 0)))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $radius = (float) config('sardegnasentieri.nearby_tracks_radius_meters', 50);

        $rows = [];
        foreach ($this->trackIds as $id) {
            // La colonna e' gia' `geography`, quindi il raggio e' in metri anche senza cast.
            // Il cast resta per rendere esplicita l'unita' e proteggere dal caso in cui qualcuno
            // lo cambi in `::geometry` o cambi il tipo della colonna: il raggio diventerebbe gradi.
            $neighbours = DB::select(
                <<<'SQL'
                SELECT b.id
                FROM ec_tracks a
                JOIN ec_tracks b
                  ON b.id > a.id
                 AND b.app_id = a.app_id
                 AND ST_DWithin(b.geometry::geography, a.geometry::geography, :radius)
                WHERE a.id = :id
                SQL,
                ['radius' => $radius, 'id' => $id]
            );

            // La vicinanza e' simmetrica: ogni coppia si scrive nei due versi.
            foreach ($neighbours as $row) {
                $rows[] = ['ec_track_id' => $id, 'related_track_id' => $row->id];
                $rows[] = ['ec_track_id' => $row->id, 'related_track_id' => $id];
            }
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('ec_track_related_tracks')->insertOrIgnore($chunk);
            }
        });

        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Sentieri vicini: blocco %s, %d tracce, %d righe entro %s m.',
            $this->range(),
            count($this->trackIds),
            count($rows),
            $radius
        ));
    }

    /**
     * Un blocco saltato lascia una traccia; il batch arriva comunque alla rigenerazione. Per
     * rifarlo: `CalculateNearbyTracksChunkJob::dispatch([<id>, …])` da tinker, poi
     * `CalculateNearbyTracksJob::regenerateOutputs()`.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('import')->error(sprintf(
            '[sardegnasentieri] Sentieri vicini: blocco %s saltato (%s). Il calcolo risulta incompleto per quelle tracce.',
            $this->range(),
            $exception?->getMessage() ?? 'errore sconosciuto'
        ));
    }

    private function range(): string
    {
        return sprintf('da id %d a id %d', $this->trackIds[0] ?? 0, $this->trackIds[array_key_last($this->trackIds) ?? 0] ?? 0);
    }
}
