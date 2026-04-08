<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiTrackResponse;

readonly class ForestasTrackData
{
    public function __construct(
        public string $source_id,
        public array $allegati,
        public array $video,
        public array $gpx,
        public ?string $url,
        public ?string $created_at,
        public ?string $updated_at,
        public array $zona_geografica,
        public ?string $codice,
        public ?string $data_rilievo,
        public ?array $info_utili,
        public ?array $roadbook,
        public bool $skip_dem_jobs = true,
    ) {}

    public static function fromApiResponse(int $externalId, ApiTrackResponse $response): self
    {
        return new self(
            source_id: (string) $externalId,
            allegati: $response->allegati,
            video: $response->video,
            gpx: $response->gpx,
            url: $response->url,
            created_at: $response->created_at,
            updated_at: $response->updated_at,
            zona_geografica: $response->taxonomies->zona_geografica,
            codice: $response->codice,
            data_rilievo: $response->data_rilievo,
            info_utili: $response->info_utili,
            roadbook: $response->roadbook,
        );
    }

    public function toArray(): array
    {
        return [
            'source_id' => $this->source_id,
            'allegati' => self::listToAssoc($this->allegati),
            'video' => self::listToAssoc($this->video),
            'gpx' => self::listToAssoc($this->gpx),
            'url' => $this->url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'zona_geografica' => self::listToAssoc($this->zona_geografica),
            'codice' => $this->codice,
            'data_rilievo' => $this->data_rilievo,
            'info_utili' => $this->info_utili,
            'roadbook' => $this->roadbook,
            'skip_dem_jobs' => $this->skip_dem_jobs,
        ];
    }

    /**
     * @param  list<mixed>  $list
     * @return array<string, mixed>
     */
    private static function listToAssoc(array $list): array
    {
        if (empty($list)) {
            return [];
        }

        $result = [];
        foreach (array_values($list) as $i => $value) {
            $result[(string) $i] = $value;
        }

        return $result;
    }
}
