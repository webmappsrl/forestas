<?php

namespace App\Nova;

use App\Nova\Filters\TaxonomyVocabularyFilter;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;
use Wm\WmPackage\Nova\TaxonomyActivity as WmTaxonomyActivity;

class TaxonomyActivity extends WmTaxonomyActivity
{
    public static function label(): string
    {
        return __('Activities');
    }

    public static function singularLabel(): string
    {
        return __('Activity');
    }

    public function filters(NovaRequest $request): array
    {
        return [new TaxonomyVocabularyFilter(\Wm\WmPackage\Models\TaxonomyActivity::class)];
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Text::make('Source ID', 'properties->source_id')->readonly()->hideFromIndex(),
            Text::make('Vocabulary', 'properties->vocabulary')->readonly()->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        $model = $request->findModelOrFail();
        $sourceId = data_get($model->properties, 'source_id');
        $vocabulary = data_get($model->properties, 'vocabulary');

        if (! $sourceId) {
            return [];
        }

        $card = new ApiLinksCard([]);
        $card->addLink('Sardegna Sentieri (termine)', 'https://www.sardegnasentieri.it/taxonomy/term/' . $sourceId . '?_format=json');
        if ($vocabulary) {
            $card->addLink('Sardegna Sentieri (vocabolario)', 'https://www.sardegnasentieri.it/ss/tassonomia/' . $vocabulary . '?_format=json');
        }

        return [$card];
    }
}
