<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Enteable extends MorphPivot
{
    protected $table = 'enteables';

    public $incrementing = true;

    public function model(): MorphTo
    {
        return $this->morphTo('enteable');
    }

    public function ente(): BelongsTo
    {
        return $this->belongsTo(Ente::class, 'ente_id');
    }
}
