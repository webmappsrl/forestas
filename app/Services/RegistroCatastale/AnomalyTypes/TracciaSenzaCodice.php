<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * La traccia agganciata dal link non ha un codice attivo, e non ha gia'
 * un'anomalia del Catasto che lo segnali (oc:8539).
 */
class TracciaSenzaCodice implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Traccia del registro senza codice attivo');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        return $this->commonRows($anomaly);
    }
}
