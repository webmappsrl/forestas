<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiPoiResponse;
use Wm\WmPackage\Dto\EcPoiPropertiesData;

readonly class PoiPropertiesData extends EcPoiPropertiesData
{
    public function __construct(
        ?array $description,
        ?string $out_source_feature_id,
        ?string $addr_complete,
        public ForestasPoiData $forestas,
    ) {
        parent::__construct(
            description: $description,
            out_source_feature_id: $out_source_feature_id,
            addr_complete: $addr_complete,
        );
    }

    public static function fromApiResponse(int $externalId, ApiPoiResponse $response): self
    {
        return new self(
            description: $response->description,
            out_source_feature_id: (string) $externalId,
            addr_complete: $response->addr_locality,
            forestas: ForestasPoiData::fromApiResponse($response),
        );
    }

    public function toArray(): array
    {
        return array_merge(
            parent::toArray(),
            ['forestas' => $this->forestas->toArray()]
        );
    }
}
