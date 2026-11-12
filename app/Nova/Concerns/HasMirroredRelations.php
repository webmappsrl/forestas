<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Models\Ente as EnteModel;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\EcPoi as WmEcPoiModel;
use Wm\WmPackage\Models\EcTrack as WmEcTrackModel;

/**
 * I collegamenti fra contenuti non si modificano da Nova (oc:8932).
 *
 * Punti di interesse correlati, percorsi correlati ed enti vengono interamente da Drupal, e i
 * percorsi vicini sono calcolati dalla geometria: l'import riscrive gli uni e gli altri a ogni
 * giro con un `sync()`, quindi un collegamento aggiunto o tolto a mano sparirebbe al giro dopo
 * senza che nessuno capisca perche'. Un'interfaccia che permette un'azione destinata a sparire e'
 * peggio di una che non la permette.
 *
 * Sta in un trait e non in una Resource perche' la regola vale per entrambi i capi del
 * collegamento: implementata sul solo percorso, dalla scheda del punto di interesse si poteva
 * ancora collegare un sentiero a mano.
 */
trait HasMirroredRelations
{
    public function authorizedToAttachAny(NovaRequest $request, $model)
    {
        return ! $this->isMirroredRelation($model) && parent::authorizedToAttachAny($request, $model);
    }

    public function authorizedToAttach(NovaRequest $request, $model)
    {
        return ! $this->isMirroredRelation($model) && parent::authorizedToAttach($request, $model);
    }

    public function authorizedToDetach(NovaRequest $request, $model, $relationship)
    {
        return ! $this->isMirroredRelation($model) && parent::authorizedToDetach($request, $model, $relationship);
    }

    /**
     * I modelli il cui collegamento e' uno specchio della sorgente.
     *
     * Si guarda il tipo del modello collegato, non il nome della relazione: le Resource EC di
     * forestas non hanno nessun'altra relazione verso questi tre modelli.
     */
    private function isMirroredRelation(mixed $model): bool
    {
        return is_a($model, WmEcTrackModel::class, true)
            || is_a($model, WmEcPoiModel::class, true)
            || is_a($model, EnteModel::class, true);
    }
}
