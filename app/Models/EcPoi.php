<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;

class EcPoi extends WmEcPoi
{
    public function relatedPois(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ec_poi_related_pois',
            'ec_poi_id',
            'related_poi_id'
        );
    }
}
