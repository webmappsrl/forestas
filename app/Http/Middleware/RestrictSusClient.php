<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Il client SUS (oc:8333) e' un canale programmatico verso un ente esterno:
 * puo' usare **solo** il proprio branch di API. Ogni altra route della
 * piattaforma risponde 403, comprese quelle di autenticazione di wm-package:
 * il branch ha i propri endpoint di login e refresh
 * (`SusAuthController`), quindi non ha bisogno di uscire dal prefisso.
 *
 * La whitelist e' per prefisso e non una blacklist di route da negare: le
 * route che wm-package aggiungera' al gruppo auth:api restano escluse per
 * costruzione, senza manutenzione.
 */
class RestrictSusClient
{
    /**
     * Prefissi consentiti al client SUS, senza slash iniziale.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'api/v1/sus/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isSusClient()) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED)) {
            return $next($request);
        }

        abort(403, __('The SUS client is not allowed to access this endpoint.'));
    }

    /**
     * Risolve l'utente dal token JWT presente nella richiesta.
     *
     * Questo middleware e' appeso al gruppo `api`, quindi viene eseguito prima
     * del middleware di route `auth:api`: auth()->user() sarebbe sempre null.
     * Il guard JWT legge l'header Authorization da se', percio' l'utente e'
     * risolvibile anche in questa posizione.
     *
     * Token assente, scaduto o malformato: la richiesta passa e a rispondere
     * 401 sara' `auth:api`, come per qualunque altro client.
     */
    private function isSusClient(): bool
    {
        try {
            $user = auth('api')->user();
        } catch (Throwable) {
            return false;
        }

        return $user !== null && $user->hasRole('Sus');
    }
}
