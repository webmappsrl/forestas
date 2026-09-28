<?php

use App\Jobs\Import\ImportRegistroCatastaleJob;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use App\Services\RegistroCatastale\RegistroCatastaleImportResult;
use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

/**
 * Il registro catastale (oc:8539) si specchia dopo che i job di Sardegna
 * Sentieri hanno finito di scrivere, non alla fine del comando che li
 * accoda: stesso principio del ricalcolo delle anomalie di oc:8607
 * (docs/knowledge/import-asincrono-e-anomalie.md). Questi test presidiano
 * l'aggancio del job del registro alla callback `finally` del batch.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );

    if (! $user->hasRole('Editor')) {
        $user->assignRole('Editor');
    }

    $id = SardegnaSentieriImportService::IMPORT_APP_ID;

    App::query()->find($id) ?? App::withoutEvents(fn () => App::query()->create([
        'id' => $id,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));

    // Un elemento per tipo basta a produrre qualche job: qui interessa solo
    // l'aggancio del batch, non la scrittura reale (stesso pattern di
    // ImportResetNormalizeTest).
    Http::fake([
        '*/tassonomia/*' => Http::response([], 200),
        '*/list-tracks/*' => Http::response(['1' => '2026-01-01T00:00:00'], 200),
        '*/listpoi/*' => Http::response(['2' => '2026-01-01T00:00:00'], 200),
        '*sardegnasentieri.it*' => Http::response([], 200),
    ]);

    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
});

/**
 * Costruisce un Batch fake da passare a mano alla callback `finally`
 * registrata dal comando, per simulare la fine reale del batch senza far
 * girare i job (stessi ingredienti di BatchFake usato dal framework).
 */
function fakeFinishedBatch(int $totalJobs, int $failedJobs): BatchFake
{
    return new BatchFake(
        'batch-id',
        'sardegnasentieri import',
        $totalJobs,
        0,
        $failedJobs,
        [],
        [],
        Carbon::now()->toImmutable(),
    );
}

it('dopo un normalize riuscito accoda il job del registro', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    Bus::assertBatched(function ($batch) {
        foreach ($batch->finallyCallbacks() as $callback) {
            $callback(fakeFinishedBatch($batch->jobs->count(), 0));
        }

        return true;
    });

    Bus::assertDispatched(ImportRegistroCatastaleJob::class);
});

it('se il normalize viene saltato il job non parte', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    Bus::assertBatched(function ($batch) {
        foreach ($batch->finallyCallbacks() as $callback) {
            // Job falliti ben oltre la soglia del 2%: il normalize non
            // parte, e con lui non deve partire nemmeno il job del registro.
            $callback(fakeFinishedBatch($batch->jobs->count(), $batch->jobs->count()));
        }

        return true;
    });

    Bus::assertNotDispatched(ImportRegistroCatastaleJob::class);
});

it('nell import orario i job sono raggruppati in un batch e il registro parte alla fine', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import');

    Bus::assertBatched(function ($batch) {
        foreach ($batch->finallyCallbacks() as $callback) {
            $callback(fakeFinishedBatch($batch->jobs->count(), 0));
        }

        return true;
    });

    Bus::assertDispatched(ImportRegistroCatastaleJob::class);
});

/**
 * Rifà da capo i fake HTTP con elenchi vuoti: i fake del beforeEach restano
 * registrati e vincono sui successivi (il primo che corrisponde risponde),
 * quindi serve una factory nuova.
 */
function fakeSorgenteSenzaNovita(): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        '*/tassonomia/*' => Http::response([], 200),
        '*/list-tracks/*' => Http::response([], 200),
        '*/listpoi/*' => Http::response([], 200),
        '*sardegnasentieri.it*' => Http::response([], 200),
    ]);
}

it('nell import orario senza job il registro parte comunque', function () {
    Bus::fake();
    fakeSorgenteSenzaNovita();

    $this->artisan('sardegnasentieri:import')
        ->expectsOutputToContain('Nessun job da importare.')
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertDispatched(ImportRegistroCatastaleJob::class);
});

it('con --reset e nessun job il registro non parte', function () {
    Bus::fake();
    fakeSorgenteSenzaNovita();

    $this->artisan('sardegnasentieri:import', ['--reset' => true])
        ->expectsOutputToContain('Nessun job da importare.')
        ->assertSuccessful();

    Bus::assertNothingBatched();
    Bus::assertNotDispatched(ImportRegistroCatastaleJob::class);
});

it('con --only e nessun job il registro non parte', function () {
    Bus::fake();
    fakeSorgenteSenzaNovita();

    $this->artisan('sardegnasentieri:import', ['--only' => 'tracks'])
        ->expectsOutputToContain('Nessun job da importare.')
        ->assertSuccessful();

    Bus::assertNotDispatched(ImportRegistroCatastaleJob::class);
});

it('il comando forestas:registro-import esegue l importer', function () {
    $result = new RegistroCatastaleImportResult(['Z-SU-D' => 2], 1, [], [['name' => 'Z-SU-D', 'gid' => '0', 'rows' => 2, 'registro' => true]]);

    $this->mock(RegistroCatastaleImporter::class, function ($mock) use ($result) {
        $mock->shouldReceive('run')->once()->andReturn($result);
    });

    $exitCode = Artisan::call('forestas:registro-import');

    expect($exitCode)->toBe(0);
});

it('il comando forestas:registro-import trova il lock occupato dal job ed esce in FAILURE senza importare', function () {
    $this->mock(RegistroCatastaleImporter::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    $job = new ImportRegistroCatastaleJob;
    $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 60);
    $lock->get();

    try {
        $exitCode = Artisan::call('forestas:registro-import');

        expect($exitCode)->toBe(1);
    } finally {
        $lock->release();
    }
});

it('un foglio irraggiungibile finisce sul canale import senza rilanciare', function () {
    $this->mock(RegistroCatastaleImporter::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andThrow(new RegistroCatastaleReadException('foglio giu'));
    });

    Log::shouldReceive('channel')->with('import')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'foglio giu'));

    (new ImportRegistroCatastaleJob)->handle(app(RegistroCatastaleImporter::class));
});

it('il job resta sotto il timeout del supervisor e non si sovrappone a se stesso', function () {
    $job = new ImportRegistroCatastaleJob;
    $supervisorTimeout = config('horizon.defaults.supervisor-sardegnasentieri-import.timeout');

    expect($supervisorTimeout)->toBeInt()
        ->and($job->timeout)->toBeLessThan($supervisorTimeout);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe(ImportRegistroCatastaleJob::OVERLAP_KEY);
});

it('un giro che trova il lock occupato non esegue l importer', function () {
    $this->mock(RegistroCatastaleImporter::class, function ($mock) {
        $mock->shouldReceive('run')->never();
    });

    $job = new ImportRegistroCatastaleJob;
    $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 60);
    $lock->get();

    try {
        dispatch_sync($job);
    } finally {
        $lock->release();
    }
});
