<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

it('registra la chiamata al branch SUS senza mai loggare il token', function () {
    Role::findOrCreate('Sus', 'web');

    $client = User::factory()->create(['email' => 'sus@example.invalid']);
    $client->assignRole('Sus');
    $token = JWTAuth::fromUser($client);

    Log::shouldReceive('channel')->with('sus')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($client, $token) {
        expect($context['user_id'])->toBe($client->id);
        expect($context['endpoint'])->toBe('api/v1/sus/ping');
        expect($context['status'])->toBe(200);
        expect($context)->toHaveKey('timestamp');

        $serializzato = json_encode($context);
        expect($serializzato)->not->toContain($token);
        expect(array_keys($context))->not->toContain('authorization');

        return true;
    });

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sus/ping')
        ->assertOk();
});
