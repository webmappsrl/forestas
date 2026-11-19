<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Nova\Filters\ChoiceFilter;
use Wm\WmPackage\Services\IconSvgService;
use Wm\WmPackage\Support\TaxonomyTermOptions;

/**
 * Filtro delle EC Tracce per attività, a scelta multipla, sul selettore del campo `Choice` come
 * gli altri filtri della Resource (oc:8988).
 *
 * Le attività stanno ancora in `taxonomy_activities`, non in `taxonomy_terms`: per questo il filtro
 * non è un `TaxonomyTermFilter`. Il valore è l'identifier dell'attività, stabile fra ambienti
 * perché finisce nell'URL dell'index; più attività scelte valgono in OR.
 */
class EcTrackActivityFilter extends ChoiceFilter
{
    public function __construct()
    {
        $this->multiple();
    }

    public function name(): string
    {
        return __('Activity');
    }

    /**
     * Solo identifier di attività esistenti. Se non ne resta nessuno — un link salvato con
     * un'attività rinominata o tolta — il risultato è vuoto: un filtro non allarga mai i risultati.
     *
     * @param  list<string>|string  $value  identifier delle attività scelte
     */
    protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder
    {
        $valid = TaxonomyActivity::query()
            ->whereIn('identifier', (array) $value)
            ->pluck('identifier')
            ->all();

        if ($valid === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'taxonomyActivities',
            fn ($activities) => $activities->whereIn('taxonomy_activities.identifier', $valid)
        );
    }

    /**
     * Le attività nell'ordine dei dati, con l'icona del form quando c'è.
     *
     * @return list<array{value: string, label: string, svg?: string}>
     */
    protected function choiceOptions(NovaRequest $request): array
    {
        $icons = new IconSvgService;

        return TaxonomyActivity::query()
            ->orderBy('id')
            ->get()
            ->map(function (TaxonomyActivity $activity) use ($icons) {
                $option = ['value' => (string) $activity->identifier, 'label' => self::label($activity)];
                $svg = TaxonomyTermOptions::iconSvg($icons, $activity->getAttribute('icon'));
                if ($svg !== null) {
                    $option['svg'] = $svg;
                }

                return $option;
            })
            ->values()
            ->all();
    }

    /**
     * Nome nella lingua corrente; se manca, la prima traduzione disponibile e, in ultimo,
     * l'identifier: un'opzione senza etichetta non si può scegliere.
     */
    private static function label(TaxonomyActivity $activity): string
    {
        $name = $activity->getTranslation('name', app()->getLocale(), true);
        if ($name === '' || $name === null) {
            $name = collect($activity->getTranslations('name'))->filter()->first();
        }

        return (string) ($name ?: $activity->identifier);
    }
}
