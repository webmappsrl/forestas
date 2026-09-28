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
     * L'import non scrive `manual_data`: lunghezza, dislivello, durata e quote su
     * Drupal vengono dal vecchio calcolo di Webmapp, non da misure. `manual_data`
     * resta degli operatori, e con la chiave assente dal DTO l'array_merge del
     * service conserva quello già presente sul sentiero (oc:8641).
     */
    public static function fromApiResponse(int $externalId, ApiTrackResponse $response): self
    {
        return new self(
            description: $response->description,
            excerpt: $response->excerpt,
            manual_data: null,
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
