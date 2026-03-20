<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Http\Clients\SardegnaSentieriClient;
use Wm\WmPackage\Helpers\GlobalFileHelper;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;

class SardegnaSentieriTaxonomyService
{
    /**
     * Vocabularies mapped to TaxonomyPoiType
     */
    private const POI_VOCABULARIES = [
        'tipologia_poi',
    ];

    /**
     * Vocabularies mapped to TaxonomyActivity
     */
    private const TRACK_VOCABULARIES = [
        'categorie_fruibilita_sentieri',
        'tipologia_sentieri',
    ];

    /**
     * Cache for resolved IDs
     *
     * @var array<string, array<string, int|null>>
     */
    private array $cache = [
        'poi_types' => [],
        'activities' => [],
    ];

    /**
     * Cache raw taxonomy terms keyed by vocabulary and API term ID.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $taxonomyTermsCache = [];

    /**
     * Cached icon names loaded from icons.json.
     *
     * @var array<int, string>|null
     */
    private ?array $iconNamesCache = null;

    public function __construct(
        private readonly SardegnaSentieriClient $client
    ) {
    }

    /**
     * Import all taxonomies from API
     */
    public function importAll(): void
    {
        $this->importPoiTaxonomies();
        $this->importTrackTaxonomies();
    }

    /**
     * Import POI-related taxonomies
     */
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

    /**
     * Import Track-related taxonomies
     */
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

    /**
     * Resolve API term ID to TaxonomyPoiType ID
     */
    public function resolvePoiTypeId(string $apiTermId): ?int
    {
        $ids = $this->resolvePoiTypeIds($apiTermId);

        return $ids[0] ?? null;
    }

    /**
     * Resolve API term ID to all TaxonomyPoiType IDs to be associated.
     *
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
     * Resolve API term ID to TaxonomyActivity ID
     */
    public function resolveActivityId(string $apiTermId): ?int
    {
        // Backwards compatible resolver for the existing vocabulary.
        return $this->resolveActivityIdForVocabulary($apiTermId, 'categorie_fruibilita_sentieri');
    }

    /**
     * Resolve API term ID to TaxonomyActivity ID for a given Sardegna Sentieri vocabulary.
     */
    public function resolveActivityIdForVocabulary(string $apiTermId, string $vocabulary): ?int
    {
        $ids = $this->resolveActivityIdsForVocabulary($apiTermId, $vocabulary);

        return $ids[0] ?? null;
    }

    /**
     * Resolve API term ID to all TaxonomyActivity IDs to be associated.
     *
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

    /**
     * Resolve API taxonomy term ID to geohub identifier.
     */
    private function resolveGeohubIdentifier(string $vocabulary, string $apiTermId): ?string
    {
        $terms = $this->getTaxonomyTerms($vocabulary);
        $term = $terms[$apiTermId] ?? null;

        if (! is_array($term)) {
            return null;
        }

        $identifier = $term['geohub_identifier'] ?? null;

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    /**
     * Get taxonomy terms keyed by API term ID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getTaxonomyTerms(string $vocabulary): array
    {
        if (isset($this->taxonomyTermsCache[$vocabulary])) {
            return $this->taxonomyTermsCache[$vocabulary];
        }

        $terms = $this->client->getTaxonomy($vocabulary);

        // Ensure consistent string keys for API term IDs.
        $normalized = [];
        foreach ($terms as $apiId => $term) {
            if (is_array($term)) {
                $normalized[(string) $apiId] = $term;
            }
        }

        $this->taxonomyTermsCache[$vocabulary] = $normalized;

        return $normalized;
    }

    private function resolveIconNameByIdentifier(string $identifier): ?string
    {
        $iconNames = $this->getIconNames();
        if (empty($iconNames)) {
            return null;
        }

        $needle = $this->normalizeKey($identifier);

        // 1) Exact match on normalized values
        foreach ($iconNames as $iconName) {
            if ($this->normalizeKey($iconName) === $needle) {
                return $iconName;
            }
        }

        // 2) Partial match: icon name contains identifier (or vice versa)
        foreach ($iconNames as $iconName) {
            $normalizedIcon = $this->normalizeKey($iconName);
            if (str_contains($normalizedIcon, $needle) || str_contains($needle, $normalizedIcon)) {
                return $iconName;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
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

    private function findOrNewActivity(?string $identifier, string $name): TaxonomyActivity
    {
        if (! empty($identifier)) {
            return TaxonomyActivity::firstOrNew(['identifier' => $identifier]);
        }

        return $this->findActivityByName($name) ?? new TaxonomyActivity;
    }

    private function findOrNewPoiType(?string $identifier, string $name): TaxonomyPoiType
    {
        if (! empty($identifier)) {
            return TaxonomyPoiType::firstOrNew(['identifier' => $identifier]);
        }

        return $this->findPoiTypeByName($name) ?? new TaxonomyPoiType;
    }

    private function findActivityByName(string $name): ?TaxonomyActivity
    {
        return TaxonomyActivity::query()
            ->whereRaw("name::text ilike ?", ['%"'.$name.'"%'])
            ->first();
    }

    private function findPoiTypeByName(string $name): ?TaxonomyPoiType
    {
        return TaxonomyPoiType::query()
            ->whereRaw("name::text ilike ?", ['%"'.$name.'"%'])
            ->first();
    }

    /**
     * @param array<string, mixed> $term
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
