<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\StatoValidazione;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

class SardegnaSentieriTrackImportService
{
    private ?int $appId = null;

    private ?int $userId = null;

    public function __construct(
        private readonly SardegnaSentieriClient $client,
        private readonly SardegnaSentieriTaxonomyService $taxonomyService
    ) {}

    /**
     * Import a single Track by external ID
     */
    public function importTrack(int $externalId): EcTrack
    {
        $feature = $this->client->getTrackDetail($externalId);
        $props = $feature['properties'] ?? [];

        // Get or resolve app_id and user_id
        $appId = $this->getAppId();
        $userId = $this->getUserId();

        // Get geometry from GPX
        $geometry = $this->getGeometryFromGpx($props);

        // Prepare forestas-specific data
        $forestasData = [
            'source_id' => (string) $externalId,
            'type' => $props['type'] ?? null,
            'allegati' => $props['allegati'] ?? [],
            'video' => $props['video'] ?? [],
            'gpx' => $props['gpx'] ?? [],
            'url' => $props['url'] ?? null,
            'updated_at' => $props['updated_at'] ?? null,
            'zona_geografica' => $props['taxonomies']['zona_geografica'] ?? [],
            'skip_dem_jobs' => true,
        ];

        // DEM-like values from API must live in manual_data.
        $manualData = [
            'distance' => $props['lunghezza'] ?? null,
            'ascent' => $props['dislivello_totale'] ?? null,
            'duration_forward' => $props['durata'] ?? null,
            'duration_backward' => $props['durata'] ?? null,
        ];

        // Build data array
        $data = [
            'app_id' => $appId,
            'user_id' => $userId,
            'name' => $this->extractTranslatable($props['name'] ?? null),
        ];

        // Merge properties
        $existing = EcTrack::whereRaw("properties->>'sardegnasentieri_id' = ?", [(string) $externalId])
            ->value('properties');

        $existingManualData = is_array($existing['manual_data'] ?? null) ? $existing['manual_data'] : [];

        $data['properties'] = array_merge(
            $existing ?? [],
            [
                'sardegnasentieri_id' => (string) $externalId,
                'description' => $this->extractTranslatable($props['description'] ?? null),
                'excerpt' => $this->extractTranslatable($props['excerpt'] ?? null),
                'manual_data' => array_merge($existingManualData, $manualData),
                'forestas' => $forestasData,
            ]
        );

        // Add geometry if available
        if ($geometry !== null) {
            $data['geometry'] = $geometry;
        }

        // Handle stato_validazione enum
        $statoId = $props['taxonomies']['stato_di_validazione'][0] ?? null;
        if ($statoId !== null) {
            $statoEnum = StatoValidazione::fromApiId((string) $statoId);
            $data['stato_validazione'] = $statoEnum?->value;
        }

        // Update or create via JSONB key lookup
        $ecTrack = EcTrack::firstWhere(
            DB::raw("properties->>'sardegnasentieri_id'"),
            (string) $externalId
        ) ?? new EcTrack();

        $ecTrack->fill($data)->saveQuietly();

        // Sync related POIs (partenza + arrivo)
        $this->syncRelatedPois($ecTrack, $props);

        // Sync taxonomy relationships
        $this->syncTaxonomies($ecTrack, $props);

        return $ecTrack;
    }

    /**
     * Get geometry from GPX file
     */
    private function getGeometryFromGpx(array $props): ?string
    {
        $gpxUrls = $props['gpx'] ?? [];

        foreach ($gpxUrls as $gpxUrl) {
            try {
                $gpxContent = $this->client->getGpxContent($gpxUrl);
                $geometry = $this->parseGpxToWkt($gpxContent);

                if ($geometry !== null) {
                    return $geometry;
                }
            } catch (\Exception $e) {
                // Try next GPX file
                continue;
            }
        }

        return null;
    }

    /**
     * Parse GPX content to WKT MULTILINESTRING Z
     */
    private function parseGpxToWkt(string $gpxContent): ?string
    {
        $xml = simplexml_load_string($gpxContent);

        if ($xml === false) {
            return null;
        }

        $segments = [];

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                $points = [];

                foreach ($seg->trkpt as $pt) {
                    $lon = (float) $pt['lon'];
                    $lat = (float) $pt['lat'];
                    $ele = isset($pt->ele) ? (float) $pt->ele : 0.0;
                    $points[] = "{$lon} {$lat} {$ele}";
                }

                if (!empty($points)) {
                    $segments[] = '(' . implode(', ', $points) . ')';
                }
            }
        }

        if (empty($segments)) {
            return null;
        }

        return 'MULTILINESTRING Z (' . implode(', ', $segments) . ')';
    }

    /**
     * Sync related POIs (partenza and arrivo)
     */
    private function syncRelatedPois(EcTrack $ecTrack, array $props): void
    {
        $poiIds = collect([$props['partenza'] ?? null, $props['arrivo'] ?? null])
            ->filter()
            ->map(fn($sourceId) => EcPoi::whereRaw(
                "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                [(string) $sourceId, (string) $sourceId]
            )->value('id'))
            ->filter()
            ->values()
            ->toArray();

        if (!empty($poiIds)) {
            $ecTrack->ecPois()->sync($poiIds);
        }
    }

    /**
     * Sync taxonomy relationships for the Track
     */
    private function syncTaxonomies(EcTrack $ecTrack, array $props): void
    {
        // Sync activities
        $activityIds = collect($props['taxonomies']['categorie_fruibilita_sentieri'] ?? [])
            ->flatMap(fn($apiId) => $this->taxonomyService->resolveActivityIdsForVocabulary((string) $apiId, 'categorie_fruibilita_sentieri'))
            ->filter()
            ->values()
            ->toArray();

        $tipologiaIds = collect($props['taxonomies']['tipologia_sentieri'] ?? [])
            ->flatMap(fn($apiId) => $this->taxonomyService->resolveActivityIdsForVocabulary((string) $apiId, 'tipologia_sentieri'))
            ->filter()
            ->values()
            ->toArray();

        $activityIds = array_values(array_unique(array_merge($activityIds, $tipologiaIds)));

        if (!empty($activityIds)) {
            $ecTrack->taxonomyActivities()->sync($activityIds);
        }
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
