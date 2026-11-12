<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EcTrack;
use App\Support\RelatedTrackCards;

/**
 * Aggiorna le card dei sentieri correlati quando cambia una traccia (oc:8959): solo se è cambiato
 * un dato che la card mostra (`RelatedTrackCards::signature()`), e prima che la cancellazione
 * porti via in cascata le righe dei correlati.
 */
class RelatedTrackCardObserver
{
    public function updated(EcTrack $track): void
    {
        // In `updated` l'originale non è ancora allineato: è la traccia prima del salvataggio.
        $before = (clone $track)->setRawAttributes($track->getRawOriginal(), true);

        if (RelatedTrackCards::signature($before) !== RelatedTrackCards::signature($track)) {
            RelatedTrackCards::refresh((int) $track->id);
        }
    }

    public function deleting(EcTrack $track): void
    {
        $viewers = RelatedTrackCards::viewersOf((int) $track->id);
        if ($viewers !== []) {
            RelatedTrackCards::refresh((int) $track->id, $viewers);
        }
    }
}
