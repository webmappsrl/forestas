<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EcTrack;
use App\Support\RelatedTrackCards;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Scout\ModelObserver;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\TaxonomyTermable;

/**
 * Aggiorna le card dei sentieri correlati quando a una traccia si attacca o si toglie un termine
 * di «Tipo percorso» (oc:8959), il solo termine che la card mostra.
 */
class RelatedTrackRouteTypeObserver
{
    private const ROUTE_TYPE_TAXONOMY = 'route-type';

    public function saved(TaxonomyTermable $termable): void
    {
        $this->refreshIfRouteType($termable);
    }

    public function deleted(TaxonomyTermable $termable): void
    {
        $this->refreshIfRouteType($termable);
    }

    private function refreshIfRouteType(TaxonomyTermable $termable): void
    {
        // La morphMap del package risolve `App\Models\EcTrack` nella classe del package: si
        // confronta con quella, che è anche la madre del modello di forestas.
        $class = Relation::getMorphedModel($termable->termable_type) ?? $termable->termable_type;
        if (! is_a($class, WmEcTrack::class, true)) {
            return;
        }

        // La leva con cui un import silenzia gli observer dei termini, come nel package.
        if (ModelObserver::syncingDisabledFor(EcTrack::class)) {
            return;
        }

        // Dal termine e non da `parent_id` della riga: un detach crea il pivot con le sole chiavi.
        $parentId = TaxonomyTerm::query()->whereKey($termable->taxonomy_term_id)->value('parent_id');
        $taxonomy = $parentId === null ? null : TaxonomyTerm::query()->whereKey($parentId)->value('identifier');
        if ($taxonomy === self::ROUTE_TYPE_TAXONOMY) {
            RelatedTrackCards::refresh((int) $termable->termable_id);
        }
    }
}
