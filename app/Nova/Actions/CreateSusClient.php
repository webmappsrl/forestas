<?php

namespace App\Nova\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

// Nota interna. Il provisioning del client SUS (oc:8333) e' un'azione dedicata
// e non la compilazione dei campi Ruoli del resource utente, perche' quei campi
// sono in sola lettura per chi non e' in `WM_SUPER_ADMIN_EMAILS`
// (`RolesAndPermissionsService`). Allargare quell'allowlist non era una via
// praticabile: la stessa funzione governa anche `AppPolicy` e lo scope
// dell'import POI da OSM, quindi darebbe privilegi molto oltre la gestione dei
// ruoli. Questa azione fa una cosa sola, ed e' cio' che serve a Forestas per
// gestire il proprio client senza dipendere da Webmapp.

class CreateSusClient extends Action
{
    public $standalone = true;

    public $confirmButtonText = 'Create SUS client';

    public function name(): string
    {
        return __('Create SUS client');
    }

    /**
     * @param  Collection<int, User>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $email = mb_strtolower(trim((string) $fields->get('email')));

        if (User::where('email', $email)->exists()) {
            return Action::danger(__(
                'A user with the email :email already exists. To change the password use the Password field on their record.',
                ['email' => $email]
            ));
        }

        $password = (string) $fields->get('password');

        $client = new User;
        $client->name = (string) $fields->get('name');
        $client->email = $email;
        $client->password = Hash::make($password);
        $client->save();

        $client->syncRoles(['Sus']);

        // La cache dei permessi di Spatie e' condivisa con i queue worker: senza
        // il reset il ruolo appena assegnato non sarebbe visto da tutti.
        Artisan::call('permission:cache-reset');

        return Action::message(__(
            'SUS client :email created with the Sus role.',
            ['email' => $email]
        ));
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make(__('Email'), 'email')
                ->rules('required', 'email', 'max:254', 'unique:users,email'),

            Text::make(__('Name'), 'name')
                ->rules('required', 'max:255'),

            // Campo di testo e non Password: il valore deve restare leggibile,
            // perche' questo e' il momento in cui va copiato. Dopo il
            // salvataggio la password e' cifrata e non piu' recuperabile.
            Text::make(__('Password'), 'password')
                ->rules('required', 'string', 'min:16', 'max:255')
                ->default(fn () => Str::random(32))
                ->help(__('Suggested automatically: copy it before confirming, or replace it.')),
        ];
    }

    public function authorizedToSee(\Illuminate\Http\Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }
}
