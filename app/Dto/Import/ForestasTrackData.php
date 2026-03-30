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
        return [
            'source_id'       => $this->source_id,
            'type'            => $this->type,
            'allegati'        => self::listToAssoc($this->allegati),
            'video'           => self::listToAssoc($this->video),
            'gpx'             => self::listToAssoc($this->gpx),
            'url'             => $this->url,
            'updated_at'      => $this->updated_at,
            'zona_geografica' => self::listToAssoc($this->zona_geografica),
            'skip_dem_jobs'   => $this->skip_dem_jobs,
        ];
    }

    /**
     * Converte una lista PHP (array indicizzato numericamente) in array associativo
     * con chiavi stringa ("0", "1", ...) compatibile con il campo KeyValue di Nova.
     *
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
