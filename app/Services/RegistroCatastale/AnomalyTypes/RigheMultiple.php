<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Piu' righe del registro cadono sullo stesso codice (di solito tratti
 * diversi dello stesso numero): il sentiero non riceve valori finche' il
 * foglio non viene sistemato (oc:8540).
 */
class RigheMultiple implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Più righe del registro per lo stesso codice');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $rows = is_array($anomaly->context['rows'] ?? null) ? $anomaly->context['rows'] : [];

        $labels = array_map(
            fn ($r) => __(':sheet · row :row', ['sheet' => (string) ($r['sheet'] ?? ''), 'row' => (string) ($r['row'] ?? '')]),
            array_filter($rows, 'is_array'),
        );

        return [
            ...$this->commonRows($anomaly),
            [__('Righe'), e(implode(', ', $labels))],
        ];
    }
}
