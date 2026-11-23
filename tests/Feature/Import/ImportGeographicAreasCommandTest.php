<?php

declare(strict_types=1);

use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Models\User;
use App\Services\Import\GeographicAreasImportService;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;

uses(RefreshDatabase::class);

/**
 * Il passo delle aree geografiche nel comando di import (oc:9001). Il service è finto: qui si
 * verifica quando il comando lo chiama e cosa fa se fallisce, non cosa scrive.
 */
beforeEach(function () {
    Bus::fake();
    $this->areas = Mockery::mock(GeographicAreasImportService::class);
    $this->instance(GeographicAreasImportService::class, $this->areas);
});

function geographicAreasCommandApp(): void
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
    $user->assignRole('Editor');
    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID, 'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
    ]));
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
}

it('rifiuta --only=aree insieme a --reset', function () {
    // Il reset tronca tracce e POI qualunque sia --only: con le sole aree non li reimporterebbe.
    $this->areas->shouldNotReceive('run');

    $this->artisan('sardegnasentieri:import', ['--only' => 'aree', '--reset' => true])->assertFailed();

    Bus::assertNothingDispatched();
});

/**
 * Luoghi di tracce e POI, layer e reindex, nell'ordine: senza il reindex l'API di ricerca filtra
 * ancora sul vecchio `layers` dell'indice, e il click sull'area apre un layer senza percorsi.
 */
function assertGeographicAreasChain(): void
{
    Bus::assertChained([
        SyncTaxonomyWhereJob::class,
        RecalculateSardegnaSentieriLayersJob::class,
        RegenerateTaxonomyWhereOutputsJob::class,
    ]);
}

it('con --only=aree porta le aree anche senza la copia Drupal, e accoda luoghi, layer e reindex', function () {
    // Nessuna app e nessuna copia Drupal: l'import normale si fermerebbe sui prerequisiti.
    $this->areas->shouldReceive('run')->once()->andReturn(['areas' => ['created' => 39], 'layers_created' => 39]);

    $this->artisan('sardegnasentieri:import', ['--only' => 'aree'])->assertSuccessful();

    assertGeographicAreasChain();
    Bus::assertNothingBatched();
});

it('con --only=aree esce con errore se il passo delle aree fallisce', function () {
    $this->areas->shouldReceive('run')->once()->andThrow(new RuntimeException('Geohub non risponde'));

    $this->artisan('sardegnasentieri:import', ['--only' => 'aree'])->assertFailed();

    Bus::assertNothingDispatched();
});

it('senza job da importare accoda luoghi, layer e reindex se sono nati layer', function () {
    geographicAreasCommandApp();
    fakeSardegnaSentieriSource();
    $this->areas->shouldReceive('run')->once()->andReturn(['areas' => ['created' => 2], 'layers_created' => 2]);

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    assertGeographicAreasChain();
});

it('l\'import prosegue se il passo delle aree fallisce', function () {
    geographicAreasCommandApp();
    fakeSardegnaSentieriSource(['1836' => '2026-10-30 10:00:00']);
    $this->areas->shouldReceive('run')->once()->andThrow(new RuntimeException('Geohub non risponde'));

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $b) => $b->jobs->first() instanceof ImportSardegnaSentieriTrackJob);
});

it('elenca anche aree fra i valori ammessi di --only', function () {
    $this->artisan('sardegnasentieri:import', ['--only' => 'foo'])
        ->expectsOutputToContain('aree')
        ->assertFailed();
});
