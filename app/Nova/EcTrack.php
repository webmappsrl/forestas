<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\StatoValidazione;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        // Strip parent Tab::group(s) so we can rebuild a single Details group
        // that contains both the inherited Info tab and the new Forestas tab.
        // Note: getInfoTabFields() is inherited from WmNovaEcTrack (wm-package).
        // If the parent adds new Tab::group entries in the future, review this filter.
        $nonTabFields = array_values(array_filter($parentFields, fn($f) => ! ($f instanceof TabsGroup)));

        return [
            ...$nonTabFields,
            Select::make('Stato validazione', 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
            Tab::group(__('Details'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
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
            Text::make('Source ID', 'properties->forestas->source_id'),
            Text::make('Tipo', 'properties->forestas->type'),
            Text::make('URL', 'properties->forestas->url'),
            Text::make('Aggiornato il', 'properties->forestas->updated_at'),
            KeyValue::make('Allegati', 'properties->forestas->allegati'),
            KeyValue::make('Video', 'properties->forestas->video'),
            KeyValue::make('GPX', 'properties->forestas->gpx'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
