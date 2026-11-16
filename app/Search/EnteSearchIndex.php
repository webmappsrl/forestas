<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Mapping dell'indice Elasticsearch degli enti (oc:8965).
 *
 * Si aggiunge in AppServiceProvider::boot(): WmPackageServiceProvider::packageRegistered()
 * sostituisce `elasticsearch.indices` con la configurazione del package, e un file di config di
 * forestas verrebbe cancellato. I settings non servono: Matchish ripiega su `settings.default`
 * del package, che ha già `edge_ngram_analyzer`.
 */
final class EnteSearchIndex
{
    public const NAME = 'entes';

    /**
     * @return array<string, mixed>
     */
    public static function mappings(): array
    {
        return [
            'properties' => [
                'id' => ['type' => 'keyword'],
                'slug' => ['type' => 'keyword'],
                'type' => ['type' => 'keyword'],
                'name' => [
                    'type' => 'text',
                    'fields' => [
                        'edge' => [
                            'type' => 'text',
                            'analyzer' => 'edge_ngram_analyzer',
                            'search_analyzer' => 'standard',
                        ],
                    ],
                ],
                'description' => ['type' => 'text'],
                // Solo per costruire la card: restano in _source, non si cercano.
                'name_translations' => ['type' => 'object', 'enabled' => false],
                'feature_image' => ['type' => 'object', 'enabled' => false],
            ],
        ];
    }
}
