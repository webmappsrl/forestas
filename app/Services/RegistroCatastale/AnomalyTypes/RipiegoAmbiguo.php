<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Il ripiego su area + settore + numero + variante trova piu' di un codice
 * candidato: senza il link non si sceglie a caso quale sia quello giusto
 * (oc:8539).
 */
class RipiegoAmbiguo implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Più codici candidati per lo stesso numero del registro');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];
        $candidates = is_array($context['candidates'] ?? null) ? $context['candidates'] : [];

        return [
            ...$this->commonRows($anomaly),
            [__('Codici candidati'), e(implode(', ', $candidates))],
        ];
    }
}
