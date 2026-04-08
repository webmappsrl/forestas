<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use App\Dto\Import\PoiPropertiesData;
use App\Dto\Import\TrackPropertiesData;
use App\Enums\StatoValidazione;
use App\Enums\TipoEnte;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcPoi as LocalEcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Models\TaxonomyWarning;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    private const POI_ACTIVITY_VOCABULARIES = [
        'servizi',
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
        'warnings' => [],
    ];

    /** @var array<string, int|null> */
    private array $enteCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $taxonomyTermsCache = [];

    /** @var array<int, string>|null */
    private ?array $iconNamesCache = null;

    public function __construct(
        private readonly SardegnaSentieriClient $client,
        private readonly SardegnaSentieriMediaSyncService $mediaSync,
    ) {}

    // -------------------------------------------------------------------------
    // Taxonomy import
    // -------------------------------------------------------------------------

    public function importAll(): void
    {
        $this->importPoiTaxonomies();
        $this->importPoiActivityTaxonomies();
        $this->importTrackTaxonomies();
        $this->importWarningTaxonomies();
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

    private function importPoiActivityTaxonomies(): void
    {
        foreach (self::POI_ACTIVITY_VOCABULARIES as $vocabulary) {
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

    private function importWarningTaxonomies(): void
    {
        $terms = $this->client->getTaxonomyWarnings();

        foreach ($terms as $apiId => $term) {
            if (! is_array($term)) {
                continue;
            }

            $nameRaw = $term['name'] ?? null;
            $name = is_array($nameRaw) ? $nameRaw : ['it' => (string) ($nameRaw ?? '')];

            if (empty($name['it'])) {
                continue;
            }

            $identifier = 'sardegnasentieri:warning:'.$apiId;

            $taxonomy = TaxonomyWarning::firstOrNew(['identifier' => $identifier]);
            $taxonomy->identifier = $identifier;
            $taxonomy->name = $name;
            $taxonomy->saveQuietly();
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

    /**
     * @return array<int, int>
     */
    public function resolveWarningIds(string $apiTermId): array
    {
        if (isset($this->cache['warnings'][$apiTermId])) {
            return (array) ($this->cache['warnings'][$apiTermId] ?? []);
        }

        $identifier = 'sardegnasentieri:warning:'.$apiTermId;

        $ids = TaxonomyWarning::query()
            ->where('identifier', $identifier)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $this->cache['warnings'][$apiTermId] = $ids === [] ? null : $ids;

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
        $this->syncEntiPoi($ecPoi, $response);
        $this->syncRelatedPoisForPoi($ecPoi, $response);

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

        $serviziIds = collect($response->taxonomies->servizi)
            ->flatMap(fn ($apiId) => $this->resolveActivityIdsForVocabulary((string) $apiId, 'servizi'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (! empty($serviziIds)) {
            $ecPoi->taxonomyActivities()->sync($serviziIds);
        }
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

        if ($geometry === null && $response->geometryFallback !== null) {
            $geometry = $this->convertGeoJsonGeometryToWkt($response->geometryFallback);
        }

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

        $this->syncFromTo($ecTrack, $response);
        $this->syncEnti($ecTrack, $response);
        $this->syncRelatedPois($ecTrack, $response);
        $this->syncTrackTaxonomies($ecTrack, $response);
        $this->syncTrackWarnings($ecTrack, $response);
        $this->syncTrackType($ecTrack, $response);

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

    private function syncFromTo(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $from = null;
        $to = null;

        if ($response->partenza !== null) {
            $partenzaPoi = EcPoi::whereRaw(
                "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                [$response->partenza, $response->partenza]
            )->first();
            $from = $partenzaPoi?->getTranslation('name', 'it') ?? null;
        }

        if ($response->arrivo !== null) {
            $arrivoPoi = EcPoi::whereRaw(
                "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                [$response->arrivo, $response->arrivo]
            )->first();
            $to = $arrivoPoi?->getTranslation('name', 'it') ?? null;
        }

        if ($from !== null || $to !== null) {
            $props = $ecTrack->properties ?? [];
            $props['from'] = $from;
            $props['to'] = $to;
            $ecTrack->properties = $props;
            $ecTrack->saveQuietly();
        }
    }

    private function syncRelatedPois(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $resolveId = fn (?string $sourceId): ?int => $sourceId === null ? null : EcPoi::whereRaw(
            "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
            [$sourceId, $sourceId]
        )->value('id');

        $partenzaId = $resolveId($response->partenza);

        $correlatiIds = collect($response->poi_correlati)
            ->map(fn ($sourceId) => $resolveId($sourceId))
            ->filter()
            ->values()
            ->toArray();

        $arrivoId = $resolveId($response->arrivo);

        $poiIds = array_values(array_filter(
            array_merge(
                $partenzaId !== null ? [$partenzaId] : [],
                $correlatiIds,
                $arrivoId !== null ? [$arrivoId] : [],
            )
        ));

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

    private function syncTrackType(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        if ($response->type === null || $response->type === '') {
            return;
        }

        $identifier = 'sardegnasentieri:type:'.$response->type;

        $activity = TaxonomyActivity::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ucfirst($response->type)]
        );

        $currentIds = $ecTrack->taxonomyActivities()->pluck('taxonomy_activities.id')->toArray();
        $allIds = array_unique(array_merge($currentIds, [$activity->id]));
        $ecTrack->taxonomyActivities()->sync($allIds);
    }

    private function syncTrackWarnings(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $warningIds = collect($response->taxonomies->tipologia_di_avvertenze)
            ->flatMap(fn ($apiId) => $this->resolveWarningIds((string) $apiId))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $ecTrack->taxonomyWarnings()->sync($warningIds);
    }

    private function syncRelatedPoisForPoi(EcPoi $ecPoi, ApiPoiResponse $response): void
    {
        if (empty($response->poi_correlati)) {
            return;
        }

        $ids = collect($response->poi_correlati)
            ->map(fn (string $sourceId) => EcPoi::whereRaw(
                "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                [$sourceId, $sourceId]
            )->value('id'))
            ->filter()
            ->values()
            ->toArray();

        $localPoi = LocalEcPoi::find($ecPoi->id);
        if ($localPoi !== null) {
            $localPoi->relatedPois()->sync($ids);
        }
    }

    private function syncEntiPoi(EcPoi $ecPoi, ApiPoiResponse $response): void
    {
        $ente = $response->ente_istituzione_societa ?? [];

        $pairs = [];

        $ruoliMap = [
            'soggetto_gestore' => 'gestore',
            'soggetto_manutentore' => 'manutentore',
            'soggetto_rilevatore' => 'rilevatore',
        ];

        foreach ($ruoliMap as $field => $ruolo) {
            $nodeId = isset($ente[$field]) ? (string) $ente[$field] : null;
            if ($nodeId === null) {
                continue;
            }
            $enteId = $this->resolveOrImportEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => $ruolo];
            }
        }

        $operatori = is_array($ente['riferimento_operatori_guid'] ?? null)
            ? $ente['riferimento_operatori_guid']
            : [];

        foreach ($operatori as $nodeId) {
            $enteId = $this->resolveOrImportEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'operatore'];
            }
        }

        if (empty($pairs)) {
            return;
        }

        DB::table('enteables')
            ->where('enteable_id', $ecPoi->id)
            ->where('enteable_type', EcPoi::class)
            ->delete();

        foreach ($pairs as $pair) {
            DB::table('enteables')->insertOrIgnore([
                'ente_id' => $pair['ente_id'],
                'enteable_id' => $ecPoi->id,
                'enteable_type' => EcPoi::class,
                'ruolo' => $pair['ruolo'],
            ]);
        }
    }

    private function syncEnti(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $ente = $response->ente_istituzione_societa ?? [];

        // Collect all (enteId, ruolo) pairs — same ente can have multiple roles
        $pairs = [];

        $ruoliMap = [
            'soggetto_gestore' => 'gestore',
            'soggetto_manutentore' => 'manutentore',
            'soggetto_rilevatore' => 'rilevatore',
        ];

        foreach ($ruoliMap as $field => $ruolo) {
            $nodeId = isset($ente[$field]) ? (string) $ente[$field] : null;
            if ($nodeId === null) {
                continue;
            }
            $enteId = $this->resolveOrImportEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => $ruolo];
            }
        }

        $operatori = is_array($ente['riferimento_operatori_guid'] ?? null)
            ? $ente['riferimento_operatori_guid']
            : [];

        foreach ($operatori as $nodeId) {
            $enteId = $this->resolveOrImportEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'operatore'];
            }
        }

        if ($response->complesso_forestale !== null) {
            $enteId = $this->resolveOrImportEnte((int) $response->complesso_forestale);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'complesso_forestale'];
            }
        }

        // Remove all existing enteables for this track, then re-insert
        DB::table('enteables')
            ->where('enteable_id', $ecTrack->id)
            ->where('enteable_type', EcTrack::class)
            ->delete();

        foreach ($pairs as $pair) {
            DB::table('enteables')->insertOrIgnore([
                'ente_id' => $pair['ente_id'],
                'enteable_id' => $ecTrack->id,
                'enteable_type' => EcTrack::class,
                'ruolo' => $pair['ruolo'],
            ]);
        }
    }

    private function resolveOrImportEnte(int $nodeId): ?int
    {
        $cacheKey = (string) $nodeId;

        if (array_key_exists($cacheKey, $this->enteCache)) {
            return $this->enteCache[$cacheKey];
        }

        // Check if already imported
        $existing = Ente::where('sardegnasentieri_id', $cacheKey)->value('id');

        if ($existing !== null) {
            $this->enteCache[$cacheKey] = $existing;

            return $existing;
        }

        $result = $this->importOrUpdateEnte($cacheKey);
        $this->enteCache[$cacheKey] = $result;

        return $result;
    }

    public function importOrUpdateEnte(string $sardegnaSentieriId): ?int
    {
        try {
            $node = $this->client->getNodeDetail((int) $sardegnaSentieriId);
            $title = $node['title'][0]['value'] ?? null;

            if (empty($title)) {
                return null;
            }

            $contattiRaw = $node['field_contatti'][0]['value'] ?? null;
            $contatti = (is_string($contattiRaw) && $contattiRaw !== '') ? $contattiRaw : null;

            $paginaWebRaw = $node['field_pagina_web'][0]['uri'] ?? null;
            $paginaWeb = is_string($paginaWebRaw) ? $paginaWebRaw : null;

            $tipoEnteId = isset($node['field_tipo_ente'][0]['target_id'])
                ? (int) $node['field_tipo_ente'][0]['target_id']
                : null;

            /** @var TipoEnte|string|null $tipoEnteValue */
            $tipoEnteValue = null;
            if ($tipoEnteId !== null) {
                $enum = TipoEnte::fromDrupalId($tipoEnteId);
                if ($enum !== null) {
                    $tipoEnteValue = $enum;
                } else {
                    try {
                        $term = $this->client->getTaxonomyTerm($tipoEnteId);
                        $label = $term['name'][0]['value'] ?? null;
                        if ($label !== null) {
                            $tipoEnteSlug = Str::slug($label);
                            Log::warning("TipoEnte Drupal ID {$tipoEnteId} non mappato nell'enum: slug '{$tipoEnteSlug}'");
                            $tipoEnteValue = $tipoEnteSlug;
                        }
                    } catch (\Exception) {
                        Log::warning("TipoEnte Drupal ID {$tipoEnteId} non risolvibile via API");
                    }
                }
            }

            $featureImageUrl = isset($node['field_immagine_principale'][0]['url'])
                ? (string) $node['field_immagine_principale'][0]['url']
                : null;

            $description = isset($node['body'][0]['value'])
                ? trim(strip_tags((string) $node['body'][0]['value']))
                : null;

            $lat = isset($node['field_geolocalizzazione'][0]['lat'])
                ? (float) $node['field_geolocalizzazione'][0]['lat']
                : null;

            $lon = isset($node['field_geolocalizzazione'][0]['lon'])
                ? (float) $node['field_geolocalizzazione'][0]['lon']
                : null;

            $geometry = ($lat !== null && $lon !== null)
                ? "POINT({$lon} {$lat})"
                : null;

            $ente = Ente::firstOrNew(['sardegnasentieri_id' => $sardegnaSentieriId]);
            $ente->setTranslations('name', ['it' => $title, 'en' => $title]);
            $ente->contatti = $contatti;
            $ente->pagina_web = $paginaWeb;
            if ($description !== null && $description !== '') {
                $ente->setTranslations('description', ['it' => $description, 'en' => $description]);
            }
            if ($tipoEnteValue instanceof TipoEnte) {
                $ente->tipo_ente = $tipoEnteValue;
            } else {
                // Arbitrary slug (not yet in enum): bypass Eloquent enum cast
                $rawAttrs = $ente->getAttributes();
                $rawAttrs['tipo_ente'] = $tipoEnteValue;
                $ente->setRawAttributes($rawAttrs, true);
            }
            if ($geometry !== null) {
                $ente->geometry = $geometry;
            }
            $ente->properties = null;
            $ente->saveQuietly();

            if ($featureImageUrl !== null && $this->mediaSync !== null) {
                $this->mediaSync->syncImportedImages($ente, [
                    ['url' => $featureImageUrl, 'autore' => '', 'credits' => '', 'order' => 0],
                ]);
            }

            return $ente->id;
        } catch (\Exception) {
            return null;
        }
    }

    private function convertGeoJsonGeometryToWkt(?array $geometry): ?string
    {
        if ($geometry === null) {
            return null;
        }

        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'LineString' && is_array($coordinates)) {
            $points = array_map(
                fn ($c) => count($c) >= 3
                    ? "{$c[0]} {$c[1]} {$c[2]}"
                    : "{$c[0]} {$c[1]} 0",
                $coordinates
            );

            return 'MULTILINESTRING Z (('.implode(', ', $points).'))';
        }

        return null;
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
