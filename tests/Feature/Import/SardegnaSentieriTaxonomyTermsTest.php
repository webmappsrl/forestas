<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * L'import scrive i legami dei termini leggendo la gerarchia di
 * `categorie_fruibilita_sentieri` (oc:8910). La fonte è finta: i tid sono
 * quelli veri della copia di Drupal.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);

    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();
});

/**
 * Importa un sentiero con questi tid di `field_frubilita`.
 *
 * @param  list<int>  $tids
 * @param  array<int, int>  $parents  tid => tid del padre (0 per una radice); i tid non indicati hanno la radice vera
 */
function importaConFruibilita(array $tids, array $parents = [], int $nid = 75, ?SardegnaSentieriImportService $service = null, string $bundle = 'sentiero', array $tipologiaItinerari = []): EcTrack
{
    // La gerarchia vera del tassonomia.
    $gerarchia = [
        4066 => 4061, 4067 => 4061, 4068 => 4061, 4069 => 4061,
        4908 => 4064, 4909 => 4064, 4910 => 4064, 4911 => 4064, 4912 => 4064,
        4902 => 4065, 4903 => 4065, 4904 => 4065,
        4905 => 4062, 4906 => 4062, 4907 => 4062,
        4916 => 4060, 4917 => 4060, 4918 => 4060,
        4897 => 4890, 4900 => 4890, 4893 => 4890,
        4896 => 4894, 4892 => 4894, 4898 => 4894,
        4891 => 4895, 4901 => 4895, 4899 => 4895,
        4061 => 0, 4064 => 0, 4065 => 0, 4062 => 0, 4060 => 0, 4059 => 0, 4890 => 0, 4894 => 0, 4895 => 0,
    ];
    $padri = [];
    foreach ($tids as $tid) {
        $padri[(string) $tid] = (string) ($parents[$tid] ?? $gerarchia[$tid] ?? 0);
    }

    $feature = ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]],
        'properties' => [
            'id' => (string) $nid,
            'name' => ['it' => 'Sentiero Test'],
            'type' => $bundle,
            'updated_at' => '2026-10-01T10:00:00',
            'taxonomies' => [
                'tipologia_itinerari' => array_map('strval', $tipologiaItinerari),
                'categorie_fruibilita_sentieri' => array_map('strval', $tids),
                'fruibilita_padri' => $padri,
            ],
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class.', '.SardegnaSentieriSource::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);

    $media = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $media->shouldIgnoreMissing();

    $service ??= new SardegnaSentieriImportService($client, $client, $media);

    return $service->importTrack($nid);
}

function identifiersOf(EcTrack $track, string $taxonomy): array
{
    return $track->fresh()->terms($taxonomy)->pluck('identifier')->sort()->values()->all();
}

it('scrive la difficoltà nel tassonomia giusta', function () {
    // 4909 = MTB_MC_trail_Grado2
    $track = importaConFruibilita([4909]);

    expect($track->fresh()->term('mtb-difficulty')->identifier)->toBe('sardegnasentieri:tid:4909')
        ->and($track->fresh()->term('mtb-difficulty')->getTranslation('name', 'it'))->toBe('MC')
        ->and($track->fresh()->taxonomyTerms)->toHaveCount(2);
});

