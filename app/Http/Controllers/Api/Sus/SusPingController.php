<?php

namespace App\Http\Controllers\Api\Sus;

use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(
    name: 'Ping',
    description: 'Verifica della connessione e dell\'autenticazione.',
    weight: 2,
)]
class SusPingController extends Controller
{
    /**
     * Ping
     *
     * Conferma che la connessione e l'autenticazione funzionano, restituendo
     * l'identita' del client autenticato. Non tocca alcun dato del Catasto.
     *
     * @response array{client: array{id: int, name: string, email: string}, roles: string[], timestamp: string}
     */
    #[Response(status: 200, description: 'Connessione e autenticazione funzionanti.')]
    #[Response(status: 401, description: 'Token assente, scaduto o non valido.', type: 'array{message: string}')]
    #[Response(status: 403, description: "Token valido, ma l'utente non e' il client SUS.", type: 'array{message: string}')]
    public function __invoke(): JsonResponse
    {
        $client = auth('api')->user();

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ],
            'roles' => $client->getRoleNames(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
