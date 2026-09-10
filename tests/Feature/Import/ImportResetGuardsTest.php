<?php

use App\Http\Clients\SardegnaSentieriClient;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;

/**
 * `--reset` cancella e SOLO DOPO scarica: qualunque cosa vada storta in mezzo
 * lascia la piattaforma vuota. È successo davvero il 10/09/2026 sul collaudo,
 * con Drupal caduto subito dopo il troncamento — 763 tracciati e 970 punti
 * persi, recuperati copiandoli da un altro ambiente.
 *
 * Questi test presidiano le due guardie messe davanti al troncamento.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    // Il comando esce sui prerequisiti se l'app dell'import non esiste, e lo
    // farebbe prima di arrivare alle guardie che questi test presidiano.
    resetGuardsApp();
});

function resetGuardsApp(): App
{
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );

    if (! $user->hasRole('Editor')) {
        $user->assignRole('Editor');
    }

    $id = SardegnaSentieriImportService::IMPORT_APP_ID;

    return App::query()->find($id) ?? App::withoutEvents(fn () => App::query()->create([
        'id' => $id,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));
}

it('non cancella nulla se la sorgente non risponde', function () {
    Http::fake([
        '*sardegnasentieri.it*' => Http::response('Temporarily Unavailable', 503),
    ]);

    EcTrack::factory()->createQuietly();
    $prima = DB::table('ec_tracks')->count();

    $exit = Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('nessun dato e\' stato cancellato')
        ->and(DB::table('ec_tracks')->count())->toBe($prima);
});

it('non cancella nulla se il backup fallisce', function () {
    // Sorgente raggiungibile: la guardia che deve scattare è la seconda.
    Http::fake([
        '*sardegnasentieri.it*' => Http::response([], 200),
    ]);

    // Un backup che fallisce: il comando esiste, accetta le stesse opzioni
    // del vero, ed esce con errore.
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 1);

    EcTrack::factory()->createQuietly();
    $prima = DB::table('ec_tracks')->count();

    $exit = Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Backup fallito')
        ->and(DB::table('ec_tracks')->count())->toBe($prima);
});

it('riconosce una sorgente raggiungibile', function () {
    Http::fake(['*sardegnasentieri.it*' => Http::response([1, 2, 3], 200)]);

    expect(app(SardegnaSentieriClient::class)->isReachable())->toBeTrue();
});

it('non si fida della home: interroga un endpoint dei dati', function () {
    // Il 10/09 la home rispondeva 200 mentre ogni enddpoint dava 503: era una
    // pagina statica davanti all'applicazione ferma. Il controllo deve
    // chiedere i dati, non la facciata.
    Http::fake([
        'https://www.sardegnasentieri.it/ss/list-tracks*' => Http::response('', 503),
        '*' => Http::response('<html>ok</html>', 200),
    ]);

    expect(app(SardegnaSentieriClient::class)->isReachable())->toBeFalse();
});
