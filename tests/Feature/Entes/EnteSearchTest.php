<?php

declare(strict_types=1);

use App\Models\Ente;
use App\Search\EnteSearch;
use App\Search\EnteSearchQuery;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;

/**
 * La ricerca degli enti (oc:8965), oggi per il sito WordPress. Nei test Scout usa il driver `collection`:
 * Elasticsearch non serve, la ricerca vera si prova a mano (Task 5 del piano).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forestas.wp_api_token' => 'secret']);
});

/** Una ricerca finta che restituisce i `_source` dati, o lancia l'eccezione data. */
function fakeEnteSearch(array|Throwable $result): void
{
    app()->instance(EnteSearch::class, new class($result) extends EnteSearch
    {
        public function __construct(private array|Throwable $result) {}

        public function run(string $text): array
        {
            return $this->result instanceof Throwable ? throw $this->result : $this->result;
        }
    });
}

function searchEntes(TestCase $case, string $query): TestResponse
{
    return $case->getJson('/api/v1/entes/search?query='.urlencode($query), ['Authorization' => 'Bearer secret']);
}

it('aggiunge il mapping degli enti senza togliere quello delle tracce', function () {
    expect(config('elasticsearch.indices.mappings.entes.properties.name.fields.edge.analyzer'))->toBe('edge_ngram_analyzer')
        ->and(config('elasticsearch.indices.mappings.default'))->not->toBeNull()
        ->and(config('elasticsearch.indices.settings.default.analysis.analyzer.edge_ngram_analyzer'))->not->toBeNull();
});

it('non usa la sintassi Lucene', function () {
    $body = EnteSearchQuery::body('Comune di Oliena (NU');
    $encoded = json_encode($body);

    expect($encoded)->not->toContain('query_string')
        ->and($body['query']['bool']['must'][0]['multi_match']['query'])->toBe('Comune di Oliena (NU')
        ->and($body['query']['bool']['must'][0]['multi_match']['fields'])->toBe(['name^3', 'name.edge^2', 'description']);
});

it('non indicizza i contatti né gli enti spariti', function () {
    $ente = Ente::create([
        'sardegnasentieri_id' => '13',
        'slug' => 'oasi-tepilora',
        'name' => ['it' => 'Oasi Tepilora'],
        'description' => ['it' => '<p>Complesso <strong>forestale</strong></p>'],
        'contatti' => ['it' => '<p>info@x.it</p>'],
        'properties' => [],
    ]);
    $gone = Ente::create(['sardegnasentieri_id' => '2', 'name' => ['it' => 'Sparito'], 'properties' => ['forestas' => ['deleted_from_source' => true]]]);

    $document = $ente->toSearchableArray();

    expect($ente->searchableAs())->toBe('entes')
        ->and($document)->not->toHaveKey('contatti')
        ->and(json_encode($document))->not->toContain('info@x.it')
        ->and($document['name'])->toBe('Oasi Tepilora')
        ->and($document['description'])->toBe('Complesso forestale')
        ->and($ente->shouldBeSearchable())->toBeTrue()
        ->and($gone->shouldBeSearchable())->toBeFalse();
});

it('indicizza un ente senza descrizione né immagine', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '17', 'slug' => 'comune-di-oliena', 'name' => ['it' => 'Comune di Oliena'], 'properties' => []]);

    $document = $ente->toSearchableArray();

    expect($document['description'])->toBeNull()
        ->and($document['feature_image'])->toBeNull()
        ->and($document['name'])->toBe('Comune di Oliena');
});

it('non incolla le parole dei blocchi nella descrizione indicizzata', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '3', 'name' => ['it' => 'Gal BMG'], 'description' => ['it' => '<h4>Il GAL</h4><p>Testo<br />nuovo &amp; altro</p>'], 'properties' => []]);

    expect($ente->toSearchableArray()['description'])->toBe('Il GAL Testo nuovo & altro');
});

it('indicizza un tipo di ente che l enum non conosce', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '3', 'name' => ['it' => 'Gal BMG'], 'properties' => []]);
    DB::table('entes')->where('id', $ente->id)->update(['tipo_ente' => 'tipo-nuovo']);

    expect($ente->fresh()->toSearchableArray()['type'])->toBe('tipo-nuovo');
});

it('restituisce le card nell\'ordine dei risultati', function () {
    fakeEnteSearch([
        ['id' => 13, 'slug' => 'oasi-tepilora', 'name' => 'Oasi Tepilora', 'name_translations' => ['it' => 'Oasi Tepilora'], 'type' => 'complesso-forestale', 'feature_image' => null, 'description' => 'x'],
        ['id' => 17, 'slug' => 'comune-di-oliena', 'name' => 'Comune di Oliena', 'name_translations' => ['it' => 'Comune di Oliena'], 'type' => 'ente-partner', 'feature_image' => ['url' => 'u', 'thumbnail' => 't'], 'description' => 'y'],
    ]);

    searchEntes($this, 'tepilora')->assertOk()->assertExactJson(['data' => [
        ['id' => 13, 'slug' => 'oasi-tepilora', 'name' => ['it' => 'Oasi Tepilora'], 'type' => 'complesso-forestale', 'feature_image' => null],
        ['id' => 17, 'slug' => 'comune-di-oliena', 'name' => ['it' => 'Comune di Oliena'], 'type' => 'ente-partner', 'feature_image' => ['url' => 'u', 'thumbnail' => 't']],
    ]]);
});

it('risponde 503 se l\'indice manca', function () {
    $response = new Response(404, ['Content-Type' => 'application/json'], json_encode(['error' => ['type' => 'index_not_found_exception']]));
    fakeEnteSearch((new ClientResponseException('index_not_found_exception', 404))->setResponse($response));

    searchEntes($this, 'tepilora')->assertStatus(503);
});

it('accetta caratteri della sintassi Lucene', function () {
    fakeEnteSearch([]);

    searchEntes($this, 'Comune (NU:')->assertOk()->assertExactJson(['data' => []]);
});

it('richiede il testo da cercare', function () {
    fakeEnteSearch([]);

    $this->getJson('/api/v1/entes/search', ['Authorization' => 'Bearer secret'])->assertUnprocessable();
    searchEntes($this, str_repeat('a', 201))->assertUnprocessable();
});

it('richiede il token', function () {
    $this->getJson('/api/v1/entes/search?query=x')->assertUnauthorized();
});
