<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EcTrack;
use App\Support\RelatedTrackCards;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

/**
 * Riscrive su AWS i file delle tracce che mostrano $trackId fra i sentieri correlati (oc:8959).
 *
 * Unico per traccia finché non parte: un salvataggio in Nova che cambia nome e foto, più le
 * conversioni della foto appena caricata, accodano un job solo. Ogni file lo riscrive
 * `UpdateEcTrackAwsJob`, come per la traccia stessa.
 */
class RefreshRelatedTrackCardsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<int>|null  $viewerIds  le tracce da riscrivere, se già note (cancellazione)
     */
    public function __construct(public int $trackId, public ?array $viewerIds = null)
    {
        $this->onQueue('aws');
    }

    public function uniqueId(): string
    {
        // La cancellazione ha un id suo: un aggiornamento in coda della stessa traccia, partito
        // dopo la cancellazione, non troverebbe più le righe dei correlati.
        return 'related-track-cards-'.$this->trackId.($this->viewerIds === null ? '' : '-deleted');
    }

    /**
     * Lock su redis e non sullo store di default: il job si accoda dentro la transazione di Nova,
     * e con `CACHE_STORE=database` il lock la farebbe fallire su Postgres (trappola del package,
     * oc:8564).
     */
    public function uniqueVia()
    {
        return Cache::store('redis');
    }

    public function handle(): void
    {
        $ids = $this->viewerIds ?? RelatedTrackCards::viewersOf($this->trackId);
        if ($ids === []) {
            return;
        }

        foreach (EcTrack::query()->whereKey($ids)->get() as $track) {
            UpdateEcTrackAwsJob::dispatch($track);
        }
    }
}
