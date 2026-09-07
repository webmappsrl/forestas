<?php

use App\Http\Controllers\Api\Sus\SusAuthController;
use App\Http\Controllers\Api\Sus\SusPingController;
use Illuminate\Support\Facades\Route;

// Autenticazione del client SUS. Fuori da `auth:api` — sono gli endpoint con
// cui il token si ottiene. Il throttle e' lo stesso applicato al login della
// piattaforma in wm-package.
Route::middleware(['throttle:100,1', \App\Http\Middleware\LogSusRequest::class])
    ->prefix('auth')
    ->name('auth.')
    ->group(function () {
        Route::post('/login', [SusAuthController::class, 'login'])->name('login');
    });

Route::middleware(['auth:api', \App\Http\Middleware\LogSusRequest::class])->group(function () {
    Route::post('/auth/refresh', [SusAuthController::class, 'refresh'])->name('auth.refresh');
    Route::get('/ping', SusPingController::class)->name('ping');
});
