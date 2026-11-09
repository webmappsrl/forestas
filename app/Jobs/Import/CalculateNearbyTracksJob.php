<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcola dalla geometria i sentieri correlati (oc:8932).
 *
 * Due percorsi dell'app di import sono correlati se distano al massimo
 * `sardegnasentieri.nearby_tracks_radius_meters` metri. Il calcolo sostituisce
 * l'import dei correlati di Drupal: quelli erano contiguita', e un dato
 * calcolato vale anche per i sentieri creati dopo lo spegnimento di Drupal.
 *
 * Il lavoro e' a blocchi di `sardegnasentieri.nearby_tracks_chunk_size` tracce
 * (ordinate per id): ogni esecuzione sta largamente dentro il timeout della
 * coda, poi il job si riaccoda per il blocco successivo. Solo il primo blocco
 * svuota la tabella, e il blocco successivo si accoda prima del lavoro.
 * La vicinanza e' simmetrica, quindi ogni coppia e' scritta in entrambe le direzioni. Si cicla sulle tracce perche' un join a coppie non
 * e' piu' veloce e non si presta a essere spezzato.
 */
class CalculateNearbyTracksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const OVERLAP_KEY = 'sardegnasentieri-nearby-tracks';

    /**
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import` (360 in
     * config/horizon.php), come gli altri job della coda di import.
     */
    public int $timeout = 300;

    /**
     * @param  int  $afterId  elabora le tracce con id maggiore di questo; 0 = primo blocco
     */
    public function __construct(public int $afterId = 0)
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Mai due esecuzioni dello stesso blocco insieme: `retry_after` della connessione redis (90 s)
     * puo' far ri-riservare da un secondo worker un blocco ancora in corso.
     *
     * La chiave e' per blocco (`afterId`) e non unica: il blocco successivo si accoda PRIMA del
     * lavoro, e con una chiave comune un altro worker lo troverebbe subito bloccato e, con
     * `dontRelease()`, lo scarterebbe, interrompendo la catena. Blocchi diversi scrivono righe
     * diverse con `insertOrIgnore`, quindi possono girare insieme.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY.'-'.$this->afterId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $radius = (float) config('sardegnasentieri.nearby_tracks_radius_meters', 50);
        $chunkSize = max(1, (int) config('sardegnasentieri.nearby_tracks_chunk_size', 50));
        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        $ids = DB::table('ec_tracks')
            ->where('app_id', $appId)
            ->whereNotNull('geometry')
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit($chunkSize)
            ->pluck('id');

        // Il primo blocco svuota la tabella subito, prima di accodare il successivo: dopo,
        // i blocchi seguenti potrebbero gia' aver scritto le loro righe.
        // Solo al primo tentativo: un nuovo tentativo del primo blocco (la coda ne concede
        // fino a 5) non deve cancellare le righe scritte nel frattempo dai blocchi seguenti.
        if ($this->afterId === 0 && $this->attempts() <= 1) {
            DB::table('ec_track_related_tracks')->delete();
        }

        // Il blocco successivo si accoda PRIMA del lavoro: se questo blocco muore a meta'
        // (timeout, worker riavviato) la catena non si interrompe e il buco e' un solo blocco,
        // tracciato da failed(). Le scritture sono idempotenti (insertOrIgnore).
        $isFull = $ids->count() === $chunkSize;
        if ($isFull) {
            self::dispatch((int) $ids->last());
        }

        $rows = [];
        foreach ($ids as $id) {
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

        $next = $isFull ? (string) $ids->last() : 'fine';
        Log::channel('import')->info(sprintf(
            '[sardegnasentieri] Sentieri vicini: blocco dopo id %d, %d tracce, %d righe entro %s m, prossimo blocco da id %s.',
            $this->afterId,
            $ids->count(),
            count($rows),
            $radius,
            $next
        ));
    }

    /**
     * Un blocco saltato lascia una traccia: i blocchi successivi sono gia' accodati e girano.
     * Per rifare il blocco perso: `CalculateNearbyTracksJob::dispatch(<afterId>)` da tinker
     * (riaccoda anche i successivi, innocuo perche' le scritture sono idempotenti).
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('import')->error(sprintf(
            '[sardegnasentieri] Sentieri vicini: blocco dopo id %d saltato (%s). Il calcolo risulta incompleto per quelle tracce.',
            $this->afterId,
            $exception?->getMessage() ?? 'errore sconosciuto'
        ));
    }
}
