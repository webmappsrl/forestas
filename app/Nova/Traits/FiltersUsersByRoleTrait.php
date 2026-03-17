<?php

namespace App\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;

trait FiltersUsersByRoleTrait
{
    public static function relatableUsers(NovaRequest $request, $query)
    {
        $currentUser = $request->user();
        if ($currentUser && $currentUser->hasRole('Administrator')) {
            return $query->role(['Administrator', 'Validator']);
        }

        return $query;
    }
}
