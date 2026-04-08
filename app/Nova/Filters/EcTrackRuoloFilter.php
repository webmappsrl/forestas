<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Models\EcTrack;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class EcTrackRuoloFilter extends Filter
{
    public function name(): string
    {
        return 'Ruolo';
    }

    public function apply(Request $request, $query, $value): Builder
    {
        return $query->whereExists(function ($q) use ($value) {
            $q->from('enteables')
                ->whereColumn('enteables.enteable_id', 'ec_tracks.id')
                ->where('enteables.enteable_type', EcTrack::class)
                ->where('enteables.ruolo', $value);
        });
    }

    public function options(Request $request): array
    {
        return [
            'Complesso forestale' => 'complesso_forestale',
            'Gestore' => 'gestore',
            'Manutentore' => 'manutentore',
            'Rilevatore' => 'rilevatore',
            'Operatore' => 'operatore',
        ];
    }
}
