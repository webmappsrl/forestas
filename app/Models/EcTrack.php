<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
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

    public function taxonomyWarnings(): MorphToMany
    {
        return $this->morphToMany(TaxonomyWarning::class, 'taxonomy_warningable')
            ->using(TaxonomyWarningable::class);
    }

    public function enti(): MorphToMany
    {
        return $this->morphToMany(Ente::class, 'enteable')
            ->using(Enteable::class)
            ->withPivot('ruolo');
    }
}
