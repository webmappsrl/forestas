<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use App\Dto\Import\PoiPropertiesData;
use App\Dto\Import\TrackPropertiesData;
use App\Enums\StatoValidazione;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Helpers\GlobalFileHelper;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;

class SardegnaSentieriImportService
{
    /** Forestas: una sola app nel progetto; tutti gli import usano questo id. */
    public const IMPORT_APP_ID = 1;

    private const POI_VOCABULARIES = [
        'tipologia_poi',
    ];

    private const TRACK_VOCABULARIES = [
        'categorie_fruibilita_sentieri',
        'tipologia_sentieri',
    ];

    private ?int $appId = null;

    private ?int $userId = null;

    /** @var array<string, array<string, int|null>> */
    private array $cache = [
        'poi_types' => [],
        'activities' => [],
    ];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $taxonomyTermsCache = [];

    /** @var array<int, string>|null */
    private ?array $iconNamesCache = null;

    public function __construct(
        private readonly SardegnaSentieriClient $client
    ) {}

    // -------------------------------------------------------------------------
    // Taxonomy import
    // -------------------------------------------------------------------------

    public function importAll(): void
    {
        $this->importPoiTaxonomies();
        $this->importTrackTaxonomies();
    }

    private function importPoiTaxonomies(): void
    {
        foreach (self::POI_VOCABULARIES as $vocabulary) {
            $terms = $this->client->getTaxonomy($vocabulary);

            foreach ($terms as $term) {
                $data = $this->buildTaxonomyData($term);
                if ($data === null) {
                    continue;
                }

                $taxonomy = $this->findOrNewPoiType($data['identifier'], $data['name']);
                $taxonomy->identifier = $data['identifier'];
                $taxonomy->name = $data['name'];
                $taxonomy->description = $data['description'];

                if (empty($taxonomy->icon)) {
                    $taxonomy->icon = $this->resolveIconNameByIdentifier((string) $data['name']);
                }

                $taxonomy->saveQuietly();
            }
        }
    }

