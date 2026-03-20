<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    return redirect('/nova');
});

// Nova SPA sometimes emits /resources/* absolute navigations.
// Normalize them to the configured Nova base path to avoid 404 redirects.
Route::get('/resources/{path?}', function (?string $path = null) {
    $normalizedPath = trim((string) $path, '/');
    $target = '/nova/resources'.($normalizedPath !== '' ? "/{$normalizedPath}" : '');
    $queryString = request()->getQueryString();

    if ($queryString) {
        $target .= "?{$queryString}";
    }

    return redirect($target);
})->where('path', '.*');
