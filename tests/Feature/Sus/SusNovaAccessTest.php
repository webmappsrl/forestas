<?php

use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use App\Models\User;

it('nega l accesso a Nova al client SUS', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create(['email' => 'sus@example.invalid']);
    $client->assignRole('Sus');

    expect(Gate::forUser($client)->allows('viewNova'))->toBeFalse();
});

it('non cambia l accesso a Nova per gli altri ruoli', function () {
    Role::findOrCreate('Validator', 'web');
    Role::findOrCreate('Guest', 'web');

    $validator = User::factory()->create();
    $validator->assignRole('Validator');

    $guest = User::factory()->create();
    $guest->assignRole('Guest');

    expect(Gate::forUser($validator)->allows('viewNova'))->toBeTrue();
    expect(Gate::forUser($guest)->allows('viewNova'))->toBeFalse();
});
