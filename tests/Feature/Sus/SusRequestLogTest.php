<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

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

/**
 * Il contesto della riga scritta sul canale `sus` dalla prossima chiamata.
 */
function captureSusLog(): ArrayObject
{
    $captured = new ArrayObject;

    Log::shouldReceive('channel')->with('sus')->andReturnSelf();
    Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use ($captured) {
        $captured->exchangeArray($context);
    });

    return $captured;
}

function susLogClientHeaders(): array
{
    Role::findOrCreate('Sus', 'web');
    $client = User::factory()->create();
    $client->assignRole('Sus');

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($client)];
}

it('registra i campi che non passano la validazione, ma non i valori inviati', function () {
    // Quando chi integra segnala un 422, il log deve dire su quale campo. Il
    // valore no: una domanda porta nomi, e-mail e codici fiscali.
    $headers = susLogClientHeaders();
    $log = captureSusLog();

    $this->withHeaders($headers)
        ->postJson(route('sus.applications.store'), ['municipality_email' => 'valore-riservato-123'])
        ->assertUnprocessable();

    expect($log['status'])->toBe(422)
        ->and($log['validation_errors'])->toHaveKeys(['municipality_email', 'geometry'])
        ->and($log['validation_errors']['municipality_email'][0])->toContain('municipality_email');

    expect(json_encode($log->getArrayCopy()))->not->toContain('valore-riservato-123');
});

it('registra alla conferma i campi e gli allegati che mancano', function () {
    $headers = susLogClientHeaders();
    $application = TrailApplication::factory()->create(['status' => TrailApplicationStatus::UnderCompilation]);
    $log = captureSusLog();

    $this->withHeaders($headers)
        ->postJson(route('sus.applications.confirm', ['application' => $application]))
        ->assertUnprocessable();

    expect($log['missing'])->toHaveKeys(['fields', 'attachments'])
        ->and($log['missing']['attachments'])->not->toBeEmpty()
        ->and($log)->toHaveKey('error');
});

it('non aggiunge dettagli d errore a una chiamata riuscita', function () {
    $headers = susLogClientHeaders();
    $log = captureSusLog();

    $this->withHeaders($headers)->getJson(route('sus.ping'))->assertOk();

    expect($log->getArrayCopy())->not->toHaveKeys(['validation_errors', 'missing', 'error']);
});
