<?php

namespace App\Nova;

use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as WmTrailApplication;

// Sottoclasse vuota richiesta dal package (oc:8539): il package non
// registra piu' le Resource del Catasto, ma continua a cercarle per
// uriKey (fisso nella classe base, invariato qui).
class TrailApplication extends WmTrailApplication {}
