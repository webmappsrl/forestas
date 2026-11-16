<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Il token statico delle rotte degli enti (oc:8965): stessa semantica di `EnsureExportToken` del
 * package. 403 se lo shard non ha il token, 401 se manca o è sbagliato. Vale per tutte le rotte.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forestas.wp_api_token' => 'secret']);
});

dataset('entes routes', [
    'JSON' => ['/api/v1/entes'],
    'ricerca' => ['/api/v1/entes/search?query=x'],
]);

it('risponde 401 senza header', function (string $url) {
    $this->getJson($url)->assertUnauthorized();
})->with('entes routes');

it('risponde 401 con un token sbagliato', function (string $url) {
    $this->getJson($url, ['Authorization' => 'Bearer other'])->assertUnauthorized();
})->with('entes routes');

it('risponde 403 se il token non è configurato', function (?string $configured, string $header) {
    config(['forestas.wp_api_token' => $configured]);

    $this->getJson('/api/v1/entes', ['Authorization' => $header])->assertForbidden();
})->with([
    'vuoto, header vuoto' => ['', 'Bearer '],
    'null, header vuoto' => [null, 'Bearer '],
    'vuoto, header qualsiasi' => ['', 'Bearer secret'],
]);

it('risponde 200 con il token giusto', function () {
    $this->getJson('/api/v1/entes', ['Authorization' => 'Bearer secret'])->assertOk();
});
