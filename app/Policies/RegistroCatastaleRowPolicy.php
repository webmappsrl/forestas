<?php

namespace App\Policies;

use App\Models\RegistroCatastaleRow;
use Illuminate\Contracts\Auth\Authenticatable;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;

/**
 * Le righe del registro catastale (oc:8700) fanno parte del Catasto: le vede
 * chi vede il Catasto, con la regola del package (TrailRegistryPolicy::allows),
 * mai riscritta qui. Senza una policy Nova non protegge il detail aperto per
 * URL. Il mirror lo scrive solo l'import: nessuna scrittura da Nova.
 */
class RegistroCatastaleRowPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return TrailRegistryPolicy::allows($user);
    }

    public function view(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return TrailRegistryPolicy::allows($user);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function restore(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function replicate(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }
}
