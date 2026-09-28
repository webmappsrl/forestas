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
        return __('Numero del registro diverso da quello del catasto');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];

        return [
            ...$this->commonRows($anomaly),
            [__('Codice del catasto'), e((string) ($context['code'] ?? ''))],
            [__('Codice del foglio'), e((string) ($context['sheet_code'] ?? ''))],
        ];
    }
}
