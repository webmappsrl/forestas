<?php

namespace App\Nova;

use App\Enums\StatoValidazione;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;

class EcTrack extends WmNovaEcTrack
{
    public function fields(NovaRequest $request): array
    {
        return array_merge(parent::fields($request), [
            Select::make('Stato validazione', 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
        ]);
    }
}
