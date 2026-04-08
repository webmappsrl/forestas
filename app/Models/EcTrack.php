<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;

class EcTrack extends WmEcTrack
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->translatable = array_merge($this->translatable ?? [], [
            'properties->forestas->info_utili',
            'properties->forestas->roadbook',
        ]);
    }

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
