<?php

use App\Http\Controllers\Api\Sus\SusAuthController;
use App\Http\Controllers\Api\Sus\SusPingController;
use App\Http\Middleware\LogSusRequest;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\ConfirmApplicationController;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\PatchApplicationController;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\ShowApplicationController;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreApplicationController;
use Wm\WmPackage\Http\Controllers\Api\TrailRegistry\StoreAttachmentController;

// Autenticazione del client SUS. Fuori da `auth:api` — sono gli endpoint con
// cui il token si ottiene. Il throttle e' lo stesso applicato al login della
// piattaforma in wm-package.
Route::middleware(['throttle:100,1', LogSusRequest::class])
    ->prefix('auth')
    ->name('auth.')
    ->group(function () {
        Route::post('/login', [SusAuthController::class, 'login'])->name('login');
    });

// Tutto il canale autenticato e' riservato al client: anche ping e refresh.
// Con il solo `auth:api` il refresh consegnerebbe un token nuovo a chiunque ne
// abbia uno della piattaforma, perche' il segreto JWT e' lo stesso.
Route::middleware(['auth:api', LogSusRequest::class, RoleMiddleware::class.':Sus'])->group(function () {
    Route::post('/auth/refresh', [SusAuthController::class, 'refresh'])->name('auth.refresh');
    Route::get('/ping', SusPingController::class)->name('ping');

    // Domande di accatastamento. Puntano direttamente ai controller di
    // wm-package: nessuna sottoclasse nello shard finche' non serve
    // personalizzare qualcosa.
    //
    // Il limite e' per utente (il throttle gira dopo `auth:api`): 30 chiamate al
    // minuto. Da quando la domanda si compila in piu' riprese il conto non e'
    // piu' quello di prima — erano 11 chiamate, una creazione piu' nove
    // allegati e la conferma — perche' fra l'apertura e la trasmissione ce ne
    // stanno quante ne servono.
    //
    // Il peggio ragionevole resta pero' sotto: un'apertura, i campi del
    // modulo spalmati su cinque o sei consegne, nove allegati — quattro
    // documenti e cinque fotografie — e una conferma fanno una ventina di
    // chiamate, e il limite ne lascia passare 30. Una domanda al minuto
    // contro le dieci al giorno stimate, con il margine per qualche
    // correzione.
    //
    // Il tetto serve soprattutto a cio' che una chiamata si porta dietro: i
    // caricamenti su S3 e il servizio DEM, che riparte a ogni tracciato nuovo
    // e non solo alla creazione.
    //
    // La lettura dello stato consuma lo stesso budget: senza un limitatore con
    // nome, Laravel conta per utente e non per rotta. Con i volumi del verbale
    // del 10/09 — al massimo 30 domande al mese, picchi di 10 al giorno — chi
    // segue le proprie domande interrogandole ogni pochi minuti resta molto
    // sotto il limite.
    //
    Route::middleware(['throttle:30,1'])->group(function () {
        Route::post('/trail-applications', StoreApplicationController::class)->name('applications.store');
        Route::get('/trail-applications/{application}', ShowApplicationController::class)->name('applications.show');
        // La modifica. Il client manda un `POST` con `_method=PATCH` nel
        // modulo, perche' la chiamata puo' portare anche il file del
        // tracciato; Laravel legge quel campo e instrada la richiesta come
        // `PATCH`, quindi la rotta si dichiara `patch` e non `post`. Il
        // perche' dello spoofing sta nel docblock del controller.
        Route::patch('/trail-applications/{application}', PatchApplicationController::class)
            ->name('applications.patch');
        Route::post('/trail-applications/{application}/attachments', StoreAttachmentController::class)
            ->name('applications.attachments.store');
        Route::post('/trail-applications/{application}/confirm', ConfirmApplicationController::class)
            ->name('applications.confirm');
    });
});
