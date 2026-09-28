<?php

use App\Nova\TrailApplication;
use App\Nova\TrailRegistryAnomaly;
use App\Nova\TrailRegistryCode;
use Laravel\Nova\Nova;

it('Nova usa le Resource del Catasto di forestas', function () {
    Nova::resourcesIn(app_path('Nova'));

    expect(Nova::resourceForKey('trail-registry-codes'))->toBe(TrailRegistryCode::class);
    expect(Nova::resourceForKey('trail-registry-anomalies'))->toBe(TrailRegistryAnomaly::class);
    expect(Nova::resourceForKey('trail-applications'))->toBe(TrailApplication::class);
});
