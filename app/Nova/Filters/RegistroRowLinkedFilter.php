<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Righe agganciate o no a un codice del catasto (oc:8700). Il motivo di una
 * riga senza codice — numero prenotato, anomalia, righe multiple — non sta
 * qui: lo dice la lista delle anomalie, e duplicarlo vorrebbe dire tenere due
 * elenchi allineati.
 */
class RegistroRowLinkedFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Linked to a code');
    }

    public function apply(NovaRequest $request, $query, $value)
    {
        return $value === 'yes'
            ? $query->whereNotNull('trail_registry_code_id')
            : $query->whereNull('trail_registry_code_id');
    }

    public function options(NovaRequest $request): array
    {
        return [__('Yes') => 'yes', __('No') => 'no'];
    }
}
