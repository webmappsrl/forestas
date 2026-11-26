<?php

declare(strict_types=1);

namespace App\Search;

use Elastic\Elasticsearch\Client;
use Wm\WmPackage\Models\EcTrack;

/**
 * L'indice Elasticsearch delle tracce visto dalla pulizia di fine import (oc:8999): quali id
 * contiene e come toglierne alcuni. Sta in una classe sua perché i test la sostituiscano senza un
 * Elasticsearch vero, come EnteSearch.
 *
 * L'indice è l'alias di Scout del modello delle tracce (`ec_tracks`), non il nome con la data
 * che Matchish dà all'indice vero: l'alias resta lo stesso quando l'indice si ricrea.
 */
class TrackSearchIndex
{
    private const PAGE_SIZE = 1000;

    private const SCROLL = '1m';

    /**
     * Tutti gli id delle voci nell'indice, letti a pagine con lo scroll.
     *
     * @return list<int>
     */
    public function documentIds(): array
    {
        $client = app(Client::class);
        $response = $client->search([
            'index' => $this->name(),
            'scroll' => self::SCROLL,
            'size' => self::PAGE_SIZE,
            'body' => ['_source' => false, 'query' => ['match_all' => new \stdClass]],
        ])->asArray();

        $ids = [];
        $scrollId = $response['_scroll_id'] ?? null;
        try {
            while (($hits = $response['hits']['hits'] ?? []) !== []) {
                foreach ($hits as $hit) {
                    $ids[] = (int) $hit['_id'];
                }
                if ($scrollId === null) {
                    break;
                }
                $response = $client->scroll(['body' => ['scroll_id' => $scrollId, 'scroll' => self::SCROLL]])->asArray();
                $scrollId = $response['_scroll_id'] ?? $scrollId;
            }
        } finally {
            if ($scrollId !== null) {
                $client->clearScroll(['body' => ['scroll_id' => $scrollId]]);
            }
        }

        return $ids;
    }

    /**
     * Toglie dall'indice le voci con questi id.
     *
     * @param  list<int>  $ids
     */
    public function delete(array $ids): void
    {
        app(Client::class)->deleteByQuery([
            'index' => $this->name(),
            'refresh' => true,
            'body' => ['query' => ['ids' => ['values' => array_map('strval', $ids)]]],
        ]);
    }

    /**
     * Il modello delle tracce di cui questo è l'indice (`wm-package.ec_track_model`).
     *
     * @return class-string<EcTrack>
     */
    public function trackModel(): string
    {
        /** @var class-string<EcTrack> */
        return config('wm-package.ec_track_model');
    }

    private function name(): string
    {
        $model = $this->trackModel();

        return (new $model)->searchableAs();
    }
}
