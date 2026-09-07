<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        $middleware->appendToGroup('api', \App\Http\Middleware\RestrictSusClient::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
