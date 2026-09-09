<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

it('rifiuta il ping senza token', function () {
    $this->getJson('/api/v1/sus/ping')->assertUnauthorized();
});

it('risponde al ping con l identita del client autenticato', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create([
        'name' => 'SUS Client',
        'email' => 'sus@example.invalid',
    ]);
    $client->assignRole('Sus');

    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk()
        ->assertJsonPath('client.email', 'sus@example.invalid')
        ->assertJsonPath('roles', ['Sus']);
});
