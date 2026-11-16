<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\Ente;
use Elastic\Elasticsearch\Client;

/**
 * Esegue la ricerca degli enti su Elasticsearch (oc:8965). Classe a sé perché i test la sostituiscono:
 * nei test Scout usa il driver `collection`, che la callback di Matchish non la esegue.
 */
class EnteSearch
{
    /**
     * I documenti trovati (`_source`), in ordine di rilevanza.
     *
     * @return list<array<string, mixed>>
     */
    public function run(string $text): array
    {
        $raw = Ente::search($text, fn (Client $client) => $client->search([
            'index' => EnteSearchIndex::NAME,
            'body' => EnteSearchQuery::body($text),
        ])->asArray())->raw();

        return array_values(array_map(
            fn (array $hit) => $hit['_source'],
            $raw['hits']['hits'] ?? []
        ));
    }
}
