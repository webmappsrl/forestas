<?php

declare(strict_types=1);

use App\Jobs\Import\SyncForestasSentieriItinerariLayersJob;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;

uses(RefreshDatabase::class);

function forestasApp(): App
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);

    $id = SardegnaSentieriImportService::IMPORT_APP_ID;
    $existing = App::query()->find($id);
    if ($existing !== null) {
        return $existing;
    }

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        [
            'name' => 'Sardegna Sentieri',
            'password' => Hash::make(Str::random(32)),
        ]
    );
    if (! $user->hasRole('Editor')) {
        $user->assignRole('Editor');
    }

    return App::withoutEvents(fn () => App::query()->create([
        'id' => $id,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));
}

it('crea i layer Sentieri/Itinerari (nome capitalizzato) e collega le track in base a forestas.type', function () {
    Bus::fake();
    $app = forestasApp();

    $tSentieri = EcTrack::factory()->create([
        'app_id' => $app->id,
        'properties' => ['forestas' => ['type' => 'sentiero', 'skip_dem_jobs' => true]],
    ]);

    $tItinerari = EcTrack::factory()->create([
        'app_id' => $app->id,
        'properties' => ['forestas' => ['type' => 'itinerario', 'skip_dem_jobs' => true]],
    ]);

    $tOther = EcTrack::factory()->create([
        'app_id' => $app->id,
        'properties' => ['forestas' => ['type' => 'altro', 'skip_dem_jobs' => true]],
    ]);

    (new SyncForestasSentieriItinerariLayersJob($app->id))->handle();

    $layerSentieri = Layer::query()
        ->where('app_id', $app->id)
        ->whereRaw("properties->>'source' = ?", ['forestas'])
        ->whereRaw("properties->>'key' = ?", ['sentiero'])
        ->first();

    $layerItinerari = Layer::query()
        ->where('app_id', $app->id)
        ->whereRaw("properties->>'source' = ?", ['forestas'])
        ->whereRaw("properties->>'key' = ?", ['itinerario'])
        ->first();

    expect($layerSentieri)->not->toBeNull()
        ->and($layerSentieri->getTranslation('name', 'it'))->toBe('Sentieri');

    expect($layerItinerari)->not->toBeNull()
        ->and($layerItinerari->getTranslation('name', 'it'))->toBe('Itinerari');

    expect($tSentieri->fresh()->layers->pluck('id')->all())->toContain($layerSentieri->id);
    expect($tItinerari->fresh()->layers->pluck('id')->all())->toContain($layerItinerari->id);
    expect($tOther->fresh()->layers->pluck('id')->all())->not->toContain($layerSentieri->id)
        ->and($tOther->fresh()->layers->pluck('id')->all())->not->toContain($layerItinerari->id);
});

it('è idempotente: rieseguire sync non duplica pivot né crea layer doppi', function () {
    Bus::fake();
    $app = forestasApp();

    $tSentieri = EcTrack::factory()->create([
        'app_id' => $app->id,
        'properties' => ['forestas' => ['type' => 'sentiero', 'skip_dem_jobs' => true]],
    ]);

    $job = new SyncForestasSentieriItinerariLayersJob($app->id);
    $job->handle();
    $job->handle();

    $layerCount = Layer::query()
        ->where('app_id', $app->id)
        ->whereRaw("properties->>'source' = ?", ['forestas'])
        ->whereRaw("properties->>'key' = ?", ['sentiero'])
        ->count();

    expect($layerCount)->toBe(1);

    expect($tSentieri->fresh()->layers()->count())->toBe(1);
});

