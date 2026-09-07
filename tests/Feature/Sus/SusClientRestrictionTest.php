<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('Sus', 'web');

    $this->client = User::factory()->create(['email' => 'sus@example.invalid']);
    $this->client->assignRole('Sus');
    $this->token = auth('api')->login($this->client);
});

it('nega al client SUS la cancellazione del proprio account', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/delete')
        ->assertForbidden();

    expect(User::find($this->client->id))->not->toBeNull();
});

it('nega al client SUS la modifica del proprio profilo', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/user', ['name' => 'cambiato'])
        ->assertForbidden();
});

it('consente al client SUS le route del proprio branch', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk();
});

it('nega al client SUS anche il login della piattaforma', function () {
    // Il branch SUS ha i propri endpoint di autenticazione: il client non ha
    // motivo di usare quelli condivisi con le app mobile.
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/auth/refresh')
        ->assertForbidden();
});

it('consente al client SUS il refresh sul proprio branch', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/sus/auth/refresh')
        ->assertOk();
});

it('non limita gli utenti senza ruolo Sus', function () {
    $altro = User::factory()->create();
    $token = auth('api')->login($altro);

    // Si usa auth/me e non auth/user: quest'ultima crasha con TypeError se
    // manca l'header app-id (bug preesistente in wm-package,
    // AppAuthController::filterUserPrivacyByAppId), che renderebbe il test
    // verde o rosso per il motivo sbagliato.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/me')
        ->assertSuccessful();
});
