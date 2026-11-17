<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Nova\Filters\ChoiceFilter;

/**
 * Filtro delle EC Tracce per area catastale, a scelta multipla (oc:8806), con ricerca (oc:8825),
 * sul selettore del campo `Choice` come gli altri filtri della Resource (oc:8988).
 *
 * Legge properties->taxonomy_where, scritto dal calcolo geometrico delle aree: una traccia
 * esce se attraversa almeno una delle aree scelte, anche per un tratto breve.
 *
 * La chiave resta quella di Nova, il nome della classe, e il valore un array di id, come con il
 * vecchio filtro outline: i link salvati con le aree continuano a filtrare.
 */
class EcTrackCadastralAreaFilter extends ChoiceFilter
{
    public function __construct()
    {
        // Il segnaposto della ricerca è quello di default, come negli altri filtri del menu.
        $this->multiple();
    }

    public function name(): string
    {
        return __('Cadastral areas');
    }

    /**
     * @param  list<string>|string  $value  id delle aree scelte
     */
    protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder
    {
        $areaIds = collect((array) $value)
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
     * Le aree per nome, in ordine naturale. L'id resta intero: lo converte in stringa
     * `ChoiceFilter`.
     *
     * @return list<array{value: int, label: string}>
     */
    protected function choiceOptions(NovaRequest $request): array
    {
        return TaxonomyWhere::query()
            ->where('properties->source', TaxonomyWhereLayersImportService::SOURCE_KEY)
            ->get()
            ->sortBy(fn (TaxonomyWhere $area) => $area->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (TaxonomyWhere $area) => ['value' => $area->id, 'label' => (string) $area->name])
            ->values()
            ->all();
    }
}
