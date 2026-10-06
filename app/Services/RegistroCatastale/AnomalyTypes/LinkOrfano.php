<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Il link della riga non porta a nessuna traccia, e il ripiego sul numero
 * non basta o non c'e' (oc:8539).
 */
class LinkOrfano implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Registry link with no matching track');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        return $this->commonRows($anomaly);
    }
}
