<?php

use App\Console\Commands\ImportSardegnaSentieriCommand;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\Source\SardegnaSentieriApiSource;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;

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
    fakeSardegnaSentieriSource(available: false);

    EcTrack::factory()->createQuietly();
    $before = DB::table('ec_tracks')->count();

    $exit = Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('nessun dato e\' stato cancellato')
        ->and(DB::table('ec_tracks')->count())->toBe($before);
});

it('nell import orario non marca nulla come sparito se la copia è vuota', function () {
    fakeSardegnaSentieriSource(available: false);

    $track = EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['sardegnasentieri_id' => '75'],
    ]);

    $exit = Artisan::call('sardegnasentieri:import');

    expect($exit)->toBe(1)
        ->and(data_get($track->fresh()->properties, 'forestas.deleted_from_source'))->toBeNull();
});

it('non cancella nulla se il backup fallisce', function () {
    // Sorgente raggiungibile: la guardia che deve scattare è la seconda.
    fakeSardegnaSentieriSource();

    // Un backup che fallisce: il comando esiste, accetta le stesse opzioni
    // del vero, ed esce con errore.
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 1);

    EcTrack::factory()->createQuietly();
    $before = DB::table('ec_tracks')->count();

    $exit = Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Backup fallito')
        ->and(DB::table('ec_tracks')->count())->toBe($before);
});

it('riconosce una sorgente raggiungibile', function () {
    Http::fake(['*sardegnasentieri.it*' => Http::response([1, 2, 3], 200)]);

    expect(app(SardegnaSentieriApiSource::class)->isAvailable())->toBeTrue();
});

it('non si fida della home: interroga un endpoint dei dati', function () {
    // Il 10/09 la home rispondeva 200 mentre ogni enddpoint dava 503: era una
    // pagina statica davanti all'applicazione ferma. Il controllo deve
    // chiedere i dati, non la facciata.
    Http::fake([
        'https://www.sardegnasentieri.it/ss/list-tracks*' => Http::response('', 503),
        '*' => Http::response('<html>ok</html>', 200),
    ]);

    expect(app(SardegnaSentieriApiSource::class)->isAvailable())->toBeFalse();
});

it('il reset cancella le relazioni dei termini di tracce e POI e ricrea la tassonomia', function () {
    fakeSardegnaSentieriSource();
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
    Bus::fake();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $term = TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->first()->children->first();
    $track = EcTrack::factory()->createQuietly();
    DB::table('taxonomy_termables')->insert([
        'taxonomy_term_id' => $term->id,
        'termable_type' => EcTrack::class,
        'termable_id' => $track->id,
    ]);

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect(DB::table('taxonomy_termables')->where('termable_type', EcTrack::class)->count())->toBe(0)
        ->and(TaxonomyTerm::taxonomies()->count())->toBe(11)
        ->and(TaxonomyTerm::count())->toBe(49);
});

it('il reset non cancella le relazioni dei termini di altri modelli', function () {
    fakeSardegnaSentieriSource();
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
    Bus::fake();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $term = TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->first()->children->first();
    $layerId = 1;

    // Gli altri termables (es. layer) non sono tracce o POI: il filtro li lascia.
    // Si verifica sul DELETE di resetData, non sul --fresh, che passa dal CASCADE.
    $method = new ReflectionMethod(ImportSardegnaSentieriCommand::class, 'resetData');
    $command = app(ImportSardegnaSentieriCommand::class);
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
    DB::table('taxonomy_termables')->insert([
        ['taxonomy_term_id' => $term->id, 'termable_type' => Layer::class, 'termable_id' => $layerId],
    ]);

    $method->invoke($command);

    expect(DB::table('taxonomy_termables')->where('termable_type', Layer::class)->count())->toBe(1)
        ->and(TaxonomyTerm::count())->toBe(49);
});

it('l import orario allinea i termini senza azzerarli', function () {
    fakeSardegnaSentieriSource();
    Bus::fake();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $term = TaxonomyTerm::query()->where('identifier', 'mtb-difficulty')->first()->children->first();
    $track = EcTrack::factory()->createQuietly();
    DB::table('taxonomy_termables')->insert([
        'taxonomy_term_id' => $term->id,
        'termable_type' => EcTrack::class,
        'termable_id' => $track->id,
    ]);
    $idBefore = $term->id;

    Artisan::call('sardegnasentieri:import');

    expect(DB::table('taxonomy_termables')->where('termable_type', EcTrack::class)->count())->toBe(1)
        ->and(TaxonomyTerm::find($idBefore))->not->toBeNull();
});

it('l import orario crea la tassonomia se manca', function () {
    fakeSardegnaSentieriSource();
    Bus::fake();

    Artisan::call('sardegnasentieri:import');

    expect(TaxonomyTerm::taxonomies()->count())->toBe(11);
});

it('il reset non porta via le relazioni dei layer: niente da rifare a mano dopo l import notturno', function () {
    // Il reset notturno chiamava il comando dei termini con `--fresh`, che svuota
    // `taxonomy_terms`: il pivot cade in cascata e ogni notte i layer restavano senza filtri,
    // con le mappe vuote fino a un `sardegnasentieri:configure-layers` lanciato a mano (oc:8910).
    fakeSardegnaSentieriSource();
    Artisan::command('wm:backup-run {--only-db} {--disable-notifications}', fn () => 0);
    Bus::fake();

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $term = TaxonomyTerm::query()->where('identifier', 'route-type-trail')->firstOrFail();
    $layer = Layer::factory()->createQuietly([
        'name' => ['it' => 'Sentieri'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
    ]);
    $layer->taxonomyTerms()->attach($term->id);

    Artisan::call('sardegnasentieri:import', ['--reset' => true]);

    expect(TaxonomyTerm::query()->where('identifier', 'route-type-trail')->value('id'))->toBe($term->id)
        ->and($layer->fresh()->taxonomyTerms()->pluck('identifier')->all())->toBe(['route-type-trail']);
});
