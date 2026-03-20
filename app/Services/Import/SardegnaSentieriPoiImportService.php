<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Http\Clients\SardegnaSentieriClient;
use App\Models\User;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;

class SardegnaSentieriPoiImportService
{
    private ?int $appId = null;

    private ?int $userId = null;

    public function __construct(
        private readonly SardegnaSentieriClient $client,
        private readonly SardegnaSentieriTaxonomyService $taxonomyService
    ) {}

    /**
     * Import a single POI by external ID
     */
    public function importPoi(int $externalId): EcPoi
    {
        $feature = $this->client->getPoiDetail($externalId);
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;

        // Get coordinates from geometry
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            throw new \RuntimeException("Invalid geometry for POI {$externalId}");
        }

        // Prepare forestas-specific data
        $forestasData = [
            'codice' => $props['codice'] ?? null,
            'collegamenti' => $props['collegamenti'] ?? [],
            'come_arrivare' => $props['come_arrivare'] ?? null,
            'url' => $props['url'] ?? null,
            'updated_at' => $props['updated_at'] ?? null,
            'zona_geografica' => $props['taxonomies']['zona_geografica'] ?? [],
        ];

        // Get or resolve app_id and user_id
        $appId = $this->getAppId();
        $userId = $this->getUserId();

        // Build data array
        $data = [
            'app_id' => $appId,
            'user_id' => $userId,
            'name' => $this->extractTranslatable($props['name'] ?? null),
            'geometry' => "POINT({$coords[0]} {$coords[1]})", // Z will be calculated from DEM
        ];

        // Merge properties
        $existing = EcPoi::whereRaw(
            "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
            [(string) $externalId, (string) $externalId]
        )
            ->value('properties');

        $data['properties'] = array_merge(
            $existing ?? [],
            [
                'description' => $this->extractTranslatable($props['description'] ?? null),
                'addr_complete' => $props['addr_locality'] ?? null,
                'out_source_feature_id' => (string) $externalId,
                'forestas' => $forestasData,
            ]
        );

        // Update or create via JSONB key lookup
        $ecPoi = EcPoi::whereRaw(
            "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
            [(string) $externalId, (string) $externalId]
        )->first() ?? new EcPoi();

        $ecPoi->fill($data)->saveQuietly();

        // Sync taxonomy relationships
        $this->syncTaxonomies($ecPoi, $props);

        // Sync media (images)
        $this->syncMedia($ecPoi, $props);

        return $ecPoi;
    }

    /**
     * Sync taxonomy relationships for the POI
     */
    private function syncTaxonomies(EcPoi $ecPoi, array $props): void
    {
        // Sync POI types
        $poiTypeIds = collect($props['taxonomies']['tipologia_poi'] ?? [])
            ->flatMap(fn($apiId) => $this->taxonomyService->resolvePoiTypeIds((string) $apiId))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (!empty($poiTypeIds)) {
            $ecPoi->taxonomyPoiTypes()->sync($poiTypeIds);
        }
    }

    /**
     * Media import is currently disabled for SardegnaSentieri POIs.
     * Keep method in place so import flow is explicit and stable.
     */
    private function syncMedia(EcPoi $ecPoi, array $props): void
    {
        // Intentionally left as no-op until media ingestion is implemented.
    }

    /**
     * Get App ID for Sardegna Sentieri
     */
    private function getAppId(): int
    {
        if ($this->appId === null) {
            $app = App::where('sku', 'it.webmapp.sardegnasentieri')->first();

            if (!$app) {
                throw new \RuntimeException('App "Sardegna Sentieri" not found. Run SardegnaSentieriSeeder first.');
            }

            $this->appId = $app->id;
        }

        return $this->appId;
    }

    /**
     * Get User ID for forestas
     */
    private function getUserId(): int
    {
        if ($this->userId === null) {
            $user = User::where('email', 'forestas@webmapp.it')->first();

            if (!$user) {
                throw new \RuntimeException('User "forestas@webmapp.it" not found. Run SardegnaSentieriSeeder first.');
            }

            $this->userId = $user->id;
        }

        return $this->userId;
    }

    /**
     * Extract translatable field (handles both array and string)
     *
     * @return array<string, string>|null
     */
    private function extractTranslatable(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        // If it's a string, wrap in 'it' locale
        return ['it' => $value];
    }
}
