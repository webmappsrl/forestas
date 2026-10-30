<?php

use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

// Mentre scripts/drupal-dump-reload.sh ricarica la copia, il database è vuoto
// o caricato a metà: l'import non deve leggerlo (oc:8900).

beforeEach(function () {
    $this->lockFile = storage_path('framework/testing/reload-'.uniqid().'.lock');
    config(['forestas.sardegnasentieri.reload_lock_path' => $this->lockFile]);
    File::ensureDirectoryExists(dirname($this->lockFile));
});

afterEach(function () {
    File::delete($this->lockFile);
});

function lockTestApp(): void
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    $user->assignRole('Editor');
    App::query()->find(SardegnaSentieriImportService::IMPORT_APP_ID) ?? App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));
}

it('il comando salta e lo dice se la copia si sta ricaricando', function () {
    lockTestApp();
    File::put($this->lockFile, '');
    Bus::fake();
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldNotReceive('getTrackList', 'getPoiList', 'getTaxonomy', 'isAvailable');
    $this->instance(SardegnaSentieriSource::class, $source);
    $this->artisan('sardegnasentieri:import')
        ->expectsOutputToContain('Copia del DB Drupal in ricarica dal')
        ->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('il comando fallisce se il lock è rimasto da una ricarica fallita', function () {
    lockTestApp();
    File::put($this->lockFile, '');
    touch($this->lockFile, now()->subHours(3)->getTimestamp());
    Bus::fake();
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldNotReceive('getTrackList', 'getPoiList', 'getTaxonomy', 'isAvailable');
    $this->instance(SardegnaSentieriSource::class, $source);

    $this->artisan('sardegnasentieri:import')
        ->expectsOutputToContain('ricarica fallita?')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

it('il comando legge la copia quando non c\'è il lock', function () {
    lockTestApp();
    Bus::fake();
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldReceive('isAvailable')->andReturn(true);
    $source->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);
    $source->shouldReceive('getPoiList')->once()->andReturn([]);
    $source->shouldReceive('getTrackList')->once()->andReturn([]);
    $this->instance(SardegnaSentieriSource::class, $source);

    $this->artisan('sardegnasentieri:import')->assertExitCode(0);
});

it('il job si rimette in coda se la copia si sta ricaricando', function () {
    File::put($this->lockFile, '');
    $source = Mockery::mock(SardegnaSentieriSource::class);
    // Nessuna aspettativa: una chiamata a getTrackDetail fallirebbe il test.
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(600);

    $job = new ImportSardegnaSentieriTrackJob(75);
    $job->setJob($queueJob);
    $job->handle($source, app(SardegnaSentieriImportService::class));
});
