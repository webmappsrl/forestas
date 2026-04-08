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
        /** @var list<string> */
        public array $servizi = [],
        /** @var list<string> */
        public array $tipologia_di_avvertenze = [],
    ) {}

    public static function fromArray(array $taxonomies): self
    {
        return new self(
            tipologia_poi: self::normalizeIdList($taxonomies['tipologia_poi'] ?? null),
            categorie_fruibilita_sentieri: self::normalizeIdList($taxonomies['categorie_fruibilita_sentieri'] ?? null),
            tipologia_sentieri: self::normalizeIdList($taxonomies['tipologia_sentieri'] ?? null),
            stato_di_validazione: self::normalizeIdList($taxonomies['stato_di_validazione'] ?? null),
            zona_geografica: self::normalizeIdList($taxonomies['zona_geografica'] ?? null),
            servizi: self::normalizeIdList($taxonomies['servizi'] ?? null),
            tipologia_di_avvertenze: self::normalizeIdList($taxonomies['tipologia_di_avvertenze'] ?? null),
        );
    }

    /**
     * L'API Drupal a volte invia un solo tid come stringa/int invece di array di id.
     *
     * @return list<string>
     */
    private static function normalizeIdList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return [(string) $value];
    }
}
