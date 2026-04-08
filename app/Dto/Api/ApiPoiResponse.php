<?php

declare(strict_types=1);

namespace App\Dto\Api;

readonly class ApiPoiResponse
{
    public function __construct(
        public string $id,
        /** @var array<string, string> Translatable {it: ..., en: ...} */
        public array $name,
        /** @var array<string, string>|null */
        public ?array $description,
        public ?string $addr_locality,
        public ?string $codice,
        /** @var list<mixed> */
        public array $collegamenti,
        /** @var array<string, string>|null {it: [HTML], en: [HTML]} */
        public ?array $come_arrivare,
        public ?string $url,
        public ?string $updated_at,
        /** @var list<float> [lon, lat] or [lon, lat, ele] */
        public array $coordinates,
        public ApiTaxonomiesData $taxonomies,
        public ?ApiSardegnaImageData $immaginePrincipale,
        /** @var list<ApiSardegnaImageData> */
        public array $galleria,
        /** @var list<string> */
        public array $allegati,
        /** @var list<string> */
        public array $video,
        /** @var list<string> */
        public array $poi_correlati,
        /** @var array<string, mixed>|null */
        public ?array $ente_istituzione_societa,
    ) {}

    /**
     * Parse a GeoJSON Feature returned by GET /poi/{id}?_format=json
     *
     * @param  array<string, mixed>  $feature
     */
    public static function fromJson(array $feature): self
    {
        $props = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? [];

        $galleria = [];
        if (isset($props['galleria']) && is_array($props['galleria'])) {
            foreach ($props['galleria'] as $row) {
                $img = ApiSardegnaImageData::tryFromMixed($row);
                if ($img !== null) {
                    $galleria[] = $img;
                }
            }
        }

        return new self(
            id: (string) ($props['id'] ?? ''),
            name: is_array($props['name'] ?? null) ? $props['name'] : ['it' => (string) ($props['name'] ?? '')],
            description: isset($props['description']) && is_array($props['description']) ? $props['description'] : null,
            addr_locality: self::optionalString($props['addr_locality'] ?? null),
            codice: self::optionalString($props['codice'] ?? null),
            collegamenti: is_array($props['collegamenti'] ?? null) ? $props['collegamenti'] : [],
            come_arrivare: isset($props['come_arrivare']) && is_array($props['come_arrivare']) ? $props['come_arrivare'] : null,
            url: self::optionalString($props['url'] ?? null),
            updated_at: isset($props['updated_at']) ? (string) $props['updated_at'] : null,
            coordinates: is_array($coords) ? array_values(array_map('floatval', $coords)) : [],
            taxonomies: ApiTaxonomiesData::fromArray(is_array($props['taxonomies'] ?? null) ? $props['taxonomies'] : []),
            immaginePrincipale: ApiSardegnaImageData::tryFromMixed($props['immagine_principale'] ?? null),
            galleria: $galleria,
            allegati: is_array($props['allegati'] ?? null) ? array_values($props['allegati']) : [],
            video: is_array($props['video'] ?? null) ? array_values($props['video']) : [],
            poi_correlati: is_array($props['poi_correlati'] ?? null) ? array_values(array_map('strval', $props['poi_correlati'])) : [],
            ente_istituzione_societa: isset($props['ente_istituzione_societa']) && is_array($props['ente_istituzione_societa']) ? $props['ente_istituzione_societa'] : null,
        );
    }

    /**
     * Drupal a volte invia stringhe strutturate come array: non fare cast a (string).
     */
    private static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
