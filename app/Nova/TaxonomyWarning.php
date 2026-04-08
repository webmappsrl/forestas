<?php

declare(strict_types=1);

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractTaxonomyResource;

class TaxonomyWarning extends AbstractTaxonomyResource
{
    public static $model = \App\Models\TaxonomyWarning::class;

    public static $title = 'name';

    public static function label(): string
    {
        return 'Taxonomy Warnings';
    }

    public static function singularLabel(): string
    {
        return 'Taxonomy Warning';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            MorphToMany::make('Tracks Associati', 'ecTracks', EcTrack::class)
                ->display('name')
                ->help('Track associati a questo warning'),
        ];
    }
}
