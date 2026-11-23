<?php

namespace App\Nova;

use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractUserResource;

/**
 * La Resource degli utenti estende quella del package, come tutte le altre Resource di forestas.
 *
 * Fino a oggi era la Resource di default dello scheletro Nova, rimasta dal primo commit: mostrava
 * solo ID, Gravatar, nome, email e password, e si perdeva tutto quello che il package ci mette —
 * cognome, campo e filtro App, ruoli e permessi, i POI e le tracce UGC dell'utente, e la
 * disattivazione di Impersonate sulla detail view, dove un bug di Nova porta al login invece che
 * all'impersonificazione.
 */
class User extends AbstractUserResource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\User>
     */
    public static $model = \App\Models\User::class;

    /**
     * Le azioni di forestas, che non stanno nel package: la creazione del client SUS.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\CreateSusClient,
        ];
    }
}
