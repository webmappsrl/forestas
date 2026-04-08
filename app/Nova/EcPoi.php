<?php

declare(strict_types=1);

namespace App\Nova;

use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Text;
use Marshmallow\Tiptap\Tiptap;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public static $model = \App\Models\EcPoi::class;

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        $poi = $request->findModelOrFail();
        $sourceId = data_get($poi->properties, 'out_source_feature_id');
        $htmlUrl = data_get($poi->properties, 'forestas.url');

        if (! $sourceId && ! $htmlUrl) {
            return [];
        }

        $card = new ApiLinksCard([]);
        if ($sourceId) {
            $card->addLink('Sardegna Sentieri (JSON)', 'https://www.sardegnasentieri.it/ss/poi/' . $sourceId . '?_format=json');
        }
        if ($htmlUrl) {
            $card->addLink('Sardegna Sentieri (HTML)', $htmlUrl);
        }

        return [$card];
    }

    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        $nonTabFields = array_values(array_filter($parentFields, fn($f) => ! ($f instanceof TabsGroup)));

        return [
            ...$nonTabFields,
            BelongsToMany::make('Related POIs', 'relatedPois', self::class),
            Tab::group(__('Details'), [
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
            ]),
        ];
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Sardegna Sentieri ID', 'properties->out_source_feature_id')->readonly(),
            Text::make('Codice', 'properties->forestas->codice'),
            NovaTabTranslatable::make([
                Tiptap::make('Come arrivare', 'properties->forestas->come_arrivare')->readonly(),
            ])->hideFromIndex(),
            Text::make('URL', 'properties->forestas->url')->readonly(),
            Text::make('Aggiornato il', 'properties->forestas->updated_at')->readonly(),
            KeyValue::make('Collegamenti', 'properties->forestas->collegamenti'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
