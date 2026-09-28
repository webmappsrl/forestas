<?php

namespace App\Nova;

use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as WmTrailRegistryCode;

// Aggiunge la Tab «Registro» (oc:8539) accanto a quella «Codice» del
// package, senza toccarne i campi. La uriKey resta quella fissa della
// classe base, da cui il package continua a cercare questa Resource.
class TrailRegistryCode extends WmTrailRegistryCode
{
    /**
     * I campi del package restano invariati dentro la Tab «Codice»: la Tab
     * «Registro» (oc:8539) affianca lo specchio del foglio Google, senza
     * toccare cosa il package mostra ne' come lo mostra.
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Tab::group(__('Code'), [
                Tab::make(__('Code'), parent::fields($request)),
                Tab::make(__('Registry'), [
                    Text::make(__('Registry'), fn () => RegistroTabRenderer::render($this->resource))
                        ->asHtml()->onlyOnDetail(),
                ]),
            ]),
        ];
    }
}
