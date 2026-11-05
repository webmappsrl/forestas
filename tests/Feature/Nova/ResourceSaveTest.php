<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il salvataggio di ogni Resource Nova toccata da oc:8910, dall'endpoint vero (oc:8910).
 *
 * Nasce da un difetto sfuggito: una Resource Nova non è logica, è una dichiarazione che il
 * framework assembla, e i test che ne estraggono i pezzi — le `rules` di un campo date a
 * `Validator` — non provano che il montaggio avvenga. Un riferimento a una costante inesistente
 * passava quei test e rompeva ogni salvataggio dalla scheda. Qui si fa la richiesta che fa il
 * browser, una per Resource, e dove il ticket ha aggiunto qualcosa si verifica anche l'effetto.
 *
 * Qui restano solo le due tassonomie con tabella propria, che sono di Forestas, e la vista delle
 * avvertenze. Tutto il resto — `TaxonomyTerm`, `Layer`, `App` e la sezione delle tassonomie sulle
 * schede EC, che il package compone e lo shard eredita — ha il suo test nel package, in
 * `wm-package/tests/Feature/Nova/PackageResourceSaveTest.php`: gira da uno shard ma protegge
 * tutti.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Le Resource con Images arrivano a StorageService: senza un disco finto il salvataggio
    // muore sulla configurazione S3, non su ciò che si sta provando.
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');

    // I job unici del salvataggio prendono il lock su Redis (`uniqueVia()`): in test l'estensione
    // phpredis non c'è, e senza questa riga il salvataggio muore con «Class "Redis" not found».
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);

    RolesAndPermissionsService::seedDatabase();
    $this->user = User::factory()->create();
    $this->user->assignRole('Administrator');
    // Il bbox serve ai layer: senza, il salvataggio del layer si ferma con un errore d'uso.
    $this->app1 = App::factory()->createQuietly(['map_bbox' => '[10.39637,43.71683,10.52729,43.84512]']);

    $this->routeType = TaxonomyTerm::create([
        'identifier' => 'route-type',
        'name' => ['it' => 'Tipo percorso'],
        'single_choice' => true,
    ]);
    $this->trail = TaxonomyTerm::create([
        'parent_id' => $this->routeType->id,
        'identifier' => 'route-type-trail',
        'name' => ['it' => 'Sentiero'],
    ]);
    $this->walk = TaxonomyTerm::create([
        'parent_id' => $this->routeType->id,
        'identifier' => 'route-type-walk',
        'name' => ['it' => 'Cammino'],
    ]);
    // Le tassonomie si vedono solo se accese sull'app del contenuto.
    $this->app1->taxonomies()->sync([$this->routeType->id]);
});

it('salva la scheda di un\'attività, di un tipo di POI e di un\'avvertenza', function () {
    $activity = TaxonomyActivity::create(['identifier' => 'hiking', 'name' => ['it' => 'Escursionismo']]);
    $poiType = TaxonomyPoiType::create(['identifier' => 'spring', 'name' => ['it' => 'Sorgente']]);
    $warnings = TaxonomyTerm::create(['identifier' => 'warnings', 'name' => ['it' => 'Avvertenze']]);
    $warning = TaxonomyTerm::create([
        'parent_id' => $warnings->id,
        'identifier' => 'sardegnasentieri:warning:1',
        'name' => ['it' => 'Frana'],
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-activities/{$activity->id}", ['translations_name_it' => 'Trekking'])
        ->assertOk();
    $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-poi-types/{$poiType->id}", ['translations_name_it' => 'Fonte'])
        ->assertOk();
    // Le avvertenze sono termini del modello unico, con la loro vista dedicata.
    $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-warnings/{$warning->id}", [
            'identifier' => 'sardegnasentieri:warning:1',
            'sort_order' => 0,
            'translations_name_it' => 'Frana attiva',
        ])
        ->assertOk();

    expect($activity->fresh()->getTranslation('name', 'it'))->toBe('Trekking')
        ->and($poiType->fresh()->getTranslation('name', 'it'))->toBe('Fonte')
        ->and($warning->fresh()->getTranslation('name', 'it'))->toBe('Frana attiva');
});
