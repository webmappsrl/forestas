<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaxonomyWarningable extends MorphPivot
{
    protected $table = 'taxonomy_warningables';

    public $incrementing = true;

    public function model(): MorphTo
    {
        return $this->morphTo('taxonomy_warningable');
    }

    public function warning(): BelongsTo
    {
        return $this->belongsTo(TaxonomyWarning::class, 'taxonomy_warning_id');
    }
}
