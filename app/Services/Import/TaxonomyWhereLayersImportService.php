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

            $name = $this->extractTranslatedName($layer['title'] ?? null)
                ?? $this->extractTranslatedName($layer['name'] ?? null)
                ?? ['it' => $externalLayerId, 'en' => $externalLayerId];

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

    /**
     * @return array<string, string>|null
     */
    private function extractTranslatedName(mixed $value): ?array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            // Some upstream payloads contain JSON serialized as string.
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $normalized = $this->normalizeTranslations($decoded);
                if ($normalized !== []) {
                    return $normalized;
                }
            }

            return ['it' => $trimmed, 'en' => $trimmed];
        }

        if (is_array($value)) {
            $normalized = $this->normalizeTranslations($value);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    private function normalizeTranslations(array $value): array
    {
        $normalized = [];
        foreach (['it', 'en', 'de', 'fr', 'es'] as $lang) {
            if (isset($value[$lang]) && is_string($value[$lang]) && trim($value[$lang]) !== '') {
                $normalized[$lang] = trim($value[$lang]);
            }
        }

        if ($normalized === []) {
            foreach ($value as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $label = trim($candidate);
                    $normalized['it'] = $label;
                    $normalized['en'] = $label;
                    break;
                }
            }
        }

        if (isset($normalized['it']) && ! isset($normalized['en'])) {
            $normalized['en'] = $normalized['it'];
        }
        if (isset($normalized['en']) && ! isset($normalized['it'])) {
            $normalized['it'] = $normalized['en'];
        }

        return $normalized;
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
