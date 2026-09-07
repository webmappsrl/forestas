<?php

use App\Models\User;
use App\Nova\Actions\CreateSusClient;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('Sus', 'web');
    Role::findOrCreate('Administrator', 'web');
});

function eseguiAzione(array $campi): Laravel\Nova\Actions\ActionResponse|array
{
    $action = new CreateSusClient;

    return $action->handle(
        new ActionFields(collect($campi), collect()),
        new Collection
    );
}

it('crea il client SUS con il ruolo assegnato', function () {
    eseguiAzione(['email' => 'sus@catasto.invalid', 'name' => 'SUS Client', 'password' => 'password-di-prova-lunga']);

    $client = User::where('email', 'sus@catasto.invalid')->first();

    expect($client)->not->toBeNull();
    expect($client->name)->toBe('SUS Client');
    expect($client->getRoleNames()->all())->toBe(['Sus']);
});

it('la password indicata permette il login sul branch SUS', function () {
    eseguiAzione([
        'email' => 'sus@catasto.invalid',
        'name' => 'SUS Client',
        'password' => 'password-scelta-nel-form',
    ]);

    $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'sus@catasto.invalid',
        'password' => 'password-scelta-nel-form',
    ])->assertOk();
});

it('suggerisce una password lunga e diversa a ogni apertura del form', function () {
    $action = new App\Nova\Actions\CreateSusClient;
    $richiesta = Laravel\Nova\Http\Requests\NovaRequest::create('/');

    $suggerite = collect(range(1, 3))->map(function () use ($action, $richiesta) {
        $campo = collect($action->fields($richiesta))->firstWhere('attribute', 'password');
        $campo->resolveForAction($richiesta);

        return $campo->value;
    });

    expect($suggerite->unique())->toHaveCount(3);
    expect(mb_strlen((string) $suggerite->first()))->toBeGreaterThanOrEqual(16);
});

it('non sovrascrive un client esistente', function () {
    $esistente = User::factory()->create(['email' => 'sus@catasto.invalid']);
    $passwordPrima = $esistente->password;

    eseguiAzione(['email' => 'sus@catasto.invalid', 'name' => 'Altro nome', 'password' => 'password-di-prova-lunga']);

    expect($esistente->fresh()->password)->toBe($passwordPrima);
    expect(User::where('email', 'sus@catasto.invalid')->count())->toBe(1);
});

it('e visibile solo agli Administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $editor = User::factory()->create();
    Role::findOrCreate('Editor', 'web');
    $editor->assignRole('Editor');

    $action = new CreateSusClient;

    $richiestaAdmin = Laravel\Nova\Http\Requests\NovaRequest::create('/');
    $richiestaAdmin->setUserResolver(fn () => $admin);

    $richiestaEditor = Laravel\Nova\Http\Requests\NovaRequest::create('/');
    $richiestaEditor->setUserResolver(fn () => $editor);

    expect($action->authorizedToSee($richiestaAdmin))->toBeTrue();
    expect($action->authorizedToSee($richiestaEditor))->toBeFalse();
});
