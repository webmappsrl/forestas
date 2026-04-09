<?php

declare(strict_types=1);

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractTaxonomyResource;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;

class TaxonomyWarning extends AbstractTaxonomyResource
{
    public static $model = \App\Models\TaxonomyWarning::class;

    public static $title = 'name';

    public static function label(): string
    {
        return __('Warnings');
    }

    public static function singularLabel(): string
    {
        return __('Warning');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Text::make('Source ID', 'properties->source_id')->readonly()->hideFromIndex(),
            Text::make('Vocabulary', 'properties->vocabulary')->readonly()->hideFromIndex(),
            MorphToMany::make('Tracks Associati', 'ecTracks', EcTrack::class)
                ->display('name')
                ->help('Track associati a questo warning'),
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        $model = $request->findModelOrFail();
        $sourceId = data_get($model->properties, 'source_id');

        if (! $sourceId) {
            return [];
        }

        $card = new ApiLinksCard([]);
        $card->addLink('Sardegna Sentieri (termine)', 'https://www.sardegnasentieri.it/taxonomy/term/' . $sourceId . '?_format=json');
        $card->addLink('Sardegna Sentieri (vocabolario)', 'https://www.sardegnasentieri.it/ss/tassonomia/tipologia_di_avvertenze?_format=json');

        return [$card];
    }
}
