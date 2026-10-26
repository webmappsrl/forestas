<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\BooleanFilter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyWhere;

/**
 * Filtro delle EC Tracce per area catastale, a scelta multipla (oc:8806).
 *
 * Legge properties->taxonomy_where, scritto dal calcolo geometrico delle aree: una traccia
 * esce se attraversa almeno una delle aree spuntate, anche per un tratto breve.
 */
class EcTrackCadastralAreaFilter extends BooleanFilter
{
    public function name(): string
    {
        return __('Cadastral areas');
    }

    /**
     * @param  array<int|string, bool>|mixed  $value  id dell'area => spuntata
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $areaIds = collect(is_array($value) ? $value : [])
            ->filter()
            ->keys()
            ->map(fn ($id) => (string) (int) $id)
            ->unique()
            ->values();

        // Nova chiama apply() anche con tutte le caselle a false: nessuna restrizione.
        if ($areaIds->isEmpty()) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        // La sola chiave non basta: collide con gli osm2cai_id di altre TaxonomyWhere. La
        // sorgente si legge in tutte e due le forme del package: _source (oc:8588) e
        // source (oc:8487).
        return $query->where(function ($query) use ($areaIds, $table) {
            foreach ($areaIds as $areaId) {
                $query->orWhereRaw(
                    "jsonb_exists({$table}.properties->'taxonomy_where', ?)
                    AND COALESCE(
                        {$table}.properties->'taxonomy_where'->?->>'_source',
                        {$table}.properties->'taxonomy_where'->?->>'source'
                    ) = ?",
                    [$areaId, $areaId, $areaId, TaxonomyWhereLayersImportService::SOURCE_KEY]
                );
            }
        });
    }

    /**
     * @return array<string, int>
     */
    public function options(NovaRequest $request): array
    {
        return TaxonomyWhere::query()
            ->where('properties->source', TaxonomyWhereLayersImportService::SOURCE_KEY)
            ->get()
            ->sortBy(fn (TaxonomyWhere $area) => $area->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (TaxonomyWhere $area) => [$area->name => $area->id])
            ->all();
    }
}
