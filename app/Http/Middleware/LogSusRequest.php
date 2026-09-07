<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra ogni chiamata al branch SUS (oc:8333) sul canale di log dedicato.
 *
 * Scopo diagnostico: quando Engineering segnala che una chiamata non risponde,
 * questo log dice se e' arrivata e con che esito.
 *
 * Il token e l'header Authorization non vengono mai scritti, nemmeno troncati.
 */
class LogSusRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        Log::channel('sus')->info('Richiesta al branch SUS', [
            'user_id' => $this->userId(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);

        return $response;
    }

    private function userId(): ?int
    {
        try {
            return auth('api')->user()?->id;
        } catch (Throwable) {
            return null;
        }
    }
}
