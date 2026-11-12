<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

it('espone le quattro rotte sotto il prefisso del canale', function () {
    expect(route('sus.applications.store'))->toContain('api/v1/sus/trail-applications');
    expect(route('sus.applications.show', ['application' => 1]))
        ->toContain('api/v1/sus/trail-applications/1');
    expect(route('sus.applications.attachments.store', ['application' => 1]))
        ->toContain('api/v1/sus/trail-applications/1/attachments');
    expect(route('sus.applications.confirm', ['application' => 1]))
        ->toContain('api/v1/sus/trail-applications/1/confirm');
});

it('rifiuta le chiamate senza token', function () {
    $this->postJson(route('sus.applications.store'), [])->assertUnauthorized();
});

it('non lascia passare chi non e il client del canale', function (string $name) {
    // La domanda deve esistere: il route model binding precede il controllo di
    // ruolo, e con un identificatore sconosciuto la risposta sarebbe 404.
    $application = TrailApplication::factory()->create();

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(route($name, ['application' => $application]), [])
        ->assertForbidden();
})->with([
    'creazione' => 'sus.applications.store',
    'allegato' => 'sus.applications.attachments.store',
    'conferma' => 'sus.applications.confirm',
]);

it('non lascia leggere lo stato a chi non e il client del canale', function () {
    // La lettura non cambia nulla, ma porta i dati di una domanda e, ad
    // approvazione, il numero del sentiero: non e' pubblica.
    $application = TrailApplication::factory()->create();

    $this->actingAs(User::factory()->create(), 'api')
        ->getJson(route('sus.applications.show', ['application' => $application]))
        ->assertForbidden();
});

it('lascia passare il client del canale fino alla validazione', function () {
    Role::findOrCreate('Sus', 'web');
    $client = User::factory()->create();
    $client->assignRole('Sus');

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->postJson(route('sus.applications.store'), [])
        ->assertUnprocessable();
});

it('rifiuta con 429 le chiamate oltre il limite configurato', function () {
    Role::findOrCreate('Sus', 'web');
    $client = User::factory()->create();
    $client->assignRole('Sus');

    // Il tetto si legge dalla rotta, non si scrive a mano. Il client e' nuovo,
    // quindi il suo contatore parte da zero. Le risposte 422 contano.
    $throttle = collect(Route::getRoutes()->getByName('sus.applications.store')->gatherMiddleware())
        ->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
    $limit = (int) explode(',', substr($throttle, strlen('throttle:')))[0];

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client));

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson(route('sus.applications.store'), [])->assertUnprocessable();
    }

    $this->postJson(route('sus.applications.store'), [])->assertStatus(429);
});

it('limita la frequenza delle chiamate su tutte e quattro le rotte', function () {
    foreach (['sus.applications.store', 'sus.applications.show', 'sus.applications.attachments.store', 'sus.applications.confirm'] as $name) {
        $middleware = collect(Route::getRoutes()->getByName($name)->gatherMiddleware());

        expect($middleware->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->not->toBeEmpty();
    }
});

/**
 * Un client del canale con il suo token, per le chiamate che superano
 * autenticazione e ruolo.
 */
function susClientHeaders(): array
{
    Role::findOrCreate('Sus', 'web');
    $client = User::factory()->create();
    $client->assignRole('Sus');

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($client)];
}

it('risponde al 404 di una domanda con error, senza il namespace del package', function () {
    // Senza la riscrittura in bootstrap/app.php il corpo sarebbe «No query
    // results for model [Wm\WmPackage\...\TrailApplication] 42»: il fornitore
    // vedrebbe come e' fatto il package, e il contratto collaudato lo
    // congelerebbe.
    $response = $this->withHeaders(susClientHeaders())
        ->getJson(route('sus.applications.show', ['application' => 999999]))
        ->assertNotFound()
        ->assertExactJson(['error' => __('Application not found.')]);

    expect($response->getContent())->not->toContain('WmPackage')->not->toContain('999999');
});

it('risponde allo stesso modo a una domanda d ufficio e a un identificatore inesistente', function () {
    $headers = susClientHeaders();
    $office = TrailApplication::factory()->create(['source' => 'office']);

    $onOffice = $this->withHeaders($headers)->getJson(route('sus.applications.show', ['application' => $office]));
    $onMissing = $this->withHeaders($headers)->getJson(route('sus.applications.show', ['application' => 999999]));

    expect($onOffice->status())->toBe(404)
        ->and($onOffice->getContent())->toBe($onMissing->getContent());
});

it('risponde al client un indirizzo inesistente del canale con un messaggio generico', function () {
    $this->withHeaders(susClientHeaders())
        ->getJson('/api/v1/sus/indirizzo-che-non-esiste')
        ->assertNotFound()
        ->assertExactJson(['error' => __('Not found.')]);
});

/**
 * Un utente della piattaforma con il suo token, che non e' il client del canale.
 */
function otherUserHeaders(): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser(User::factory()->create())];
}

it('da a chi non e il client la stessa risposta su una domanda che esiste e su una che non esiste', function () {
    // Prima il controllo di ruolo veniva dopo la ricerca della domanda: chi non
    // era il client riceveva 403 dove la domanda esisteva e 404 dove no, e
    // scorrendo gli id poteva contarle.
    $headers = otherUserHeaders();
    $existing = TrailApplication::factory()->create();

    $onExisting = $this->withHeaders($headers)->getJson(route('sus.applications.show', ['application' => $existing]));
    $onMissing = $this->withHeaders($headers)->getJson(route('sus.applications.show', ['application' => 999999]));

    expect($onExisting->status())->toBe(403)
        ->and($onMissing->status())->toBe(403)
        ->and($onExisting->getContent())->toBe($onMissing->getContent());

    $onExisting->assertExactJson(['error' => __('Access denied: a valid token of the channel client is required.')]);
});

it('da a chi non ha un token la stessa risposta su qualunque indirizzo del canale', function () {
    $existing = TrailApplication::factory()->create();

    $responses = [
        $this->getJson(route('sus.applications.show', ['application' => $existing])),
        $this->getJson(route('sus.applications.show', ['application' => 999999])),
        $this->getJson(route('sus.ping')),
        $this->getJson('/api/v1/sus/indirizzo-che-non-esiste'),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized()
            ->assertExactJson(['error' => __('Access denied: a valid token of the channel client is required.')]);
    }
});

it('riserva al client anche ping e refresh', function (string $method, string $name) {
    // Con il solo `auth:api` il refresh consegnava un token nuovo a chiunque ne
    // avesse uno della piattaforma, perche' il segreto JWT e' lo stesso.
    $this->withHeaders(otherUserHeaders())
        ->json($method, route($name))
        ->assertForbidden()
        ->assertExactJson(['error' => __('Access denied: a valid token of the channel client is required.')]);
})->with([
    'ping' => ['GET', 'sus.ping'],
    'refresh' => ['POST', 'sus.auth.refresh'],
]);

it('non tocca il 404 del resto dell applicazione', function () {
    $this->getJson('/api/indirizzo-che-non-esiste')
        ->assertNotFound()
        ->assertJsonMissingPath('error');
});
