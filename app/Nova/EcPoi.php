<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Concerns\HasForestasMediaFields;
use App\Nova\Concerns\HasMirroredRelations;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;
use Wm\WmPackage\Nova\EcTrack;
use Wm\WmPackage\Nova\TaxonomyPoiType;

class EcPoi extends WmNovaEcPoi
{
    use HasForestasMediaFields;
    use HasMirroredRelations;

    public static $model = \App\Models\EcPoi::class;

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var \App\Models\EcPoi $poi */
        $poi = $request->findModelOrFail();
        $sourceId = data_get($poi->properties, 'out_source_feature_id');
        $htmlUrl = data_get($poi->properties, 'forestas.url');

        if (! $sourceId && ! $htmlUrl) {
            return [];
        }

        $card = new ApiLinksCard([]);
        if ($sourceId) {
            $card->addLink('Sardegna Sentieri (JSON)', 'https://www.sardegnasentieri.it/ss/poi/'.$sourceId.'?_format=json');
        }
        if ($htmlUrl) {
            $card->addLink('Sardegna Sentieri (HTML)', $htmlUrl);
        }

        return [$card];
    }

    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        $relationTypes = [BelongsToMany::class, MorphToMany::class];
        // Come su EcTrack: via i TabsGroup del package, tranne le tassonomie (oc:8910).
        $regularFields = array_values(array_filter(
            $this->withoutPackageImageField($this->keepOnlyTaxonomySection($parentFields)),
            fn ($f) => ! in_array(get_class($f), $relationTypes)
        ));

        return [
            ...$regularFields,
            Tab::group(__('Details'), [
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
            ]),
            BelongsToMany::make('Related POIs', 'relatedPois', self::class)->collapsedByDefault(),
            MorphToMany::make(__('Taxonomy Poi Types'), 'taxonomyPoiTypes', TaxonomyPoiType::class)->collapsedByDefault(),
            BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class)->collapsedByDefault(),
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
            Text::make('Creato il', 'properties->forestas->created_at')->readonly(),
            ...$this->forestasMediaFields(),
        ];
    }
}
