<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * La traccia agganciata dal link ha un codice attivo, ma numero o variante
 * non coincidono con quanto scritto sul foglio (oc:8539).
 */
class NumeroDiverso implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Registry number different from the trail registry');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];

        return [
            ...$this->commonRows($anomaly),
            [__('Trail registry code'), e((string) ($context['code'] ?? ''))],
            [__('Sheet code'), e((string) ($context['sheet_code'] ?? ''))],
        ];
    }
}
