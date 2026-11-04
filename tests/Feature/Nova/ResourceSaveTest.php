<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
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
 * Per il dettaglio delle tassonomie sulla scheda del termine c'è `TaxonomyTermSaveTest`.
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

/** Il morph type con cui il package scrive il pivot: l'alias del morphMap, non la classe. */
function morphOf(object $model): string
{
    return $model->getMorphClass();
}

it('salva il tipo percorso sulla scheda di un sentiero', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->app1->id,
        'user_id' => $this->user->id,
        'properties' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->app1->id,
            'user' => $this->user->id,
            'taxonomy_route_type' => (string) $this->trail->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms('route-type')->pluck('identifier')->all())
        ->toBe(['route-type-trail']);
});

it('cambia il tipo percorso di un sentiero e non lascia il vecchio', function () {
    $track = EcTrack::factory()->createQuietly([
        'app_id' => $this->app1->id,
        'user_id' => $this->user->id,
        'properties' => [],
    ]);
    $track->taxonomyTerms()->attach($this->trail->id);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$track->id}", [
            'app' => $this->app1->id,
            'user' => $this->user->id,
            'taxonomy_route_type' => (string) $this->walk->id,
        ])
        ->assertOk();

    expect($track->fresh()->terms('route-type')->pluck('identifier')->all())
        ->toBe(['route-type-walk']);
});

it('salva la scheda di un POI', function () {
    // `properties` come array: la colonna porta anche due campi traducibili per path
    // (`properties->description`), e su una stringa il riempimento di Nova non regge.
    $poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->app1->id,
        'user_id' => $this->user->id,
        'name' => ['it' => 'Fonte'],
        'properties' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-pois/{$poi->id}", [
            'app' => $this->app1->id,
            'user' => $this->user->id,
            'translations_name_it' => 'Fonte vecchia',
        ])
        ->assertOk();

    expect($poi->fresh()->getTranslation('name', 'it'))->toBe('Fonte vecchia');
});

it('salva i filtri per termine sulla scheda di un Layer', function () {
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/layers/{$layer->id}", [
            'app' => $this->app1->id,
            'appOwner' => $this->app1->id,
            'translations_name_it' => 'Sentieri',
            // Su un layer la scelta è sempre multipla, anche per una tassonomia a scelta
            // singola: «sentieri o cammini» deve essere possibile.
            'taxonomy_route_type' => $this->trail->id.','.$this->walk->id,
        ])
        ->assertOk();

    expect($layer->fresh()->taxonomyTerms()->pluck('identifier')->sort()->values()->all())
        ->toBe(['route-type-trail', 'route-type-walk']);
});

it('scrive la tassonomia sulla relazione del Layer, cosi il filtro per famiglia funziona', function () {
    $layer = Layer::factory()->createQuietly(['app_id' => $this->app1->id]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/layers/{$layer->id}", [
            'app' => $this->app1->id,
            'appOwner' => $this->app1->id,
            'translations_name_it' => 'Sentieri',
            'taxonomy_route_type' => (string) $this->trail->id,
        ])
        ->assertOk();

    expect(DB::table('taxonomy_termables')
        ->where('termable_type', morphOf($layer))
        ->where('termable_id', $layer->id)
        ->value('parent_id'))->toBe($this->routeType->id);
});

it('accende e spegne le tassonomie dalla scheda di un App', function () {
    $other = TaxonomyTerm::create(['identifier' => 'surface-type', 'name' => ['it' => 'Fondo']]);

    $this->actingAs($this->user)
        ->putJson("/nova-api/apps/{$this->app1->id}", [
            'sku' => $this->app1->sku,
            'name' => 'App di prova',
            'default_language' => 'it',
            'start_end_icons_min_zoom' => 10,
            'ref_on_track_min_zoom' => 10,
            'taxonomies' => json_encode([$other->id]),
        ])
        ->assertOk();

    expect($this->app1->fresh()->taxonomies()->pluck('identifier')->all())
        ->toBe(['surface-type']);
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
