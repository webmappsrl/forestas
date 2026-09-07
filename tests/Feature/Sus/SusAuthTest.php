<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('Sus', 'web');

    $this->client = User::factory()->create([
        'email' => 'sus@example.invalid',
        'password' => bcrypt('password-di-prova'),
    ]);
    $this->client->assignRole('Sus');
});

it('rilascia un token al client SUS con credenziali corrette', function () {
    $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'sus@example.invalid',
        'password' => 'password-di-prova',
    ])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
        ->assertJsonPath('token_type', 'bearer');
});

it('rifiuta credenziali errate', function () {
    $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'sus@example.invalid',
        'password' => 'sbagliata',
    ])->assertUnauthorized();
});

it('rifiuta una richiesta senza credenziali', function () {
    $this->postJson('/api/v1/sus/auth/login', [])
        ->assertStatus(422);
});

it('nega il login a un utente senza ruolo Sus', function () {
    $altro = User::factory()->create([
        'email' => 'utente@example.invalid',
        'password' => bcrypt('password-di-prova'),
    ]);

    $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'utente@example.invalid',
        'password' => 'password-di-prova',
    ])->assertForbidden();

    expect($altro->fresh())->not->toBeNull();
});

it('ignora il referrer: non fa parte del contratto SUS', function () {
    $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'sus@example.invalid',
        'password' => 'password-di-prova',
        'referrer' => 'qualcosa',
    ])->assertOk();
});

it('rinnova il token del client SUS', function () {
    $token = auth('api')->login($this->client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sus/auth/refresh')
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
});

it('nega il refresh senza token', function () {
    $this->postJson('/api/v1/sus/auth/refresh')->assertUnauthorized();
});

it('permette di usare il ping con il token appena ottenuto', function () {
    $token = $this->postJson('/api/v1/sus/auth/login', [
        'email' => 'sus@example.invalid',
        'password' => 'password-di-prova',
    ])->json('access_token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk()
        ->assertJsonPath('client.email', 'sus@example.invalid');
});
