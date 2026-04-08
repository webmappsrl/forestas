<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wm\WmPackage\Models\Abstracts\Taxonomy;

class TaxonomyWarning extends Taxonomy
{
    protected function getRelationKey(): string
    {
        return 'warningable';
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'taxonomy_warningable')
            ->using(TaxonomyWarningable::class);
    }
}
