<?php

namespace App\Nova;

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\App as WmNovaApp;
use Wm\WmPackage\Nova\Actions\ImportTaxonomyWhereFromOsmfeatures;

class App extends WmNovaApp
{
    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ImportTaxonomyWhereFromOsmfeatures,
        ];
    }
}
