<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;

/**
 * Filtro dei layer delle EC Tracce senza le aree catastali, che hanno un filtro
 * a parte (oc:8806). apply() e key() restano quelli del package.
 */
class EcTrackLayerFilter extends FeaturesByLayerFilter
{
    /**
     * Stessa etichetta del filtro del package: Nova la ricaverebbe dal nome della classe.
     */
    public function name(): string
    {
        return 'Features By Layer Filter';
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
}
