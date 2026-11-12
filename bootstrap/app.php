<?php

use App\Http\Middleware\RestrictSusClient;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Il client SUS (oc:8333) deve poter essere bloccato anche sulle route
        // di wm-package (auth/user, auth/delete, ugc/*, wallet/buy), quindi il
        // middleware e' appeso al gruppo `api` e non applicato al solo gruppo
        // SUS. Non e' un middleware globale: le route web e Nova non lo
        // attraversano.
        $middleware->appendToGroup('api', RestrictSusClient::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Il 404 del canale SUS (oc:8968). Laravel risponde con il messaggio
        // dell'eccezione, che per una domanda inesistente e' «No query results
        // for model [Wm\WmPackage\TrailRegistry\Models\TrailApplication] 42»:
        // il fornitore vedrebbe il namespace interno del package, e il contratto
        // collaudato lo congelerebbe. Qui il 404 diventa `{error}`, come gli
        // altri errori del canale.
        //
        // Il 404 di una domanda nasce dal route model binding, prima che il
        // controller sia chiamato, oppure dalla Form Request quando la domanda
        // e' d'ufficio: in entrambi i casi l'eccezione d'origine e' la stessa,
        // quindi la risposta resta identica e non si distingue una domanda
        // d'ufficio da un id che non esiste.
        // Chi non e' il client del canale riceve lo stesso messaggio su ogni
        // rotta, che manchi il token, sia scaduto o sia di un altro utente. Il
        // codice resta quello standard — 401 senza un token valido, 403 con il
        // token di un altro utente — perche' al client vero dice se rifare il
        // login, senza rivelare nulla sulle domande. Il login non passa di qui:
        // costruisce da se' le proprie risposte.
        $deny = fn (int $status) => response()->json([
            'error' => __('Access denied: a valid token of the channel client is required.'),
        ], $status);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/v1/sus/*') ? $deny(401) : null);
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $request->is('api/v1/sus/*') ? $deny(403) : null);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($deny) {
            if (! $request->is('api/v1/sus/*')) {
                return null;
            }

            // Prima chi chiama, poi cosa cerca. Il route model binding gira
            // prima del controllo di ruolo: senza questa riga chi non e' il
            // client riceverebbe 404 dove la domanda non esiste e 403 dove
            // esiste, e scorrendo gli id potrebbe contarle. Cosi' riceve
            // sempre la stessa risposta, qualunque id provi e su qualunque
            // indirizzo del canale.
            $user = auth('api')->user();

            if (! $user?->hasRole('Sus')) {
                return $deny($user ? 403 : 401);
            }

            $previous = $e->getPrevious();
            $isApplication = $previous instanceof ModelNotFoundException
                && is_a($previous->getModel(), TrailApplication::class, true);

            return response()->json([
                'error' => $isApplication ? __('Application not found.') : __('Not found.'),
            ], 404);
        });
    })->create();
