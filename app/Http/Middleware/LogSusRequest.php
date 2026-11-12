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
 * questo log dice se e' arrivata e con che esito. Per un `422` dice anche
 * perche' (oc:8968): i campi che non hanno passato la validazione con i loro
 * messaggi, oppure, alla conferma, i campi e gli allegati che mancano.
 *
 * Il token e l'header Authorization non vengono mai scritti, nemmeno troncati.
 * Nemmeno i valori inviati: una domanda porta nomi, e-mail e codici fiscali, e
 * questo log li terrebbe per 90 giorni. Si scrive solo cio' che e' nella
 * risposta, che nomina i campi ma non ne ripete il contenuto.
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
        ] + $this->failureDetails($response));

        return $response;
    }

    /**
     * Perche' un `422` e' fallito, letto dal corpo della risposta.
     *
     * Dalla risposta e non dalla richiesta: alla fine della catena i file
     * caricati sono gia' stati spostati, e rileggerli dalla richiesta solleva
     * un'eccezione che trasforma in un 500 una chiamata riuscita (e' successo
     * con un log di diagnosi durante le prove di oc:8968).
     *
     * @return array<string, mixed>
     */
    private function failureDetails(Response $response): array
    {
        if ($response->getStatusCode() !== 422) {
            return [];
        }

        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return [];
        }

        return array_filter([
            'validation_errors' => $body['errors'] ?? null,
            'missing' => $body['missing'] ?? null,
            'error' => $body['error'] ?? null,
        ], fn ($value) => $value !== null);
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
