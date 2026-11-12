<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\RefreshRelatedTrackCardsJob;
use App\Models\EcTrack;
use Illuminate\Support\Facades\DB;

/**
 * Le card dei sentieri correlati nel file di ogni traccia (`properties.forestas.nearby_tracks`,
 * oc:8959) copiano nome, codice, tipo, prima foto, tempo e lunghezza delle tracce vicine. Quando
 * uno di questi dati cambia fuori dall'import, `RefreshRelatedTrackCardsJob` riscrive i file
 * delle sole tracce che mostrano quella cambiata.
 *
 * L'import non passa di qui: salva le tracce senza eventi, scarica le foto dentro
 * `withoutRefresh()`, e a fine giro rigenera comunque tutti i file.
 */
final class RelatedTrackCards
{
    private static int $suppressed = 0;

    /**
     * Esegue $callback senza accodare aggiornamenti delle card: per chi scrive molti dati e poi
     * rigenera tutto da sé, come l'import.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRefresh(callable $callback): mixed
    {
        self::$suppressed++;
        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    /**
     * Accoda l'aggiornamento dei file che mostrano la traccia, dopo il commit: Nova salva dentro
     * una transazione. $viewerIds serve alla cancellazione, quando le righe dei correlati sono già
     * sparite in cascata.
     *
     * @param  list<int>|null  $viewerIds
     */
    public static function refresh(int $trackId, ?array $viewerIds = null): void
    {
        if (self::$suppressed > 0) {
            return;
        }

        RefreshRelatedTrackCardsJob::dispatch($trackId, $viewerIds)->afterCommit();
    }

    /**
     * Le tracce che mostrano $trackId fra i propri correlati.
     *
     * @return list<int>
     */
    public static function viewersOf(int $trackId): array
    {
        return DB::table('ec_track_related_tracks')
            ->where('related_track_id', $trackId)
            ->orderBy('ec_track_id')
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * I dati della traccia che la card mostra e che stanno nelle sue colonne: nome, codice,
     * lunghezza e tempo con la precedenza del dettaglio (manuale, OSM, DEM). Foto e tipo di
     * percorso stanno in altre tabelle e hanno i loro observer.
     *
     * @return array<string, mixed>
     */
    public static function signature(EcTrack $track): array
    {
        return [
            'name' => $track->getTranslations('name'),
            'ref' => $track->properties['ref'] ?? null,
            'distance' => $track->classifyField($track, 'distance')['currentValue'],
            'duration_forward' => $track->classifyField($track, 'duration_forward')['currentValue'],
        ];
    }
}
