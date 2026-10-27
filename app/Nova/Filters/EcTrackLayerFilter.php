<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;

/**
 * Filtro dei layer delle EC Tracce senza le aree catastali, che hanno un filtro
 * a parte (oc:8806). key() resta quella del package.
 *
 * apply() restituisce le tracce del layer, non le candidate di onLayer() (oc:8825):
 * un layer senza attività né aree, con onLayer(), restituirebbe tutte le tracce.
 */
class EcTrackLayerFilter extends FeaturesByLayerFilter
{
    /**
     * Layer in modalità manuale → tracce associate. Layer in automatico con un filtro
     * (attività o aree) → onLayer(): per lui la tabella layerables è una copia del filtro,
     * aggiornata da un job in differita, e leggerla darebbe risultati vecchi dopo un import.
     * Layer in automatico senza filtro ma con tracce associate → tracce associate.
     * Altrimenti → onLayer().
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $layer = Layer::find($value);
        if (! $layer) {
            return $query;
        }

        if (! $layer->isAutoTrackMode()) {
            return $this->associatedTracks($query, $layer);
        }

        if ($this->hasAutoFilter($layer)) {
            return parent::apply($request, $query, $value);
        }

        if ($layer->ecTracks()->exists()) {
            return $this->associatedTracks($query, $layer);
        }

        return parent::apply($request, $query, $value);
    }

    /**
     * Senza name() Nova ricaverebbe l'etichetta dal nome della classe; quella del package era
     * «Features By Layer Filter», in inglese anche per l'utente italiano (oc:8875).
     */
    public function name(): string
    {
        return __('Layer');
    }

    /**
     * Solo i layer non legati a un'area catastale: un layer legato a una TaxonomyWhere
     * di altra sorgente resta una tipologia.
     *
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return Layer::query()
            ->whereDoesntHave('taxonomyWheres', fn ($query) => $query->where(
                'properties->source',
                TaxonomyWhereLayersImportService::SOURCE_KEY
            ))
            ->get()
            ->mapWithKeys(fn (Layer $layer) => [$layer->name => (string) $layer->id])
            ->all();
    }

    private function associatedTracks(Builder $query, Layer $layer): Builder
    {
        return $query->whereHas('layers', fn ($q) => $q->where('layers.id', $layer->id));
    }

    /**
     * Stessa condizione di LayerService::hasValidAutoModeFilter(), privata nel package.
     */
    private function hasAutoFilter(Layer $layer): bool
    {
        return ! empty($layer->properties['taxonomy_where'] ?? [])
            || $layer->taxonomyActivities()->exists();
    }
}
