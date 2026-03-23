<?php

namespace App\Models;

use Wm\WmPackage\Models\EcTrack as WmEcTrack;

class EcTrack extends WmEcTrack
{
    protected $fillable = [
        'name',
        'geometry',
        'app_id',
        'properties',
        'user_id',
        'osmid',
        'stato_validazione',
    ];
}
