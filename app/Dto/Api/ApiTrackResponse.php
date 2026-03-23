<?php

declare(strict_types=1);

namespace App\Dto\Api;

readonly class ApiTrackResponse
{
    public function __construct(
        public string $id,
        /** @var array<string, string> Translatable {it: ..., en: ...} */
        public array $name,
        /** @var array<string, string>|null */
        public ?array $description,
        /** @var array<string, string>|null */
        public ?array $excerpt,
        public ?string $lunghezza,
        public ?string $dislivello_totale,
        public ?string $durata,
        public ?string $type,
        /** @var list<string> URL PDF */
        public array $allegati,
        /** @var list<string> URL YouTube */
        public array $video,
        /** @var list<string> URL GPX */
        public array $gpx,
        public ?string $url,
        public ?string $updated_at,
        public ?string $partenza,
        public ?string $arrivo,
        public ApiTaxonomiesData $taxonomies,
    ) {}

    /**
     * Parse a GeoJSON Feature returned by GET /track/{id}?_format=json
     *
     * @param array<string, mixed> $feature
     */
    public static function fromJson(array $feature): self
    {
        $props = $feature['properties'] ?? [];

        return new self(
            id: (string) ($props['id'] ?? ''),
            name: is_array($props['name'] ?? null) ? $props['name'] : ['it' => (string) ($props['name'] ?? '')],
            description: isset($props['description']) && is_array($props['description']) ? $props['description'] : null,
            excerpt: isset($props['excerpt']) && is_array($props['excerpt']) ? $props['excerpt'] : null,
            lunghezza: isset($props['lunghezza']) ? (string) $props['lunghezza'] : null,
            dislivello_totale: isset($props['dislivello_totale']) ? (string) $props['dislivello_totale'] : null,
            durata: isset($props['durata']) ? (string) $props['durata'] : null,
            type: isset($props['type']) ? (string) $props['type'] : null,
            allegati: is_array($props['allegati'] ?? null) ? array_values($props['allegati']) : [],
            video: is_array($props['video'] ?? null) ? array_values($props['video']) : [],
            gpx: is_array($props['gpx'] ?? null) ? array_values($props['gpx']) : [],
            url: isset($props['url']) ? (string) $props['url'] : null,
            updated_at: isset($props['updated_at']) ? (string) $props['updated_at'] : null,
            partenza: isset($props['partenza']) ? (string) $props['partenza'] : null,
            arrivo: isset($props['arrivo']) ? (string) $props['arrivo'] : null,
            taxonomies: ApiTaxonomiesData::fromArray(is_array($props['taxonomies'] ?? null) ? $props['taxonomies'] : []),
        );
    }
}
