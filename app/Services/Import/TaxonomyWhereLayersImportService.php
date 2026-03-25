<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;

class TaxonomyWhereLayersImportService
{
    private const GEOJSON_URL = 'https://geohub.webmapp.it/api/export/taxonomy/geojson/32/AreeCatastaliconLayers.geojson';

    private const CONFIG_URL = 'https://wmfe.s3.eu-central-1.amazonaws.com/geohub/conf/32.json';

    private const SOURCE_KEY = 'geohub_conf_32';

    /**
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped_missing_layer_id:int,
     *   skipped_missing_match:int,
     *   skipped_invalid_geometry:int,
     *   errors:int,
     *   synced_tracks:int
     * }
     */
    public function import(?int $appId = null): array
    {
        $counters = [
            'created' => 0,
            'updated' => 0,
            'skipped_missing_layer_id' => 0,
            'skipped_missing_match' => 0,
            'skipped_invalid_geometry' => 0,
            'errors' => 0,
            'synced_tracks' => 0,
        ];

        $geojson = $this->fetchJson(self::GEOJSON_URL);
        $config = $this->fetchJson(self::CONFIG_URL);
        $layersById = $this->indexLayersById($config['MAP']['layers'] ?? []);
        $features = $geojson['features'] ?? [];

        $appUserId = $this->resolveAppUserId($appId);

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                $counters['errors']++;
                continue;
            }

            $layerId = $feature['properties']['layer_id'] ?? null;
            if ($layerId === null || $layerId === '') {
                $counters['skipped_missing_layer_id']++;
                continue;
            }

            $externalLayerId = (string) $layerId;
            $layer = $layersById[$externalLayerId] ?? null;
            if (! is_array($layer)) {
                $counters['skipped_missing_match']++;
                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry)) {
                $counters['skipped_invalid_geometry']++;
                continue;
            }

            $name = $this->extractBestLabel($layer['title'] ?? null)
                ?? $this->extractBestLabel($layer['name'] ?? null)
                ?? $externalLayerId;

            $propertiesPayload = array_filter([
                'source' => self::SOURCE_KEY,
                'external_layer_id' => $externalLayerId,
                // Backward-friendly alias in case old data/scripts already read "layer_id".
                'layer_id' => $externalLayerId,
                'title' => $layer['title'] ?? null,
                'subtitle' => $layer['subtitle'] ?? null,
                'description' => $layer['description'] ?? null,
                'feature_image' => $layer['feature_image'] ?? null,
                'layer_name' => $layer['name'] ?? null,
            ], static fn ($value) => $value !== null);

            try {
                $taxonomyWhere = $this->findExisting($externalLayerId);
                if ($taxonomyWhere) {
                    $taxonomyWhere->update([
                        'name' => $name,
                        'properties' => array_merge($taxonomyWhere->properties ?? [], $propertiesPayload),
                    ]);
                    $this->assignTaxonomyUser($taxonomyWhere, $appUserId);
                    $counters['updated']++;
                } else {
                    $taxonomyWhere = TaxonomyWhere::create([
                        'name' => $name,
                        'properties' => $propertiesPayload,
                    ]);
                    $this->assignTaxonomyUser($taxonomyWhere, $appUserId);
                    $counters['created']++;
                }

                $this->updateGeometry($taxonomyWhere->id, $geometry);
            } catch (\Throwable $e) {
                $counters['errors']++;
            }
        }

        $counters['synced_tracks'] = GeometryComputationService::make()->syncTracksTaxonomyWhere(
            config('wm-package.ec_track_model', EcTrack::class)
        );

        return $counters;
    }

    private function fetchJson(string $url): array
    {
        $response = Http::timeout(60)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException("Errore HTTP {$response->status()} su {$url}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException("Payload JSON non valido su {$url}");
        }

        return $json;
    }

    /**
     * @param  mixed  $layers
     * @return array<string, array<string, mixed>>
     */
    private function indexLayersById(mixed $layers): array
    {
        $indexed = [];
        if (! is_array($layers)) {
            return $indexed;
        }

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            $id = $layer['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $indexed[(string) $id] = $layer;
        }

        return $indexed;
    }

    private function findExisting(string $externalLayerId): ?TaxonomyWhere
    {
        return TaxonomyWhere::query()
            ->whereRaw(
                "(properties->>'source' = ?) AND ((properties->>'external_layer_id' = ?) OR (properties->>'layer_id' = ?))",
                [self::SOURCE_KEY, $externalLayerId, $externalLayerId]
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    private function updateGeometry(int $taxonomyWhereId, array $geometry): void
    {
        $geometryJson = json_encode($geometry, JSON_UNESCAPED_UNICODE);
        if ($geometryJson === false) {
            throw new \RuntimeException('Impossibile serializzare la geometria.');
        }

        DB::statement(
            'UPDATE taxonomy_wheres SET geometry = ST_GeomFromGeoJSON(?) WHERE id = ?',
            [$geometryJson, $taxonomyWhereId]
        );
    }

    private function extractBestLabel(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value)) {
            foreach (['it', 'en'] as $lang) {
                if (isset($value[$lang]) && is_string($value[$lang]) && trim($value[$lang]) !== '') {
                    return $value[$lang];
                }
            }

            foreach ($value as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolveAppUserId(?int $appId): ?int
    {
        $app = null;

        if ($appId !== null) {
            $app = App::query()->find($appId);
        } elseif (App::query()->count() === 1) {
            $app = App::query()->first();
        }

        if (! $app instanceof App || empty($app->user_id)) {
            return null;
        }

        return (int) $app->user_id;
    }

    private function assignTaxonomyUser(TaxonomyWhere $taxonomyWhere, ?int $userId): void
    {
        if ($userId === null || ! Schema::hasColumn($taxonomyWhere->getTable(), 'user_id')) {
            return;
        }

        $taxonomyWhere->forceFill(['user_id' => $userId])->saveQuietly();
    }
}
