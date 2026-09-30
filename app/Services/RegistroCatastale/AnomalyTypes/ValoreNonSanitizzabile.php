<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Una cella di lunghezza o tempi del registro che non si legge, o che chi
 * l'ha scritta segna come dubbia: non si scrive sul sentiero, vale il DEM
 * finche' Forestas non la corregge sul foglio (oc:8540).
 */
class ValoreNonSanitizzabile implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Valore del registro non interpretabile');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];

        return [
            ...$this->commonRows($anomaly),
            [__('Colonna'), e((string) ($context['column'] ?? ''))],
            [__('Valore'), e((string) ($context['value'] ?? ''))],
        ];
    }
}
