<?php

declare(strict_types=1);

namespace App\Http\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Download dei file di Sardegna Sentieri (GPX) dal sito pubblico.
 *
 * I dati non passano più da qui: l'import li legge da SardegnaSentieriSource
 * (oc:8900). I file invece restano sul sito pubblico, e la copia del DB ne ha
 * solo il percorso.
 */
class SardegnaSentieriClient
{
    private const GPX_TIMEOUT = 60;

    /**
     * Download GPX file content
     *
     * @throws ConnectionException
     */
    public function getGpxContent(string $url): string
    {
        $response = Http::timeout(self::GPX_TIMEOUT)
            ->get($url);

        throw_if($response->failed(), \RuntimeException::class, "Failed to download GPX from {$url}");

        return $response->body();
    }
}
