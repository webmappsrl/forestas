<?php

namespace App\Nova;

use App\Nova\Actions\ImportTaxonomyWhereFromLayersAction;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\TaxonomyWhere as WmTaxonomyWhere;

class TaxonomyWhere extends WmTaxonomyWhere
{
    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new ImportTaxonomyWhereFromLayersAction,
        ];
    }
}
