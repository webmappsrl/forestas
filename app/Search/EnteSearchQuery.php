<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Corpo della ricerca degli enti (oc:8965). Il testo dell'utente è un valore da cercare, mai una
 * sintassi: niente `query_string` (il default di Matchish), con cui una parentesi non chiusa fa
 * rispondere 400 a Elasticsearch.
 */
final class EnteSearchQuery
{
    public const SIZE = 50;

    /**
     * @return array<string, mixed>
     */
    public static function body(string $text): array
    {
        return [
            'size' => self::SIZE,
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => $text,
                                'fields' => ['name^3', 'name.edge^2', 'description'],
                                'fuzziness' => 'AUTO',
                                'operator' => 'and',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
