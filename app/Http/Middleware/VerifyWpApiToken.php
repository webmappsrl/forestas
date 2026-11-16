<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token statico del sito WordPress (oc:8965): va su ogni rotta che il sito chiama, oggi gli enti.
 * Client macchina, senza utenti, quindi un segreto condiviso invece di un login JWT. Il token
 * nomina chi lo possiede: un altro server avrà il suo, revocabile da solo. Non va dato a un'app
 * distribuita: dal pacchetto il segreto si estrae.
 *
 * Stesso schema e stessa semantica di `EnsureExportToken` del package (export per Orchestrator,
 * oc:8242), con un segreto proprio: token non configurato → 403, e togliere la variabile spegne
 * le rotte senza una release; token mancante o sbagliato → 401.
 */
class VerifyWpApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('forestas.wp_api_token');

        if (empty($expected)) {
            abort(403, 'WordPress API is not configured on this instance.');
        }

        if (! hash_equals((string) $expected, (string) $request->bearerToken())) {
            abort(401, 'Invalid WordPress API token.');
        }

        return $next($request);
    }
}
