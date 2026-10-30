<?php

declare(strict_types=1);

namespace App\Services\Import\Source;

use Carbon\CarbonImmutable;

/**
 * Valori della copia del DB Drupal resi identici a come li davano le API
 * (oc:8900).
 *
 * Un carattere diverso costa caro: `changed` è il confronto dell'import
 * incrementale, l'URL è la chiave con cui SardegnaSentieriMediaSyncService
 * riconosce un'immagine già scaricata.
 */
final class DrupalValues
{
    private const TIMEZONE = 'Europe/Rome';

    private const PUBLIC_SCHEME = 'public://';

    /**
     * Timestamp unix UTC della copia → `Y-m-d H:i:s` in ora italiana, come
     * le API. In PHP e non con FROM_UNIXTIME: quella usa il fuso della
     * sessione MySQL.
     */
    public static function dateTime(int $unixUtc): string
    {
        return CarbonImmutable::createFromTimestampUTC($unixUtc)
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d H:i:s');
    }

    /**
     * `public://<percorso>` → URL pubblico, con ogni segmento codificato come
     * fanno le API (spazi e parentesi compresi). I file privati non hanno un
     * URL pubblico.
     */
    public static function fileUrl(string $uri): ?string
    {
        if (! str_starts_with($uri, self::PUBLIC_SCHEME)) {
            return null;
        }

        $path = substr($uri, strlen(self::PUBLIC_SCHEME));
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return rtrim((string) config('forestas.sardegnasentieri.files_url'), '/').'/'.$encoded;
    }
}
