<?php

namespace App\Http\Controllers\Api\Sus;

use App\Http\Requests\Api\Sus\SusLoginRequest;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Throwable;

// Nota interna (commento e non docblock: non finisce nella documentazione
// pubblica). Il branch SUS non riusa `POST /api/auth/login` di wm-package per
// due motivi: quel contratto e' condiviso con le app mobile e puo' evolvere
// senza preavviso, e accetta un parametro `referrer` che serve a registrare lo
// sku dell'app mobile chiamante — irrilevante per un canale server-to-server,
// ma che comparirebbe nella documentazione consegnata a Engineering come se
// andasse valorizzato. Avendo endpoint propri, il client SUS puo' usare
// esclusivamente route sotto `/api/v1/sus/`. oc:8333

/**
 * Autenticazione del client SUS.
 */
#[Group(
    name: 'Auth',
    description: "Come il client SUS ottiene e rinnova il token di accesso.",
    weight: 1,
)]
class SusAuthController extends Controller
{
    /**
     * Login
     *
     * Rilascia un token JWT a partire dalle credenziali del client SUS. Il
     * token va inviato su ogni chiamata al branch come
     * `Authorization: Bearer <token>` e scade dopo `expires_in` secondi.
     *
     * @response array{access_token: string, token_type: string, expires_in: int}
     */
    #[Response(status: 200, description: 'Token rilasciato.')]
    #[Response(status: 422, description: 'Email o password mancanti o malformate.')]
    #[Response(status: 401, description: 'Credenziali non valide.', type: 'array{error: string}')]
    #[Response(status: 403, description: "Credenziali valide, ma l'utente non e' il client SUS.", type: 'array{error: string}')]
    #[Response(status: 429, description: 'Piu\' di 100 tentativi di login al minuto dallo stesso indirizzo IP.', type: 'array{message: string}')]
    public function login(SusLoginRequest $request): JsonResponse
    {
        $credenziali = $request->validated();
        $credenziali['email'] = mb_strtolower($credenziali['email']);

        // Il contratto Guard di Laravel dichiara attempt(): bool, ma il guard
        // JWT di tymon restituisce il token come stringa (false se le
        // credenziali non sono valide).
        /** @var string|false $token */
        $token = Auth::guard('api')->attempt($credenziali);

        if (! $token) {
            return response()->json([
                'error' => __('Credenziali non valide.'),
            ], 401);
        }

        // Il branch e' riservato al client SUS: un utente della piattaforma con
        // credenziali valide non deve poterlo usare come porta di accesso
        // alternativa.
        if (! Auth::guard('api')->user()->hasRole('Sus')) {
            Auth::guard('api')->logout();

            return response()->json([
                'error' => __('Questo endpoint e\' riservato al client SUS.'),
            ], 403);
        }

        return $this->rispostaConToken($token);
    }

    /**
     * Refresh del token
     *
     * Rilascia un token nuovo a partire da quello corrente, senza reinviare le
     * credenziali. Il token precedente non e' piu' valido.
     *
     * @response array{access_token: string, token_type: string, expires_in: int}
     */
    #[Response(status: 200, description: 'Token rinnovato. Il precedente non e\' piu\' valido.')]
    #[Response(status: 401, description: 'Token assente, gia\' rinnovato, oppure oltre la finestra di rinnovo di 14 giorni: rieseguire il login.', type: 'array{error: string}')]
    public function refresh(): JsonResponse
    {
        try {
            /** @phpstan-ignore method.notFound */
            return $this->rispostaConToken(Auth::guard('api')->refresh());
        } catch (Throwable) {
            return response()->json([
                'error' => __('Impossibile rinnovare il token: rieseguire il login.'),
            ], 401);
        }
    }

    private function rispostaConToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            /** @phpstan-ignore method.notFound */
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ]);
    }
}
