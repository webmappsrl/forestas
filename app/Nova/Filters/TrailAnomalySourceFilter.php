<?php

namespace App\Nova\Filters;

use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Filtra le anomalie per provenienza (oc:8539): quelle del Catasto Sentieri
 * o quelle del registro catastale (mirror del foglio Google). Le due
 * provenienze si correggono in due posti diversi — la scheda del sentiero, o
 * il foglio alla fonte — e senza questo filtro restano mischiate nello
 * stesso elenco.
 */
class TrailAnomalySourceFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Source');
    }

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('source', $value);
    }

    public function options(NovaRequest $request): array
    {
        return [
            __('Catasto') => TrailRegistryAnomaly::SOURCE_CATASTO,
            __('Registro') => RegistroAnomalyTypes::SOURCE,
        ];
    }
}
