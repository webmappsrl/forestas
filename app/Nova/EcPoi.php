<?php

declare(strict_types=1);

namespace App\Nova;

use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        // Strip parent Tab::group(s) so we can rebuild a single Details group
        // that contains both the inherited Info tab and the new Forestas tab.
        // Note: getInfoTabFields() is inherited from WmNovaEcPoi (wm-package).
        // If the parent adds new Tab::group entries in the future, review this filter.
        $nonTabFields = array_values(array_filter($parentFields, fn ($f) => ! ($f instanceof Tab)));

        return [
            ...$nonTabFields,
            Tab::group(__('Details'), [
                Tab::make(__('Info'), $this->getInfoTabFields()),
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
            ]),
        ];
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Codice', 'properties->forestas->codice'),
            Textarea::make('Come arrivare', 'properties->forestas->come_arrivare'),
            Text::make('URL', 'properties->forestas->url'),
            Text::make('Aggiornato il', 'properties->forestas->updated_at'),
            KeyValue::make('Collegamenti', 'properties->forestas->collegamenti'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
