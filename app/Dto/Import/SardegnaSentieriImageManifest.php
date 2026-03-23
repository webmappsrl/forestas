<?php

declare(strict_types=1);

namespace App\Dto\Import;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiSardegnaImageData;
use App\Dto\Api\ApiTrackResponse;

final class SardegnaSentieriImageManifest
{
    /**
     * Ordered list for Spatie order_column: principale first, then galleria (no duplicate URLs).
     *
     * @return list<array{url: string, autore: string, credits: string, order: int}>
     */
    public static function fromApiPoiResponse(ApiPoiResponse $response): array
    {
        return self::buildOrderedItems($response->immaginePrincipale, $response->galleria);
    }

    /**
     * @return list<array{url: string, autore: string, credits: string, order: int}>
     */
    public static function fromApiTrackResponse(ApiTrackResponse $response): array
    {
        return self::buildOrderedItems($response->immaginePrincipale, $response->galleriaImmagini);
    }

    /**
     * @param  list<ApiSardegnaImageData>  $galleria
     * @return list<array{url: string, autore: string, credits: string, order: int}>
     */
    private static function buildOrderedItems(?ApiSardegnaImageData $principale, array $galleria): array
    {
        $items = [];
        $seen = [];
        $order = 0;

        if ($principale !== null) {
            $items[] = [
                'url' => $principale->url,
                'autore' => $principale->autore,
                'credits' => $principale->credits,
                'order' => $order++,
            ];
            $seen[$principale->url] = true;
        }

        foreach ($galleria as $img) {
            if (isset($seen[$img->url])) {
                continue;
            }
            $items[] = [
                'url' => $img->url,
                'autore' => $img->autore,
                'credits' => $img->credits,
                'order' => $order++,
            ];
            $seen[$img->url] = true;
        }

        return $items;
    }
}
