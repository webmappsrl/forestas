<?php

use App\Console\Commands\ImportSardegnaSentieriCommand;
use App\Jobs\Import\ImportSardegnaSentieriPoiJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

/**
 * `--reset` tronca i tracciati e con essi le anomalie del Catasto, che
 * dipendono da `ec_tracks` con una foreign key a cascata. A ricalcolarle e'
 * `trail-registry-normalize`, e il momento in cui va chiamato non e' la fine
 * del comando — che ritorna appena ha accodato i job — ma la fine dei job.
 *
 * Questi test presidiano quell'aggancio (oc:8607).
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

    // Le liste restituiscono un elemento per tipo: serve solo che qualche job
    // venga prodotto, non che l'import scriva davvero. Le tassonomie tornano
    // vuote, cosi' `importAll()` non ha nulla da costruire.
    Http::fake([
        '*/tassonomia/*' => Http::response([], 200),
        '*/list-tracks/*' => Http::response(['1' => '2026-01-01T00:00:00'], 200),
        '*/listpoi/*' => Http::response(['2' => '2026-01-01T00:00:00'], 200),
        '*sardegnasentieri.it*' => Http::response([], 200),
    ]);

    // Il backup che `--reset` esegue prima di troncare: qui deve riuscire.
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
});

it('rende raggruppabili in batch i job di import', function () {
    expect(in_array(Batchable::class, class_uses_recursive(ImportSardegnaSentieriTrackJob::class), true))->toBeTrue()
        ->and(in_array(Batchable::class, class_uses_recursive(ImportSardegnaSentieriPoiJob::class), true))->toBeTrue();
});

it('raggruppa in un batch i job dispatchati da --reset', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    Bus::assertBatchCount(1);
});

it('non raggruppa nulla senza --reset', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import');

    Bus::assertNothingBatched();
});

it('raggruppa i job anche con --only=pois, senza ricalcolare le anomalie', function () {
    Bus::fake();

    Artisan::call('sardegnasentieri:import', ['--reset' => true, '--only' => 'pois']);

    // Il batch parte comunque per i POI: e' il ricalcolo a essere saltato,
    // perche' i tracciati sono stati troncati e non reimportati.
    Bus::assertBatchCount(1);
});

it('azzera anche le domande di iscrizione', function () {
    Bus::fake();

    DB::table('trail_applications')->insert([
        'user_id' => User::query()->value('id'),
        'source' => 'office',
        'status' => 'under_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect(DB::table('trail_applications')->count())->toBe(0);
});

it('ricalcola le anomalie se i job falliti stanno sotto la soglia', function () {
    // Il caso reale osservato in locale: 26 timeout della sorgente su 1736
    // job, cioe' l'1,5%. Rinunciare al ricalcolo per questo lascerebbe la
    // lista vuota, che e' il difetto corretto da oc:8607.
    expect(ImportSardegnaSentieriCommand::shouldNormalize(26, 1736))->toBeTrue()
        ->and(ImportSardegnaSentieriCommand::shouldNormalize(0, 1736))->toBeTrue();
});

it('non ricalcola le anomalie se i job falliti superano la soglia', function () {
    expect(ImportSardegnaSentieriCommand::shouldNormalize(500, 1736))->toBeFalse()
        ->and(ImportSardegnaSentieriCommand::shouldNormalize(40, 1000))->toBeFalse();
});

it('distanzia i ritentativi dei job di import', function () {
    // Cinque tentativi tutti dentro la stessa finestra di pochi secondi
    // valgono quanto un tentativo solo contro una sorgente satura.
    foreach ([new ImportSardegnaSentieriTrackJob(1), new ImportSardegnaSentieriPoiJob(1)] as $job) {
        expect($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([30, 120, 300, 600]);
    }
});
