<?php

declare(strict_types=1);

use App\Jobs\Import\ImportSardegnaSentieriEnteJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/**
 * Gli enti si importano come sentieri e POI, in un batch che gira prima del
 * loro: i job di POI e sentieri collegano solo enti esistenti (oc:8513).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
    $user->assignRole('Editor');
    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID, 'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
    ]));
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
    Bus::fake();
});

function enteLocale(string $nid, string $changed, bool $sparito = false): Ente
{
    return Ente::create([
        'sardegnasentieri_id' => $nid,
        'name' => ['it' => "Ente {$nid}"],
        'properties' => ['forestas' => array_filter(['updated_at' => $changed, 'deleted_from_source' => $sparito ?: null])],
    ]);
}

it('accoda prima gli enti e poi POI e sentieri', function () {
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertChained([
        Bus::chainedBatch(fn (PendingBatch $b) => $b->queue() === ImportSardegnaSentieriTrackJob::QUEUE
            && $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriEnteJob
            && $b->jobs->first()->externalId === 5281 && $b->jobs->first()->changed === '2026-10-30 10:00:00'),
        Bus::chainedBatch(fn (PendingBatch $b) => $b->queue() === ImportSardegnaSentieriTrackJob::QUEUE
            && $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob),
    ]);
});

it('non riaccoda un ente non cambiato', function () {
    enteLocale('5281', '2026-10-30 10:00:00');
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertNothingChained();
    Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob);
});

it('senza POI e sentieri cambiati accoda solo gli enti', function () {
    fonteSardegnaSentieriFinta([], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriEnteJob);
});

it('marca gli enti spariti', function () {
    enteLocale('123', '2026-01-01 00:00:00');
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    expect(Ente::where('sardegnasentieri_id', '123')->first()->properties['forestas']['deleted_from_source'])->toBeTrue();
});

it('non marca nulla se la lista degli enti e vuota', function () {
    // Sentieri presenti e nessun ente: la copia e' a meta' o la query e'
    // rotta, non 93 enti spariti insieme.
    enteLocale('5281', '2026-10-30 10:00:00');
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], [], enti: []);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    expect(Ente::where('sardegnasentieri_id', '5281')->first()->properties['forestas'])->not->toHaveKey('deleted_from_source');
});

it('riaccoda un ente marcato sparito che e tornato in Drupal', function () {
    // Stesso changed: senza questa regola resterebbe marcato per sempre.
    enteLocale('5281', '2026-10-30 10:00:00', sparito: true);
    fonteSardegnaSentieriFinta([], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->first() instanceof ImportSardegnaSentieriEnteJob
        && $b->jobs->first()->externalId === 5281);
});

it('con --only=tracks importa comunque gli enti', function () {
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], ['2' => '2026-10-30 10:00:00'], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import', ['--only' => 'tracks'])->assertSuccessful();

    Bus::assertChained([
        Bus::chainedBatch(fn (PendingBatch $b) => $b->jobs->first() instanceof ImportSardegnaSentieriEnteJob),
        Bus::chainedBatch(fn (PendingBatch $b) => $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob),
    ]);
});

it('il reset svuota enteables e tiene gli enti', function () {
    $ente = enteLocale('5281', '2026-10-30 10:00:00');
    DB::table('enteables')->insert(['ente_id' => $ente->id, 'enteable_id' => 99, 'enteable_type' => 'Wm\\WmPackage\\Models\\EcTrack', 'ruolo' => 'gestore']);
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00'], [], enti: ['5281' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import', ['--reset' => true])->assertSuccessful();

    expect(Ente::find($ente->id))->not->toBeNull()
        ->and(DB::table('enteables')->count())->toBe(0);
    // Il reset rilegge tutto: anche l'ente non cambiato torna in coda.
    Bus::assertChained([
        Bus::chainedBatch(fn (PendingBatch $b) => $b->jobs->first() instanceof ImportSardegnaSentieriEnteJob),
        Bus::chainedBatch(fn (PendingBatch $b) => $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob),
    ]);
});

it('rimette in coda un sentiero rimasto senza un ente', function () {
    // Il sentiero ha saltato un ente mancante: stesso changed, ma va riletto
    // ora che l'ente puo' esserci (oc:8513).
    EcTrack::withoutEvents(fn () => DB::table('ec_tracks')->insert([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'user_id' => User::first()->id,
        'name' => json_encode(['it' => 'Sentiero']),
        'geometry' => DB::raw("ST_GeomFromText('LINESTRING Z(9.1 40.1 0, 9.2 40.2 0)', 4326)"),
        'properties' => json_encode(['sardegnasentieri_id' => '1836', 'forestas' => ['updated_at' => '2026-10-30 10:00:00', 'enti_mancanti' => true]]),
        'created_at' => now(), 'updated_at' => now(),
    ]));
    fonteSardegnaSentieriFinta(['1836' => '2026-10-30 10:00:00']);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->count() === 1 && $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob);
});
