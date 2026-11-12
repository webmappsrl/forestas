<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\UpdateLayerGeometryJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Models\LayerService;

/**
 * La mappa della scheda di un Layer, quando `layerables` è ancora vuoto (oc:8910).
 *
 * Succede subito dopo aver salvato i filtri e dopo un reset, prima che il ricalcolo passi: la
 * mappa non resta vuota, ricava le tracce dai filtri. Quel calcolo era però scritto una seconda
 * volta dentro il modello, e divergeva da `LayerService`: teneva tutti i termini in un `whereIn`
 * unico — OR fra tassonomie diverse invece di AND — e mostrava più tracce del dovuto. Peggio,
 * le scriveva nel pivot con un `sync()`, dentro una GET: `LayerableObserver` scattava riga per
 * riga, riscrivendo `properties.layers` e accodando un job di geometria e una catena PBF per
 * ogni traccia. Il risultato sbagliato finiva così nei dati che leggono l'app e le API.
 */
uses(RefreshDatabase::class);

function layerWithTwoFiltersShard(): array
{
    config(['wm-package.shard_name' => 'test_shard']);
    Storage::fake('pois');
    Storage::fake('wmfe');
    Storage::fake('conf');

    $suffix = uniqid();
    $app = App::factory()->createQuietly(['map_bbox' => '[8.0,38.8,9.9,41.3]']);
    $layer = Layer::factory()->createQuietly(['app_id' => $app->id, 'properties' => [], 'configuration' => []]);

    $routeType = TaxonomyTerm::create(['identifier' => "route-type-{$suffix}", 'name' => ['it' => 'Tipo percorso']]);
    $trail = TaxonomyTerm::create(['parent_id' => $routeType->id, 'identifier' => "rt-trail-{$suffix}", 'name' => ['it' => 'Sentiero']]);
    $difficulty = TaxonomyTerm::create(['identifier' => "mtb-difficulty-{$suffix}", 'name' => ['it' => 'Difficoltà MTB']]);
    $bc = TaxonomyTerm::create(['parent_id' => $difficulty->id, 'identifier' => "mtb-bc-{$suffix}", 'name' => ['it' => 'BC']]);

    $soloSentiero = [];
    for ($i = 0; $i < 3; $i++) {
        $t = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
        $t->taxonomyTerms()->attach($trail->id);
        $soloSentiero[] = $t->id;
    }
    $sentieroBc = [];
    for ($i = 0; $i < 2; $i++) {
        $t = EcTrack::factory()->createQuietly(['app_id' => $app->id, 'osmid' => null]);
        $t->taxonomyTerms()->attach([$trail->id, $bc->id]);
        $sentieroBc[] = $t->id;
    }

    // «Sentiero E difficoltà BC»: solo le due che hanno entrambi i termini.
    $layer->taxonomyTerms()->attach([$trail->id, $bc->id]);
    $layer->load('taxonomyTerms');

    return [$layer->fresh(), $sentieroBc, $soloSentiero];
}

it('non tocca properties.layers delle tracce (oc:8910)', function () {
    [$layer, $sentieroBc, $soloSentiero] = layerWithTwoFiltersShard();

    $layer->getFeatureCollectionMap();

    foreach ([...$sentieroBc, ...$soloSentiero] as $id) {
        expect(EcTrack::whereKey($id)->value('properties')['layers'] ?? [])->not->toContain($layer->id);
    }
});

it('non accoda job di geometria né catene PBF (oc:8910)', function () {
    [$layer] = layerWithTwoFiltersShard();
    Bus::fake();

    $layer->getFeatureCollectionMap();

    Bus::assertNotDispatched(UpdateLayerGeometryJob::class);
});
