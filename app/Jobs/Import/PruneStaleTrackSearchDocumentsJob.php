<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Search\TrackSearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Toglie dall'indice delle tracce le voci il cui id non è più una traccia (oc:8999).
 *
 * Il reset notturno tronca `ec_tracks` con `RESTART IDENTITY` e il reindex di fine import
 * (`RegenerateTaxonomyWhereOutputsJob` del package) riscrive solo le tracce che esistono: quando le
 * tracce calano, per esempio perché le bozze di Drupal non si importano più, le voci con gli id in
 * più restavano con i dati della notte prima e comparivano nella lista come doppioni. L'indice non
 * si svuota né si ricrea, quindi la lista non resta mai vuota.
 *
 * Gli id dell'indice si leggono prima di quelli del DB: una traccia creata in mezzo è già nel DB
 * quando si fa la differenza, e non si cancella. L'ordine rispetto al reindex non conta, perché il
 * reindex scrive solo id che esistono.
 */
class PruneStaleTrackSearchDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(TrackSearchIndex $index): void
    {
        $indexed = $index->documentIds();

        $model = $index->trackModel();
        $existing = $model::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $stale = array_values(array_diff($indexed, $existing));
        if ($stale !== []) {
            $index->delete($stale);
        }

        Log::channel('import')->info('[sardegnasentieri] Indice delle tracce: tolte '.count($stale).' voci senza traccia.');
    }

    public function failed(Throwable $e): void
    {
        Log::channel('import')->error('[sardegnasentieri] Pulizia dell\'indice delle tracce fallita: '.$e->getMessage());
    }
}
