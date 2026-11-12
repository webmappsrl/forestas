<?php

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\User;
use App\Nova\EcPoi as EcPoiResource;
use App\Nova\EcTrack as EcTrackResource;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * I sentieri vicini e i POI collegati si vedono in Nova ma non si modificano (oc:8932): il dato
 * viene da Drupal o e' calcolato, e l'import lo riscrive a ogni giro.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);
    RolesAndPermissionsService::seedDatabase();
    $this->user = User::factory()->create();
    $this->user->assignRole('Administrator');
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
    $this->track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [],
    ]);
    $this->track = EcTrack::find($this->track->id);
    $this->request = NovaRequest::create('/nova-api/x', 'GET');
    $this->request->setUserResolver(fn () => $this->user);
});

it('espone il campo dei percorsi vicini sulla Resource', function () {
    $field = collect((new EcTrackResource($this->track))->fields($this->request))
        ->first(fn ($f) => $f instanceof BelongsToMany && $f->attribute === 'relatedTracks');

    expect($field)->not->toBeNull();
});

it('non permette di aggiungere o togliere percorsi vicini a mano', function () {
    $resource = new EcTrackResource($this->track);

    expect($resource->authorizedToAttachAny($this->request, EcTrack::class))->toBeFalse();
    expect($resource->authorizedToAttach($this->request, $this->track))->toBeFalse();
    expect($resource->authorizedToDetach($this->request, $this->track, 'relatedTracks'))->toBeFalse();
});

it('non permette di aggiungere o togliere nemmeno i POI collegati', function () {
    $resource = new EcTrackResource($this->track);
    $poi = new EcPoi;

    expect($resource->authorizedToAttachAny($this->request, EcPoi::class))->toBeFalse();
    expect($resource->authorizedToAttach($this->request, $poi))->toBeFalse();
    expect($resource->authorizedToDetach($this->request, $poi, 'ecPois'))->toBeFalse();
});

it('non lascia modificare i collegamenti nemmeno dalla scheda del punto di interesse', function () {
    // La regola vale per entrambi i capi del collegamento: implementata sul solo percorso, dalla
    // scheda del punto di interesse si poteva ancora collegare a mano un sentiero o un altro
    // punto di interesse, e `ResolveSardegnaSentieriRelationsJob` lo cancellava al giro dopo
    // con il suo `sync()` (oc:8932).
    $created = Wm\WmPackage\Models\EcPoi::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [],
    ]);
    $poi = EcPoi::find($created->id);
    $resource = new EcPoiResource($poi);
    $request = NovaRequest::create('/nova-api/ec-pois/'.$poi->id, 'GET');

    expect($resource->authorizedToAttachAny($request, new EcPoi))->toBeFalse()
        ->and($resource->authorizedToAttachAny($request, new EcTrack))->toBeFalse()
        ->and($resource->authorizedToDetach($request, new EcTrack, 'ecTracks'))->toBeFalse();
});
