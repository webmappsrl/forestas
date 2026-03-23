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
        public ?string $come_arrivare,
        public ?string $url,
        public ?string $updated_at,
        /** @var list<float> [lon, lat] or [lon, lat, ele] */
        public array $coordinates,
        public ApiTaxonomiesData $taxonomies,
    ) {}

    /**
     * Parse a GeoJSON Feature returned by GET /poi/{id}?_format=json
     *
     * @param array<string, mixed> $feature
     */
    public static function fromJson(array $feature): self
    {
        $props = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? [];

        return new self(
            id: (string) ($props['id'] ?? ''),
            name: is_array($props['name'] ?? null) ? $props['name'] : ['it' => (string) ($props['name'] ?? '')],
            description: isset($props['description']) && is_array($props['description']) ? $props['description'] : null,
            addr_locality: isset($props['addr_locality']) ? (string) $props['addr_locality'] : null,
            codice: isset($props['codice']) ? (string) $props['codice'] : null,
            collegamenti: is_array($props['collegamenti'] ?? null) ? $props['collegamenti'] : [],
            come_arrivare: isset($props['come_arrivare']) ? (string) $props['come_arrivare'] : null,
            url: isset($props['url']) ? (string) $props['url'] : null,
            updated_at: isset($props['updated_at']) ? (string) $props['updated_at'] : null,
            coordinates: is_array($coords) ? array_values(array_map('floatval', $coords)) : [],
            taxonomies: ApiTaxonomiesData::fromArray(is_array($props['taxonomies'] ?? null) ? $props['taxonomies'] : []),
        );
    }
}
