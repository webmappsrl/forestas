<?php

declare(strict_types=1);

namespace App\Dto\Api;

readonly class ApiTaxonomiesData
{
    public function __construct(
        /** @var list<string> */
        public array $tipologia_poi = [],
        /** @var list<string> */
        public array $categorie_fruibilita_sentieri = [],
        /** @var list<string> */
        public array $tipologia_sentieri = [],
        /** @var list<string> */
        public array $stato_di_validazione = [],
        /** @var list<string> */
        public array $zona_geografica = [],
    ) {}

    public static function fromArray(array $taxonomies): self
    {
        return new self(
            tipologia_poi: array_values(array_map('strval', $taxonomies['tipologia_poi'] ?? [])),
            categorie_fruibilita_sentieri: array_values(array_map('strval', $taxonomies['categorie_fruibilita_sentieri'] ?? [])),
            tipologia_sentieri: array_values(array_map('strval', $taxonomies['tipologia_sentieri'] ?? [])),
            stato_di_validazione: array_values(array_map('strval', $taxonomies['stato_di_validazione'] ?? [])),
            zona_geografica: array_values(array_map('strval', $taxonomies['zona_geografica'] ?? [])),
        );
    }
}
