<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\StatoValidazione;
use App\Nova\Filters\EcTrackRuoloFilter;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    public static $model = \App\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        $nonTabFields = array_values(array_filter($parentFields, fn ($f) => ! ($f instanceof TabsGroup)));

        return [
            ...$nonTabFields,
            Select::make('Stato validazione', 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
            MorphToMany::make('Warnings', 'taxonomyWarnings', TaxonomyWarning::class)->display('name'),
            Tab::group(__('Details'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new EcTrackRuoloFilter,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        $track = $request->findModelOrFail();
        $card = new EcTrackApiLinksCard($track);

        $sourceId = data_get($track->properties, 'forestas.source_id');
        if ($sourceId) {
            $card->addLink('Sardegna Sentieri', 'https://www.sardegnasentieri.it/ss/track/'.$sourceId.'?_format=json');
        }

        return [$card];
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Sardegna Sentieri ID', 'properties->sardegnasentieri_id')->readonly(),
            Text::make('Source ID', 'properties->forestas->source_id')->readonly(),
            Text::make('URL', 'properties->forestas->url')->readonly(),
            Text::make('Aggiornato il', 'properties->forestas->updated_at')->readonly(),
            Text::make('Creato il', 'properties->forestas->created_at')->readonly(),
            Textarea::make('Info utili', 'properties->forestas->info_utili')->readonly(),
            Textarea::make('Roadbook', 'properties->forestas->roadbook')->readonly(),
            KeyValue::make('Allegati', 'properties->forestas->allegati'),
            KeyValue::make('Video', 'properties->forestas->video'),
            KeyValue::make('GPX', 'properties->forestas->gpx'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
