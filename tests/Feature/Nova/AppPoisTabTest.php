<?php

declare(strict_types=1);

use App\Models\User;
use App\Nova\App as AppResource;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\PoiNearbyTracksDistance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * La distanza dei percorsi vicini al POI nella tab `pois` dell'App (oc:8959): in fondo ai campi del
 * package, che restano invariati.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);

    RolesAndPermissionsService::seedDatabase();
    $this->actingAs(User::factory()->create()->assignRole('Administrator'));
});

it('aggiunge la distanza ai campi della tab pois, senza togliere quelli del package', function () {
    $app = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID])->fresh();

    $fields = collect((new AppResource($app))->fields(NovaRequest::create('/nova-api/apps/1', 'GET')))
        ->flatMap(fn ($f) => $f instanceof TabsGroup ? collect($f->tabs)->flatMap(fn ($t) => $t->data) : [$f])
        ->keyBy('attribute');
    $field = $fields[PoiNearbyTracksDistance::COLUMN] ?? null;

    expect($field)->toBeInstanceOf(Number::class)
        ->and($field->rules)->toBe(['required', 'integer', 'min:1'])
        ->and($fields->has('poi_interaction'))->toBeTrue();

    $field->resolve($app);
    expect($field->value)->toBe(1000);
});

it('non mostra la distanza sulle altre App', function () {
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    $other = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID + 1])->fresh();
    $request = NovaRequest::create('/nova-api/apps/2', 'GET');

    $fields = collect((new AppResource($other))->fields($request))
        ->flatMap(fn ($f) => $f instanceof TabsGroup ? collect($f->tabs)->flatMap(fn ($t) => $t->data) : [$f])
        ->filter(fn ($f) => $f->authorize($request))
        ->keyBy('attribute');

    expect($fields->has(PoiNearbyTracksDistance::COLUMN))->toBeFalse()
        ->and($fields->has('poi_interaction'))->toBeTrue();
});
