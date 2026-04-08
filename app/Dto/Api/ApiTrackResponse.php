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
        public ?string $created_at,
        public ?string $partenza,
        public ?string $arrivo,
        public ApiTaxonomiesData $taxonomies,
        public ?ApiSardegnaImageData $immaginePrincipale,
        /** @var list<ApiSardegnaImageData> */
        public array $galleriaImmagini,
        public ?string $codice,
        public ?string $codice_cai,
        public ?string $ele_min,
        public ?string $ele_max,
        public ?string $data_rilievo,
        public ?string $complesso_forestale,
        /** @var array<string, string>|null {it: [HTML], en: [HTML]} */
        public ?array $info_utili,
        /** @var array<string, string>|null {it: [HTML], en: [HTML]} */
        public ?array $roadbook,
        /** @var list<string> */
        public array $poi_correlati,
        /** @var array<string, mixed>|null */
        public ?array $ente_istituzione_societa,
        /** @var array<string, mixed>|null GeoJSON geometry object */
        public ?array $geometryFallback,
    ) {}

    /**
     * Parse a GeoJSON Feature returned by GET /track/{id}?_format=json
     *
     * @param  array<string, mixed>  $feature
     */
    public static function fromJson(array $feature): self
    {
        $props = $feature['properties'] ?? [];

        $galleryRaw = $props['galleria_immagini'] ?? $props['galleria'] ?? null;
        $galleriaImmagini = [];
        if (is_array($galleryRaw)) {
            foreach ($galleryRaw as $row) {
                $img = ApiSardegnaImageData::tryFromMixed($row);
                if ($img !== null) {
                    $galleriaImmagini[] = $img;
                }
            }
        }

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
            created_at: isset($props['created_at']) ? (string) $props['created_at'] : null,
            partenza: isset($props['partenza']) ? (string) $props['partenza'] : null,
            arrivo: isset($props['arrivo']) ? (string) $props['arrivo'] : null,
            taxonomies: ApiTaxonomiesData::fromArray(is_array($props['taxonomies'] ?? null) ? $props['taxonomies'] : []),
            immaginePrincipale: ApiSardegnaImageData::tryFromMixed($props['immagine_principale'] ?? null),
            galleriaImmagini: $galleriaImmagini,
            codice: isset($props['codice']) ? (string) $props['codice'] : null,
            codice_cai: isset($props['codice_cai']) ? (string) $props['codice_cai'] : null,
            ele_min: isset($props['ele_min']) ? (string) $props['ele_min'] : null,
            ele_max: isset($props['ele_max']) ? (string) $props['ele_max'] : null,
            data_rilievo: isset($props['data_rilievo']) ? (string) $props['data_rilievo'] : null,
            complesso_forestale: isset($props['complesso_forestale']) ? (string) $props['complesso_forestale'] : null,
            info_utili: isset($props['info_utili']) && is_array($props['info_utili']) ? $props['info_utili'] : null,
            roadbook: isset($props['roadbook']) && is_array($props['roadbook']) ? $props['roadbook'] : null,
            poi_correlati: is_array($props['poi_correlati'] ?? null) ? array_values(array_map('strval', $props['poi_correlati'])) : [],
            ente_istituzione_societa: isset($props['ente_istituzione_societa']) && is_array($props['ente_istituzione_societa']) ? $props['ente_istituzione_societa'] : null,
            geometryFallback: isset($feature['geometry']) && is_array($feature['geometry']) ? $feature['geometry'] : null,
        );
    }
}
