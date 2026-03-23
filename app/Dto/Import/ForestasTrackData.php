<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiTrackResponse;

readonly class ForestasTrackData
{
    public function __construct(
        public string $source_id,
        public ?string $type,
        public array $allegati,
        public array $video,
        public array $gpx,
        public ?string $url,
        public ?string $updated_at,
        public array $zona_geografica,
        public bool $skip_dem_jobs = true,
    ) {}

    public static function fromApiResponse(int $externalId, ApiTrackResponse $response): self
    {
        return new self(
            source_id: (string) $externalId,
            type: $response->type,
            allegati: $response->allegati,
            video: $response->video,
            gpx: $response->gpx,
            url: $response->url,
            updated_at: $response->updated_at,
            zona_geografica: $response->taxonomies->zona_geografica,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
