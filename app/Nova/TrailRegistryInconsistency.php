<?php

declare(strict_types=1);

namespace App\Nova;

use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryInconsistency as WmTrailRegistryInconsistency;

/**
 * Le incongruenze catastali di Forestas (oc:8989).
 *
 * Non aggiunge niente a quella del package: esiste perché le Resource del Catasto le registra
 * lo shard con le proprie sottoclassi, e perché un domani Forestas possa aggiungere qui le sue
 * colonne senza toccare il package.
 */
class TrailRegistryInconsistency extends WmTrailRegistryInconsistency {}
