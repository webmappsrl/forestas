<?php

declare(strict_types=1);

namespace App\Http\Clients;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SardegnaSentieriClient
{
    private const BASE_URL = 'https://www.sardegnasentieri.it/ss';

    private const TIMEOUT = 30;

    private const GPX_TIMEOUT = 60;

    /**
     * Get POI list: {id: timestamp, ...}
     *
     * @return array<string, string>
     *
     * @throws ConnectionException
     */
    public function getPoiList(): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get(self::BASE_URL.'/listpoi/', ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, 'Failed to fetch POI list: '.$response->body());

        return $response->json() ?? [];
    }

    /**
     * Get POI detail by ID
     *
     * @throws ConnectionException
     */
    public function getPoiDetail(int $id): ApiPoiResponse
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get(self::BASE_URL."/poi/{$id}", ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, "Failed to fetch POI {$id}: ".$response->body());

        return ApiPoiResponse::fromJson($response->json() ?? []);
    }

    /**
     * Get Track list: {id: timestamp, ...}
     *
     * @return array<string, string>
     *
     * @throws ConnectionException
     */
    public function getTrackList(): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get(self::BASE_URL.'/list-tracks/', ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, 'Failed to fetch track list: '.$response->body());

        return $response->json() ?? [];
    }

    /**
     * Get Track detail by ID
     *
     * @throws ConnectionException
     */
    public function getTrackDetail(int $id): ApiTrackResponse
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get(self::BASE_URL."/track/{$id}", ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, "Failed to fetch track {$id}: ".$response->body());

        return ApiTrackResponse::fromJson($response->json() ?? []);
    }

    /**
     * Get taxonomy vocabulary
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function getTaxonomy(string $vocabulary): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get(self::BASE_URL."/tassonomia/{$vocabulary}", ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, "Failed to fetch taxonomy {$vocabulary}: ".$response->body());

        return $response->json() ?? [];
    }

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

    /**
     * Get Drupal node detail by ID (used for enti/istituzioni)
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    public function getNodeDetail(int $id): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://www.sardegnasentieri.it/node/'.$id, ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, "Failed to fetch node {$id}: ".$response->body());

        return $response->json() ?? [];
    }

    /**
     * Get Drupal taxonomy term by ID.
     * Used as fallback when TipoEnte::fromDrupalId() returns null.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    public function getTaxonomyTerm(int $id): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->get('https://www.sardegnasentieri.it/taxonomy/term/'.$id, ['_format' => 'json']);

        throw_if($response->failed(), \RuntimeException::class, "Failed to fetch taxonomy term {$id}: ".$response->body());

        return $response->json() ?? [];
    }

    /**
     * Get warning type taxonomy: {id: {vid, name: {it, en}, parent}, ...}
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function getTaxonomyWarnings(): array
    {
        return $this->getTaxonomy('tipologia_di_avvertenze');
    }
}
