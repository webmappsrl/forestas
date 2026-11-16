<?php

use App\Http\Controllers\Api\EnteController;
use App\Http\Middleware\VerifyWpApiToken;
use Illuminate\Support\Facades\Route;

// Rotte API di forestas, con il prefisso `api` e il gruppo `api` che aggiunge bootstrap/app.php.
// SUS e dettaglio del POI restano registrati in AppServiceProvider: il secondo deve stare dopo
// le rotte del package, che sostituisce.

// Enti (oc:8965), dietro il token del sito WordPress (VerifyWpApiToken).
Route::prefix('v1/entes')
    ->name('entes.')
    ->middleware(VerifyWpApiToken::class)
    ->group(function () {
        // Tutti gli enti in un'unica risorsa che il client tiene in cache: 60 chiamate al minuto
        // bastano anche se tutto il sito passa da un solo client.
        Route::get('/', [EnteController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('index');

        // Ricerca per la ricerca unica: ogni ricerca dei visitatori passa dal server del sito,
        // quindi il limite vale per tutto il sito e ferma solo un uso anomalo.
        Route::get('/search', [EnteController::class, 'search'])
            ->middleware('throttle:600,1')
            ->name('search');
    });