it('porta una difficoltà per ogni modo di fruizione', function () {
    $track = importaConFruibilita([4067, 4909, 4903, 4906, 4917]);

    expect(identifiersOf($track, 'hiking-difficulty'))->toBe(['sardegnasentieri:tid:4067'])
        ->and(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4909'])
        ->and(identifiersOf($track, 'horse-difficulty'))->toBe(['sardegnasentieri:tid:4903'])
        ->and(identifiersOf($track, 'nordic-walking-difficulty'))->toBe(['sardegnasentieri:tid:4906'])
        ->and(identifiersOf($track, 'soft-mobility-difficulty'))->toBe(['sardegnasentieri:tid:4917']);
});

it('collassa le tre scale di accessibilità in grado più categoria', function () {
    // «Accessibile dis. Sensoriali → AE» (nodo 3901)
    $track = importaConFruibilita([4892]);

    expect($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-ae')
        ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(['inclusivity-disabilities-sensory']);
});

it('mappa tutti e nove i tid di accessibilità sul grado e sulla categoria giusti', function () {
    $attesi = [
        4897 => ['at', 'physical'], 4900 => ['ae', 'physical'], 4893 => ['aee', 'physical'],
        4896 => ['at', 'sensory'], 4892 => ['ae', 'sensory'], 4898 => ['aee', 'sensory'],
        4891 => ['at', 'cognitive'], 4901 => ['ae', 'cognitive'], 4899 => ['aee', 'cognitive'],
    ];

    $nid = 100;
    foreach ($attesi as $tid => [$grado, $categoria]) {
        $track = importaConFruibilita([$tid], nid: $nid++);

        expect($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe("inclusivity-difficulty-{$grado}", "tid {$tid}")
            ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(["inclusivity-disabilities-{$categoria}"], "tid {$tid}");
    }
});

it('accoglie più categorie di disabilità con lo stesso grado senza segnalare niente', function () {
    // «motorie AE» e «cognitiva AE»: un solo grado, due categorie.
    $track = importaConFruibilita([4900, 4901]);

    expect($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-ae')
        ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(['inclusivity-disabilities-cognitive', 'inclusivity-disabilities-physical'])
        ->and($track->fresh()->properties['forestas'] ?? [])->not->toHaveKey(SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY);
});

it('ignora un legame a una radice: nessun grado e nessuna attività in più', function () {
    // Le attività vengono da field_tipologia_sentiero e dal tipo del nodo: la radice non le tocca.
    $senzaRighe = importaConFruibilita([], nid: 75)->fresh()->taxonomyActivities->pluck('identifier')->sort()->values()->all();

    // Le radici di tutti i modi di fruizione, sentiero didattico e mobilità dolce compresi.
    $track = importaConFruibilita([4064, 4061, 4065, 4062, 4060, 4059], nid: 75);

    expect($senzaRighe)->toBe(['sardegnasentieri:type:sentiero'])
        ->and(identifiersOf($track, 'mtb-difficulty'))->toBeEmpty()->and($track->fresh()->taxonomyTerms)->toHaveCount(1)
        ->and($track->fresh()->properties['forestas'] ?? [])->not->toHaveKey(SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY)
        ->and($track->fresh()->taxonomyActivities->pluck('identifier')->sort()->values()->all())->toBe($senzaRighe)
        ->and(TaxonomyActivity::whereIn('identifier', ['trekking', 'mtb', 'horse', 'nordic-walking', 'educational-trails', 'soft-mobility'])->count())->toBe(0);
});

it('lascia una radice accanto a un grado: scrive solo il grado', function () {
    $track = importaConFruibilita([4064, 4909]);

    expect(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4909'])
        ->and($track->fresh()->taxonomyTerms)->toHaveCount(2);
});

it('non accumula doppioni a un reimport', function () {
    importaConFruibilita([4909, 4067], nid: 75);
    $track = importaConFruibilita([4909, 4067], nid: 75);

    expect($track->fresh()->terms('mtb-difficulty'))->toHaveCount(1)
        ->and($track->fresh()->taxonomyTerms)->toHaveCount(3)
        ->and(EcTrack::count())->toBe(1);
});

it('sostituisce i legami di un tassonomia quando in Drupal cambiano', function () {
    importaConFruibilita([4909, 4067], nid: 75);
    $track = importaConFruibilita([4910], nid: 75);

    expect(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4910'])
        // Drupal non dice più niente di trekking: la sua tassonomia si svuota.
        ->and($track->fresh()->terms('hiking-difficulty'))->toBeEmpty();
});

it('non lascia nascere un doppione: lo rifiuta il database', function () {
    // Prima il doppione si poteva scrivere e lo ripuliva l'import. Ora lo vieta l'unique su
    // (taxonomy_term_id, termable_type, termable_id): la garanzia non dipende più dal codice.
    $track = importaConFruibilita([4909], nid: 75);
    $term = TaxonomyTerm::where('identifier', 'sardegnasentieri:tid:4909')->first();

    expect(fn () => $track->taxonomyTerms()->attach($term->id))
        ->toThrow(UniqueConstraintViolationException::class);
    // Niente altre query dopo: in PostgreSQL la violazione aborta la transazione del test.
});

it('non tocca i legami dei tassonomie che non vengono da Drupal', function () {
    $track = importaConFruibilita([4909], nid: 75);
    $manuale = TaxonomyTerm::where('identifier', 'educational-trails-difficulty-medium')->first();
    // Un tassonomia creato a mano da Nova, che l'import non conosce.
    $own = TaxonomyTerm::create(['identifier' => 'tematismi-miei', 'name' => ['it' => 'Tematismi miei']]);
    $theme = TaxonomyTerm::create(['identifier' => 'tema-uno', 'parent_id' => $own->id, 'name' => ['it' => 'Uno']]);
    $track->taxonomyTerms()->attach([$manuale->id, $theme->id]);

    $track = importaConFruibilita([4910], nid: 75);

    expect(identifiersOf($track, 'educational-trails-difficulty'))->toBe(['educational-trails-difficulty-medium'])
        ->and(identifiersOf($track, 'tematismi-miei'))->toBe(['tema-uno'])
        ->and(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4910']);
});

it('non cancella grado e categorie messi a mano se Drupal non porta accessibilità', function () {
    $track = importaConFruibilita([4909], nid: 75);
    $manuali = TaxonomyTerm::whereIn('identifier', ['inclusivity-difficulty-aee', 'inclusivity-disabilities-physical', 'inclusivity-disabilities-cognitive'])->pluck('id')->all();
    $track->taxonomyTerms()->attach($manuali);

    // Reimport: Drupal parla solo di mountain bike.
    $track = importaConFruibilita([4910], nid: 75);

    expect($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-aee')
        ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(['inclusivity-disabilities-cognitive', 'inclusivity-disabilities-physical'])
        // Le difficoltà invece seguono Drupal.
        ->and(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4910']);

    // Nemmeno con field_frubilita vuoto.
    $track = importaConFruibilita([], nid: 75);

    expect($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-aee')
        ->and($track->fresh()->terms('mtb-difficulty'))->toBeEmpty();
});

it('sostituisce grado e categorie messi a mano se Drupal porta un tid di accessibilità', function () {
    $track = importaConFruibilita([4909], nid: 75);
    $manuali = TaxonomyTerm::whereIn('identifier', ['inclusivity-difficulty-aee', 'inclusivity-disabilities-physical', 'inclusivity-disabilities-cognitive'])->pluck('id')->all();
    $track->taxonomyTerms()->attach($manuali);

    // «Accessibile dis. Sensoriali → AE»
    $track = importaConFruibilita([4909, 4892], nid: 75);

    expect($track->fresh()->terms('inclusivity-difficulty'))->toHaveCount(1)
        ->and($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-ae')
        ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(['inclusivity-disabilities-sensory']);
});

it('segnala come anomalia due gradi nella stessa tassonomia e non muore', function () {
    $canale = Mockery::spy();
    Log::shouldReceive('channel')->with('import')->andReturn($canale);

    // Due gradi nella stessa scala a scelta singola, uno dopo il collasso: AT motorie e AEE sensoriali.
    $track = importaConFruibilita([4897, 4898]);

    $anomalie = $track->fresh()->properties['forestas'][SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY];

    expect($anomalie)->toHaveCount(1)
        ->and($anomalie[0])->toMatchArray([
            'type' => 'multiple_terms_single_choice',
            'taxonomy' => 'inclusivity-difficulty',
            'kept' => 'inclusivity-difficulty-at',
            'discarded' => ['inclusivity-difficulty-aee'],
        ])
        // Entra un grado solo, le due categorie restano.
        ->and($track->fresh()->terms('inclusivity-difficulty'))->toHaveCount(1)
        ->and($track->fresh()->term('inclusivity-difficulty')->identifier)->toBe('inclusivity-difficulty-at')
        ->and(identifiersOf($track, 'inclusivity-disabilities'))->toBe(['inclusivity-disabilities-physical', 'inclusivity-disabilities-sensory']);

    $canale->shouldHaveReceived('warning')->once();
});

it('segnala due gradi nella stessa tassonomia di una difficoltà', function () {
    // Due gradi mountain bike su un sentiero: il caso dei due nodi Drupal.
    $track = importaConFruibilita([4909, 4911]);

    $anomalie = $track->fresh()->properties['forestas'][SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY];

    expect($anomalie)->toHaveCount(1)
        ->and($anomalie[0]['taxonomy'])->toBe('mtb-difficulty')
        ->and(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4909']);
});

it('toglie il segno di anomalia quando Drupal viene corretto', function () {
    importaConFruibilita([4909, 4911], nid: 75);
    $track = importaConFruibilita([4909], nid: 75);

    expect($track->fresh()->properties['forestas'] ?? [])->not->toHaveKey(SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY);
});

it('segnala un tid che il modello non conosce e scrive il resto', function () {
    // 4999 è figlio di Trekking ma non ha un termine; 4777 è una radice nuova, e si ignora come le altre.
    $track = importaConFruibilita([4999, 4777, 4909], parents: [4999 => 4061, 4777 => 0]);

    $anomalie = $track->fresh()->properties['forestas'][SardegnaSentieriImportService::CLASSIFICATION_ANOMALIES_KEY];

    expect($anomalie)->toEqual([
        ['type' => 'unknown_term', 'tid' => '4999'],
    ])->and(identifiersOf($track, 'mtb-difficulty'))->toBe(['sardegnasentieri:tid:4909']);
});

it('non scrive legami e non muore se i tassonomie non esistono', function () {
    TaxonomyTerm::query()->delete();

    $track = importaConFruibilita([4909]);

    expect($track->fresh()->taxonomyTerms)->toBeEmpty();
});

it('non fa partire il reindex per ogni riga di pivot', function () {
    config(['scout.queue' => false]);
    $engine = new class extends NullEngine
    {
        public array $updated = [];

        public function update($models)
        {
            foreach ($models as $model) {
                $this->updated[] = $model;
            }
        }
    };
    config(['scout.driver' => 'fake']);
    app()->singleton(EngineManager::class, fn ($app) => new EngineManager($app));
    app(EngineManager::class)->extend('fake', fn () => $engine);

    importaConFruibilita([4067, 4909, 4903, 4892]);

    expect($engine->updated)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// La fonte: la gerarchia viene dal record completo, senza query nuove
// ---------------------------------------------------------------------------

function recordConFruibilita(array $terms): array
{
    $items = [];
    foreach ($terms as $delta => [$tid, $parentItems, $langcode]) {
        $items[$delta] = [
            'target_id' => $tid,
            'entity' => [
                'entity_type' => 'taxonomy_term',
                'id' => $tid,
                'bundle' => 'categorie_fruibilita_sentieri',
                'langcode' => $langcode,
                'translations' => [],
                'fields' => $parentItems === null ? [] : ['parent' => $parentItems],
            ],
        ];
    }

    return [
        'entity_type' => 'node',
        'id' => 75,
        'bundle' => 'sentiero',
        'langcode' => 'it',
        'translations' => ['it' => ['title' => 'Sentiero', 'created' => 1760000000, 'changed' => 1760000000]],
        'fields' => ['field_frubilita' => ['it' => $items]],
        'aliases' => [],
    ];
}

it('legge dal record del nodo il padre di ogni termine di fruibilità', function () {
    $record = recordConFruibilita([
        0 => [4909, ['it' => [0 => ['target_id' => '4064']]], 'it'],
        1 => [4064, ['it' => [0 => ['target_id' => '0']]], 'it'],
        2 => [4059, null, 'it'],                                         // nessuna riga parent: radice
        3 => [4892, ['und' => [0 => ['target_id' => '4894']]], 'it'],     // lingua diversa dalla originale
    ]);

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(75)->andReturn($record);

    $detail = (new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(75);

    expect($detail->taxonomies->categorie_fruibilita_sentieri)->toBe(['4909', '4064', '4059', '4892'])
        ->and($detail->taxonomies->fruibilita_padri)->toBe(['4909' => '4064', '4064' => '0', '4059' => '0', '4892' => '4894']);
});

it('non dà padre a un termine che la copia non ha più', function () {
    $record = recordConFruibilita([0 => [4909, ['it' => [0 => ['target_id' => '4064']]], 'it']]);
    unset($record['fields']['field_frubilita']['it'][0]['entity']);
    $record['fields']['field_frubilita']['it'][0]['target_id'] = '4777';

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(75)->andReturn($record);

    $detail = (new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(75);

    expect($detail->taxonomies->fruibilita_padri)->toBe([]);
});

it('un sentiero prende route-type-trail', function () {
    $track = importaConFruibilita([]);

    expect(identifiersOf($track, 'route-type'))->toBe(['route-type-trail']);
});

it('un itinerario senza il termine 4728 prende route-type-itinerary', function () {
    $track = importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4729]);

    expect(identifiersOf($track, 'route-type'))->toBe(['route-type-itinerary']);
});

it('un itinerario con il termine 4728 prende route-type-walk e non route-type-itinerary', function () {
    $track = importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4729, 4728]);

    expect(identifiersOf($track, 'route-type'))->toBe(['route-type-walk']);
});

it('il reimport del tipo percorso non accumula doppioni e segue il cambio di tipo', function () {
    $track = importaConFruibilita([], bundle: 'itinerario');
    importaConFruibilita([], bundle: 'itinerario');
    expect(identifiersOf($track, 'route-type'))->toBe(['route-type-itinerary']);

    importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4728]);
    expect(identifiersOf($track, 'route-type'))->toBe(['route-type-walk'])
        ->and($track->fresh()->taxonomyTerms()->count())->toBe(1);
});

it('un itinerario con due tipologie le prende entrambe', function () {
    $track = importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4725, 4727]);

    expect(identifiersOf($track, 'itinerary-type'))->toBe(['sardegnasentieri:tid:4725', 'sardegnasentieri:tid:4727']);
});

it('il 4728 non diventa mai una tipologia di itinerario', function () {
    $track = importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4728, 4725]);

    expect(identifiersOf($track, 'itinerary-type'))->toBe(['sardegnasentieri:tid:4725'])
        ->and(identifiersOf($track, 'route-type'))->toBe(['route-type-walk']);

    $solo = importaConFruibilita([], nid: 76, bundle: 'itinerario', tipologiaItinerari: [4728]);
    expect(identifiersOf($solo, 'itinerary-type'))->toBe([]);
});

it('un sentiero non prende nessuna tipologia di itinerario', function () {
    $track = importaConFruibilita([], bundle: 'sentiero', tipologiaItinerari: [4725]);

    expect(identifiersOf($track, 'itinerary-type'))->toBe([]);
});

it('il reimport delle tipologie non accumula doppioni e segue le modifiche', function () {
    $track = importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4725, 4727]);
    importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4725, 4727]);
    expect(identifiersOf($track, 'itinerary-type'))->toHaveCount(2);

    importaConFruibilita([], bundle: 'itinerario', tipologiaItinerari: [4727]);
    expect(identifiersOf($track, 'itinerary-type'))->toBe(['sardegnasentieri:tid:4727']);
});