    private function importTrackTaxonomies(): void
    {
        foreach (self::TRACK_VOCABULARIES as $vocabulary) {
            $terms = $this->client->getTaxonomy($vocabulary);

            foreach ($terms as $term) {
                $data = $this->buildTaxonomyData($term);
                if ($data === null) {
                    continue;
                }

                $taxonomy = $this->findOrNewActivity($data['identifier'], $data['name']);
                $taxonomy->identifier = $data['identifier'];
                $taxonomy->name = $data['name'];
                $taxonomy->description = $data['description'];

                if (empty($taxonomy->icon)) {
                    $taxonomy->icon = $this->resolveIconNameByIdentifier((string) $data['name']);
                }

                $taxonomy->saveQuietly();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Taxonomy resolution
    // -------------------------------------------------------------------------

    /**
     * @return array<int, int>
     */
    public function resolvePoiTypeIds(string $apiTermId): array
    {
        $cacheKey = 'all:'.$apiTermId;
        if (isset($this->cache['poi_types'][$cacheKey])) {
            return (array) ($this->cache['poi_types'][$cacheKey] ?? []);
        }

        $term = $this->getTaxonomyTerms('tipologia_poi')[$apiTermId] ?? null;
        if (! is_array($term)) {
            $this->cache['poi_types'][$cacheKey] = null;

            return [];
        }

        $data = $this->buildTaxonomyData($term);
        if ($data === null) {
            $this->cache['poi_types'][$cacheKey] = null;

            return [];
        }

        $ids = TaxonomyPoiType::query()
            ->where('identifier', $data['identifier'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $this->cache['poi_types'][$cacheKey] = $ids === [] ? null : $ids;

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    public function resolveActivityIdsForVocabulary(string $apiTermId, string $vocabulary): array
    {
        $cacheKey = $vocabulary.':all:'.$apiTermId;

        if (isset($this->cache['activities'][$cacheKey])) {
            return (array) ($this->cache['activities'][$cacheKey] ?? []);
        }

        $term = $this->getTaxonomyTerms($vocabulary)[$apiTermId] ?? null;
        if (! is_array($term)) {
            $this->cache['activities'][$cacheKey] = null;

            return [];
        }

        $data = $this->buildTaxonomyData($term);
        if ($data === null) {
            $this->cache['activities'][$cacheKey] = null;

            return [];
        }

        $ids = TaxonomyActivity::query()
            ->where('identifier', $data['identifier'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $this->cache['activities'][$cacheKey] = $ids === [] ? null : $ids;

        return $ids;
    }

    // -------------------------------------------------------------------------
    // POI import
    // -------------------------------------------------------------------------

    public function importPoi(int $externalId): EcPoi
    {
        return $this->importPoiFromResponse($externalId, $this->client->getPoiDetail($externalId));
    }

    public function importPoiFromResponse(int $externalId, ApiPoiResponse $response): EcPoi
    {
        if (count($response->coordinates) < 2) {
            throw new \RuntimeException("Invalid geometry for POI {$externalId}");
        }

        $data = [
            'app_id' => $this->getAppId(),
            'user_id' => $this->getUserId(),
            'name' => $response->name,
            'geometry' => "POINT({$response->coordinates[0]} {$response->coordinates[1]})",
        ];

        $ecPoi = EcPoi::whereRaw(
            "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
            [(string) $externalId, (string) $externalId]
        )->first() ?? new EcPoi;

        $existingProperties = is_array($ecPoi->properties) ? $ecPoi->properties : [];

        $data['properties'] = array_merge(
            $existingProperties,
            PoiPropertiesData::fromApiResponse($externalId, $response)->toArray()
        );

        $ecPoi->fill($data)->saveQuietly();

        $this->syncPoiTaxonomies($ecPoi, $response);

        return $ecPoi;
    }

    private function syncPoiTaxonomies(EcPoi $ecPoi, ApiPoiResponse $response): void
    {
        $poiTypeIds = collect($response->taxonomies->tipologia_poi)
            ->flatMap(fn ($apiId) => $this->resolvePoiTypeIds((string) $apiId))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $ecPoi->taxonomyPoiTypes()->sync($poiTypeIds);
    }

    // -------------------------------------------------------------------------
    // Track import
    // -------------------------------------------------------------------------

    public function importTrack(int $externalId): EcTrack
    {
        return $this->importTrackFromResponse($externalId, $this->client->getTrackDetail($externalId));
    }

    public function importTrackFromResponse(int $externalId, ApiTrackResponse $response): EcTrack
    {
        $geometry = $this->getGeometryFromGpx($response->gpx);

        $data = [
            'app_id' => $this->getAppId(),
            'user_id' => $this->getUserId(),
            'name' => $response->name,
        ];

        $ecTrack = EcTrack::firstWhere(
            DB::raw("properties->>'sardegnasentieri_id'"),
            (string) $externalId
        ) ?? new EcTrack;

        $existingProperties = is_array($ecTrack->properties) ? $ecTrack->properties : [];
        $existingManualData = is_array($existingProperties['manual_data'] ?? null) ? $existingProperties['manual_data'] : [];

        $data['properties'] = array_merge(
            $existingProperties,
            TrackPropertiesData::fromApiResponse($externalId, $response, $existingManualData)->toArray()
        );

        $isNew = ! $ecTrack->exists;

        if ($geometry !== null) {
            $data['geometry'] = $geometry;
        } elseif ($isNew) {
            throw new \RuntimeException("No GPX geometry available for new track {$externalId}. Import skipped.");
        }

        $statoId = $response->taxonomies->stato_di_validazione[0] ?? null;
        if ($statoId !== null) {
            $data['stato_validazione'] = StatoValidazione::fromApiId($statoId)?->value;
        }

        $ecTrack->fill($data)->saveQuietly();

        $this->syncRelatedPois($ecTrack, $response);
        $this->syncTrackTaxonomies($ecTrack, $response);

        return $ecTrack;
    }

    /**
     * @param  list<string>  $gpxUrls
     */
    private function getGeometryFromGpx(array $gpxUrls): ?string
    {
        foreach ($gpxUrls as $gpxUrl) {
            try {
                $gpxContent = $this->client->getGpxContent($gpxUrl);
                $geometry = $this->parseGpxToWkt($gpxContent);

                if ($geometry !== null) {
                    return $geometry;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }

    private function parseGpxToWkt(string $gpxContent): ?string
    {
        // Strip default namespace so SimpleXML can traverse elements without namespace prefix
        $gpxContent = preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $gpxContent, 1) ?? $gpxContent;

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

                if (! empty($points)) {
                    $segments[] = '('.implode(', ', $points).')';
                }
            }
        }

        if (empty($segments)) {
            return null;
        }

        return 'MULTILINESTRING Z ('.implode(', ', $segments).')';
    }

    private function syncRelatedPois(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $poiIds = collect([$response->partenza, $response->arrivo])
            ->filter()
            ->map(fn ($sourceId) => EcPoi::whereRaw(
                "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                [$sourceId, $sourceId]
            )->value('id'))
            ->filter()
            ->values()
            ->toArray();

        $ecTrack->ecPois()->sync($poiIds);
    }

    private function syncTrackTaxonomies(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $activityIds = collect($response->taxonomies->categorie_fruibilita_sentieri)
            ->flatMap(fn ($apiId) => $this->resolveActivityIdsForVocabulary((string) $apiId, 'categorie_fruibilita_sentieri'))
            ->filter()
            ->values()
            ->toArray();

        $tipologiaIds = collect($response->taxonomies->tipologia_sentieri)
            ->flatMap(fn ($apiId) => $this->resolveActivityIdsForVocabulary((string) $apiId, 'tipologia_sentieri'))
            ->filter()
            ->values()
            ->toArray();

        $allIds = array_values(array_unique(array_merge($activityIds, $tipologiaIds)));

        $ecTrack->taxonomyActivities()->sync($allIds);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function getAppId(): int
    {
        if ($this->appId === null) {
            $id = self::IMPORT_APP_ID;

            if (! App::query()->whereKey($id)->exists()) {
                throw new \RuntimeException(
                    "App id {$id} non trovata. Il progetto Forestas usa una sola app (id 1); esegui il seed o creala in Nova."
                );
            }

            $this->appId = $id;
        }

        return $this->appId;
    }

    private function getUserId(): int
    {
        if ($this->userId === null) {
            $user = User::where('email', 'forestas@webmapp.it')->first();

            if (! $user) {
                throw new \RuntimeException('User "forestas@webmapp.it" not found. Run SardegnaSentieriSeeder first.');
            }

            $this->userId = $user->id;
        }

        return $this->userId;
    }

    // -------------------------------------------------------------------------
    // Taxonomy internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTaxonomyTerms(string $vocabulary): array
    {
        if (isset($this->taxonomyTermsCache[$vocabulary])) {
            return $this->taxonomyTermsCache[$vocabulary];
        }

        $terms = $this->client->getTaxonomy($vocabulary);

        $normalized = [];
        foreach ($terms as $apiId => $term) {
            if (is_array($term)) {
                $normalized[(string) $apiId] = $term;
            }
        }

        $this->taxonomyTermsCache[$vocabulary] = $normalized;

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $term
     * @return array{identifier: string|null, name: string, description: mixed}|null
     */
    private function buildTaxonomyData(array $term): ?array
    {
        $geohubIdentifier = $term['geohub_identifier'] ?? null;
        if (is_string($geohubIdentifier) && trim($geohubIdentifier) !== '') {
            return [
                'identifier' => $geohubIdentifier,
                'name' => $geohubIdentifier,
                'description' => $term['description'] ?? null,
            ];
        }

        $rawName = $term['name'] ?? null;
        $name = $this->extractStringValue($rawName);
        if ($name === null || $name === '') {
            return null;
        }

        return [
            'identifier' => 'name:'.$this->normalizeKey($name),
            'name' => $name,
            'description' => $term['description'] ?? null,
        ];
    }

    private function findOrNewPoiType(?string $identifier, string $name): TaxonomyPoiType
    {
        if (! empty($identifier)) {
            return TaxonomyPoiType::firstOrNew(['identifier' => $identifier]);
        }

        return $this->findPoiTypeByName($name) ?? new TaxonomyPoiType;
    }

    private function findOrNewActivity(?string $identifier, string $name): TaxonomyActivity
    {
        if (! empty($identifier)) {
            return TaxonomyActivity::firstOrNew(['identifier' => $identifier]);
        }

        return $this->findActivityByName($name) ?? new TaxonomyActivity;
    }

    private function findPoiTypeByName(string $name): ?TaxonomyPoiType
    {
        return TaxonomyPoiType::query()
            ->whereRaw('name::text ilike ?', ['%"'.$name.'"%'])
            ->first();
    }

    private function findActivityByName(string $name): ?TaxonomyActivity
    {
        return TaxonomyActivity::query()
            ->whereRaw('name::text ilike ?', ['%"'.$name.'"%'])
            ->first();
    }

    private function resolveIconNameByIdentifier(string $identifier): ?string
    {
        $iconNames = $this->getIconNames();
        if (empty($iconNames)) {
            return null;
        }

        $needle = $this->normalizeKey($identifier);

        foreach ($iconNames as $iconName) {
            if ($this->normalizeKey($iconName) === $needle) {
                return $iconName;
            }
        }

        foreach ($iconNames as $iconName) {
            $normalizedIcon = $this->normalizeKey($iconName);
            if (str_contains($normalizedIcon, $needle) || str_contains($needle, $normalizedIcon)) {
                return $iconName;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function getIconNames(): array
    {
        if ($this->iconNamesCache !== null) {
            return $this->iconNamesCache;
        }

        $iconsData = GlobalFileHelper::getJsonContent('icons.json', 'icons');
        $icons = is_array($iconsData['icons'] ?? null) ? $iconsData['icons'] : [];

        $this->iconNamesCache = [];
        foreach ($icons as $icon) {
            $name = $icon['properties']['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $this->iconNamesCache[] = $name;
            }
        }

        return $this->iconNamesCache;
    }

    private function normalizeKey(string $value): string
    {
        return str_replace(['-', '_', ' '], '', mb_strtolower($value));
    }

    private function extractStringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
