<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiPoiResponse;

readonly class ForestasPoiData
{
    public function __construct(
        public ?string $codice,
        public ?array $come_arrivare,
        public ?string $url,
        public ?string $updated_at,
        public array $zona_geografica,
        public array $allegati,
        public array $video,
        public array $poi_correlati,
        public ?string $created_at = null,
        public ?string $sardegnasentieri_id = null,
    ) {}

    public static function fromApiResponse(ApiPoiResponse $response, ?int $externalId = null): self
    {
        return new self(
            codice: $response->codice,
            come_arrivare: $response->come_arrivare,
            url: $response->url,
            updated_at: $response->updated_at,
            zona_geografica: $response->taxonomies->zona_geografica,
            allegati: $response->allegati,
            video: $response->video,
            poi_correlati: $response->poi_correlati,
            created_at: $response->created_at,
            sardegnasentieri_id: $externalId === null ? null : (string) $externalId,
        );
    }

    public function toArray(): array
    {
        return [
            // Il nid di Drupal, con il nome delle tracce (oc:8959): `pois.geojson` porta il
            // `forestas` salvato così com'è, e il package non ha un punto in cui aggiungerlo in
            // uscita. `out_source_feature_id`, fuori da `forestas`, resta per import e package.
            'sardegnasentieri_id' => $this->sardegnasentieri_id,
            'codice' => $this->codice,
            'come_arrivare' => $this->come_arrivare,
            'url' => $this->url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'zona_geografica' => self::listToAssoc($this->zona_geografica),
            'allegati' => self::listToAssoc($this->allegati),
            'video' => self::listToAssoc($this->video),
            'poi_correlati' => self::listToAssoc($this->poi_correlati),
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
        foreach ($list as $i => $value) {
            $result[(string) $i] = $value;
        }

        return $result;
    }
}
