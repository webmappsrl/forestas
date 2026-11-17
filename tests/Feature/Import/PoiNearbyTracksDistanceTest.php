<?php

declare(strict_types=1);

use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\App;

/**
 * La distanza massima dei percorsi vicini al POI (oc:8959): colonna di `apps` con default 1000 m,
 * letta da `PoiNearbyTracksDistance::forApp()`, che non restituisce mai 0.
 */
uses(RefreshDatabase::class);

it('aggiunge la colonna con default 1000', function () {
    $app = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);

    expect($app->fresh()->{PoiNearbyTracksDistance::COLUMN})->toBe(1000);
});

it('ricade su 1000 senza App o senza valore', function () {
    expect(PoiNearbyTracksDistance::forApp(null))->toBe(1000)
        ->and(PoiNearbyTracksDistance::forApp(new App))->toBe(1000)
        ->and(PoiNearbyTracksDistance::forApp(new App([PoiNearbyTracksDistance::COLUMN => 0])))->toBe(1000);
});

it("legge il valore dell'App", function () {
    $app = App::factory()->createQuietly([PoiNearbyTracksDistance::COLUMN => 2500]);

    expect(PoiNearbyTracksDistance::forApp($app->fresh()))->toBe(2500);
});
