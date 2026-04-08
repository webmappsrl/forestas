<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiTrackResponse;
use Wm\WmPackage\Dto\EcTrackPropertiesData;
use Wm\WmPackage\Dto\ManualTrackData;

readonly class TrackPropertiesData extends EcTrackPropertiesData
{
    public function __construct(
        ?array $description,
        ?array $excerpt,
        ?ManualTrackData $manual_data,
        ?string $ref,
        public string $sardegnasentieri_id,
        public ForestasTrackData $forestas,
    ) {
        parent::__construct(
            description: $description,
            excerpt: $excerpt,
            manual_data: $manual_data,
            ref: $ref,
        );
    }

    /**
     * @param  array<string, mixed>  $existingManualData  Previously stored manual_data to preserve user edits
     */
    public static function fromApiResponse(int $externalId, ApiTrackResponse $response, array $existingManualData = []): self
    {
        $apiManual = ManualTrackData::fromArray([
            'distance' => $response->lunghezza,
            'ascent' => $response->dislivello_totale,
            'duration_forward' => $response->durata,
            'duration_backward' => $response->durata,
            'ele_min' => $response->ele_min,
            'ele_max' => $response->ele_max,
        ]);

        $manual = empty($existingManualData)
            ? $apiManual
            : ManualTrackData::merge(ManualTrackData::fromArray($existingManualData), $apiManual);

        return new self(
            description: $response->description,
            excerpt: $response->excerpt,
            manual_data: $manual,
            ref: $response->codice_cai,
            sardegnasentieri_id: (string) $externalId,
            forestas: ForestasTrackData::fromApiResponse($externalId, $response),
        );
    }

    public function toArray(): array
    {
        return array_merge(
            parent::toArray(),
            [
                'sardegnasentieri_id' => $this->sardegnasentieri_id,
                'forestas' => $this->forestas->toArray(),
            ]
        );
    }
}
