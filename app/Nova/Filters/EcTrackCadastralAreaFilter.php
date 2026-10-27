<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Outl1ne\NovaMultiselectFilter\MultiselectFilter;
use Wm\WmPackage\Models\TaxonomyWhere;

/**
 * Filtro delle EC Tracce per area catastale, a scelta multipla (oc:8806), come multiselect con
 * ricerca (oc:8825).
 *
 * Legge properties->taxonomy_where, scritto dal calcolo geometrico delle aree: una traccia
 * esce se attraversa almeno una delle aree scelte, anche per un tratto breve.
 */
class EcTrackCadastralAreaFilter extends MultiselectFilter
{
    public function __construct()
    {
        // Senza segnaposto il componente ripete il nome del filtro nella casella di ricerca.
        $this->placeholder(__('Select cadastral area'));
    }

    public function name(): string
    {
        return __('Cadastral areas');
    }

    /**
     * @param  list<int|string>|mixed  $value  id delle aree scelte
     */
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        $areaIds = collect(is_array($value) ? $value : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->map(fn (int $id) => (string) $id)
            ->unique()
            ->values();

        // Nessuna area scelta: nessuna restrizione.
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
     * Formato del pacchetto: id => nome (l'inverso del BooleanFilter di Nova).
     *
     * @return array<int, string>
     */
    public function options(NovaRequest $request): array
    {
        return TaxonomyWhere::query()
            ->where('properties->source', TaxonomyWhereLayersImportService::SOURCE_KEY)
            ->get()
            ->sortBy(fn (TaxonomyWhere $area) => $area->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (TaxonomyWhere $area) => [$area->id => $area->name])
            ->all();
    }
}
