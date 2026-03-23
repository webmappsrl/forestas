<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiPoiResponse;

readonly class ForestasPoiData
{
    public function __construct(
        public ?string $codice,
        public array $collegamenti,
        public ?string $come_arrivare,
        public ?string $url,
        public ?string $updated_at,
        public array $zona_geografica,
    ) {}

    public static function fromApiResponse(ApiPoiResponse $response): self
    {
        return new self(
            codice: $response->codice,
            collegamenti: $response->collegamenti,
            come_arrivare: $response->come_arrivare,
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
