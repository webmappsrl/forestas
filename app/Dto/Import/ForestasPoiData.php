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
        return [
            'codice'          => $this->codice,
            'collegamenti'    => self::listToAssoc($this->collegamenti),
            'come_arrivare'   => $this->come_arrivare,
            'url'             => $this->url,
            'updated_at'      => $this->updated_at,
            'zona_geografica' => self::listToAssoc($this->zona_geografica),
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
