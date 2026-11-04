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
use App\Models\User;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\FeaturesService;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\TrailRegistry\TrailCodeRegistrationOutcome;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

class SardegnaSentieriImportService
{
    /** Forestas: una sola app nel progetto; tutti gli import usano questo id. */
    public const IMPORT_APP_ID = 1;

    /** Identifier dell'attività che raggruppa i cammini (oc:8827). */
    public const CAMMINO_ACTIVITY_IDENTIFIER = 'sardegnasentieri:cammino';

    /** Prefisso dell'`identifier` delle attività che nascono dal tipo del tracciato (`sentiero`, `itinerario`). */
    public const TYPE_ACTIVITY_PREFIX = 'sardegnasentieri:type:';

    public const CAMMINO_ACTIVITY_NAME = 'Cammini di Sardegna';

    /** Termine «Cammini di Sardegna» di `field_tipologia_itinerario` in Drupal (oc:8827). */
    public const CAMMINI_TERM_ID = '4728';

    /**
     * Le tre scale `Accessibili dis. *` di Drupal collassano in una (oc:8910): ogni tid
     * dà il grado, nel vocabolario `inclusivity-difficulty`, e la categoria, in
     * `inclusivity-disabilities`. Va scritta qui perché i nove tid non esistono come
     * termini nel nostro modello e dall'identifier non si derivano: «sensoriale»
     * accorpa udito e vista, che in Drupal sono scale separate.
     *
     * tid Drupal => [identifier del grado, identifier della categoria].
     */
    private const ACCESSIBILITY_TIDS = [
        '4897' => ['inclusivity-difficulty-at', 'inclusivity-disabilities-physical'],
        '4900' => ['inclusivity-difficulty-ae', 'inclusivity-disabilities-physical'],
        '4893' => ['inclusivity-difficulty-aee', 'inclusivity-disabilities-physical'],
        '4896' => ['inclusivity-difficulty-at', 'inclusivity-disabilities-sensory'],
        '4892' => ['inclusivity-difficulty-ae', 'inclusivity-disabilities-sensory'],
        '4898' => ['inclusivity-difficulty-aee', 'inclusivity-disabilities-sensory'],
        '4891' => ['inclusivity-difficulty-at', 'inclusivity-disabilities-cognitive'],
        '4901' => ['inclusivity-difficulty-ae', 'inclusivity-disabilities-cognitive'],
        '4899' => ['inclusivity-difficulty-aee', 'inclusivity-disabilities-cognitive'],
    ];

    /**
     * I vocabolari che l'import scrive e di cui è l'unica fonte: a ogni import
     * i loro legami si sostituiscono con quelli di Drupal. Fuori da qui
     * (difficoltà del sentiero didattico, tematismi, tipo percorso) i legami
     * sono di Nova e l'import non li tocca mai.
     */
    private const IMPORT_OWNED_TAXONOMIES = [
        'hiking-difficulty',
        'mtb-difficulty',
        'horse-difficulty',
        'nordic-walking-difficulty',
        'soft-mobility-difficulty',
        'route-type',
        'itinerary-type',
        self::WARNINGS_TAXONOMY,
    ];

    /**
     * I vocabolari dell'accessibilità: Drupal ne ha due righe su 614 sentieri e il
     * resto lo compila un operatore in Nova. Si sostituiscono solo se Drupal, per
     * quel sentiero, porta almeno un tid di `ACCESSIBILITY_TIDS`; altrimenti i
     * legami esistenti non si toccano, o ogni reimport cancellerebbe il lavoro
     * fatto a mano (oc:8910).
     */
    private const ACCESSIBILITY_FAMILIES = [
        'inclusivity-difficulty',
        'inclusivity-disabilities',
    ];

    /** Prefisso dell'`identifier` dei termini che vengono da `categorie_fruibilita_sentieri` (oc:8910). */
    public const DRUPAL_TERM_PREFIX = 'sardegnasentieri:tid:';

    /** Il vocabolario delle avvertenze, che prima era la tassonomia `taxonomy_warnings` (oc:8910). */
    public const WARNINGS_TAXONOMY = 'warnings';

    /** Prefisso dell'`identifier` di un'avvertenza: lo stesso di quando era una tassonomia a sé. */
    public const WARNING_TERM_PREFIX = 'sardegnasentieri:warning:';

    /** Dove l'import segna, sul sentiero, ciò che nella classificazione non ha saputo scrivere (oc:8910). */
    public const CLASSIFICATION_ANOMALIES_KEY = 'classificazioni_anomale';

    private const POI_VOCABULARIES = [
        'tipologia_poi',
        'servizi',
    ];

    private const POI_ACTIVITY_VOCABULARIES = [];

    private const TRACK_VOCABULARIES = [
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

    /**
     * Se il POI o il sentiero in import ha saltato un ente mancante.
     */
    private bool $entiMancanti = false;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $taxonomyTermsCache = [];

    /** @var array<int, string>|null */
    private ?array $iconNamesCache = null;

    /** @var Collection<string, TaxonomyTerm>|null Vocabolari del modello unico per identifier. */
    private ?Collection $termTaxonomies = null;

    /** @var Collection<string, TaxonomyTerm>|null Termini del modello unico per identifier, con il vocabolario. */
    private ?Collection $termsByIdentifier = null;

    public function __construct(
        private readonly SardegnaSentieriSource $source,
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
            $terms = $this->source->getTaxonomy($vocabulary);

            foreach ($terms as $apiId => $term) {
                $data = $this->buildTaxonomyData($term);
                if ($data === null) {
                    continue;
                }

                $taxonomy = $this->findOrNewPoiType($data['identifier'], $data['name']);
                $taxonomy->identifier = $data['identifier'];
                $taxonomy->setTranslation('name', 'it', $data['name']);
                if ($data['description'] !== null) {
                    $taxonomy->setTranslation('description', 'it', $data['description']);
                }
                $taxonomy->properties = array_merge($taxonomy->properties ?? [], [
                    'source_id' => (string) $apiId,
                    'vocabulary' => $vocabulary,
                ]);

                // `icon` esiste in tabella ma non e' dichiarata sui modelli del
                // package: si passa da get/setAttribute per restare type-safe.
                if (empty($taxonomy->getAttribute('icon'))) {
                    $taxonomy->setAttribute('icon', $this->resolveIconNameByIdentifier((string) $data['name']));
                }

                $taxonomy->saveQuietly();
            }
        }
    }

    private function importPoiActivityTaxonomies(): void
    {
        foreach (self::POI_ACTIVITY_VOCABULARIES as $vocabulary) {
            $terms = $this->source->getTaxonomy($vocabulary);

            foreach ($terms as $apiId => $term) {
                $data = $this->buildTaxonomyData($term);
                if ($data === null) {
                    continue;
                }

                $taxonomy = $this->findOrNewActivity($data['identifier'], $data['name']);
                $taxonomy->identifier = $data['identifier'];
                $taxonomy->setTranslation('name', 'it', $data['name']);
                if ($data['description'] !== null) {
                    $taxonomy->setTranslation('description', 'it', $data['description']);
                }
                $taxonomy->properties = array_merge($taxonomy->properties ?? [], [
                    'source_id' => (string) $apiId,
                    'vocabulary' => $vocabulary,
                ]);

                // `icon` esiste in tabella ma non e' dichiarata sui modelli del
                // package: si passa da get/setAttribute per restare type-safe.
                if (empty($taxonomy->getAttribute('icon'))) {
                    $taxonomy->setAttribute('icon', $this->resolveIconNameByIdentifier((string) $data['name']));
                }

                $taxonomy->saveQuietly();
            }
        }
    }

    private function importTrackTaxonomies(): void
    {
        foreach (self::TRACK_VOCABULARIES as $vocabulary) {
            $terms = $this->source->getTaxonomy($vocabulary);

            foreach ($terms as $apiId => $term) {
                $data = $this->buildTaxonomyData($term);
                if ($data === null) {
                    continue;
                }

                $taxonomy = $this->findOrNewActivity($data['identifier'], $data['name']);
                $taxonomy->identifier = $data['identifier'];
                $taxonomy->setTranslation('name', 'it', $data['name']);
                if ($data['description'] !== null) {
                    $taxonomy->setTranslation('description', 'it', $data['description']);
                }
                $taxonomy->properties = array_merge($taxonomy->properties ?? [], [
                    'source_id' => (string) $apiId,
                    'vocabulary' => $vocabulary,
                ]);

                // `icon` esiste in tabella ma non e' dichiarata sui modelli del
                // package: si passa da get/setAttribute per restare type-safe.
                if (empty($taxonomy->getAttribute('icon'))) {
                    $taxonomy->setAttribute('icon', $this->resolveIconNameByIdentifier((string) $data['name']));
                }

                $taxonomy->saveQuietly();
            }
        }
    }

    /**
     * Le avvertenze di Drupal diventano termini del vocabolario `warnings` (oc:8910).
     *
     * Erano una tassonomia a sé, con le sue due tabelle; ora sono termini come tutti gli altri.
     * L'`identifier` resta quello di prima, `sardegnasentieri:warning:<tid>`, così l'import
     * continua a riconoscerle per quello e non per il nome.
     *
     * Si scrive con `insertOrIgnore` più rilettura, mai con `firstOrCreate`: due job che
     * importano insieme violerebbero l'unicità, e sotto transazione PostgreSQL l'errore aborta la
     * transazione (oc:8607).
     */
    private function importWarningTaxonomies(): void
    {
        $vocabulary = TaxonomyTerm::query()->taxonomies()->where('identifier', self::WARNINGS_TAXONOMY)->first();

        if ($vocabulary === null) {
            Log::channel('import')->warning('Vocabolario «'.self::WARNINGS_TAXONOMY.'» non trovata: avvertenze non importate.');

            return;
        }

        foreach ($this->source->getTaxonomyWarnings() as $apiId => $term) {
            if (! is_array($term)) {
                continue;
            }

            $nameRaw = $term['name'] ?? null;
            $name = is_array($nameRaw) ? $nameRaw : ['it' => (string) ($nameRaw ?? '')];

            if (empty($name['it'])) {
                continue;
            }

            $identifier = self::WARNING_TERM_PREFIX.$apiId;
            $properties = ['source_id' => (string) $apiId, 'vocabulary' => 'tipologia_di_avvertenze'];

            $now = now();
            DB::table('taxonomy_terms')->insertOrIgnore([
                'parent_id' => $vocabulary->id,
                'identifier' => $identifier,
                'name' => json_encode($name, JSON_UNESCAPED_UNICODE),
                'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $existing = TaxonomyTerm::query()->where('identifier', $identifier)->first();
            if ($existing === null) {
                continue;
            }

            // Nome e vocabolario si riallineano: un termine rinominato in Drupal, o rimasto fuori
            // dal vocabolario perché creato prima di lei, si rimette a posto da solo.
            $existing->setTranslations('name', $name);
            $existing->parent_id = $vocabulary->id;
            $existing->properties = array_merge($existing->properties ?? [], $properties);

            if ($existing->isDirty()) {
                $existing->save();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Taxonomy resolution
    // -------------------------------------------------------------------------

    /**
     * @return array<int, int>
     */
    public function resolvePoiTypeIds(string $apiTermId, string $vocabulary = 'tipologia_poi'): array
    {
        $cacheKey = $vocabulary.':all:'.$apiTermId;
        if (isset($this->cache['poi_types'][$cacheKey])) {
            return (array) ($this->cache['poi_types'][$cacheKey] ?? []);
        }

        $term = $this->getTaxonomyTerms($vocabulary)[$apiTermId] ?? null;
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
        return $this->importPoiFromResponse($externalId, $this->source->getPoiDetail($externalId));
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
            ->flatMap(fn ($apiId) => $this->resolvePoiTypeIds((string) $apiId, 'servizi'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $ecPoi->taxonomyPoiTypes()->sync(array_values(array_unique(array_merge($poiTypeIds, $serviziIds))));
    }

    // -------------------------------------------------------------------------
    // Track import
    // -------------------------------------------------------------------------

    public function importTrack(int $externalId): EcTrack
    {
        return $this->importTrackFromResponse($externalId, $this->source->getTrackDetail($externalId));
    }

    public function importTrackFromResponse(int $externalId, ApiTrackResponse $response): EcTrack
    {
        $gpxFailures = [];
        $geometry = $this->getGeometryFromGpx($response->gpx, $gpxFailures);

        if ($geometry === null && $response->geometryFallback !== null) {
            $geometry = $this->convertGeoJsonGeometryToWkt($response->geometryFallback);

            if ($geometry === null) {
                $gpxFailures[] = 'the GeoJSON fallback geometry could not be converted';
            }
        }

        $data = [
            'app_id' => $this->getAppId(),
            'user_id' => $this->getUserId(),
            'name' => $response->name,
            // Colonne di Forestas che vengono da Drupal (oc:8910). Le tre dell'inclusivita'
            // restano fuori: nessuna sorgente le possiede, sono degli operatori.
            'forestas_registry_code' => $response->codice,
            'forestas_survey_date' => $response->data_rilievo !== null
                ? substr($response->data_rilievo, 0, 10)
                : null,
        ];

        $ecTrack = EcTrack::firstWhere(
            DB::raw("properties->>'sardegnasentieri_id'"),
            (string) $externalId
        ) ?? new EcTrack;

        $existingProperties = is_array($ecTrack->properties) ? $ecTrack->properties : [];

        // Il DTO non emette `manual_data`: l'array_merge conserva quello già
        // presente, che è degli operatori (oc:8641).
        $data['properties'] = array_merge(
            $existingProperties,
            TrackPropertiesData::fromApiResponse($externalId, $response)->toArray()
        );

        // L'array_merge sostituisce l'intera chiave `forestas`: le chiavi compilate a mano in Nova
        // al suo interno vanno rimesse, l'import non le possiede (oc:8910).
        foreach (EcTrack::MANUAL_FORESTAS_KEYS as $key) {
            if (isset($existingProperties['forestas'][$key])) {
                $data['properties']['forestas'][$key] = $existingProperties['forestas'][$key];
            }
        }

        $isNew = ! $ecTrack->exists;

        if ($geometry !== null) {
            $data['geometry'] = $geometry;
        } elseif ($isNew) {
            throw new \RuntimeException(sprintf(
                'No usable geometry for new track %d. Import skipped. Reasons: %s',
                $externalId,
                $gpxFailures === [] ? 'unknown' : implode('; ', $gpxFailures)
            ));
        }

        $statoId = $response->taxonomies->stato_di_validazione[0] ?? null;
        if ($statoId !== null) {
            $data['stato_validazione'] = StatoValidazione::fromApiId($statoId)?->value;
        }

        $ecTrack->fill($data)->saveQuietly();

        $this->syncFromTo($ecTrack, $response);
        $this->syncEnti($ecTrack, $response);
        $this->syncRelatedPois($ecTrack, $response);
        $this->syncTrackActivities($ecTrack, $response);
        $this->syncTrackTerms($ecTrack, $response);

        $this->registerTrailRegistryCode($ecTrack);

        return $ecTrack;
    }

    /**
     * Registra nel catasto il codice del sentiero appena importato.
     *
     * E' la stessa regola del comando di normalizzazione, perche' chiama lo
     * stesso metodo del service: leggere il `ref`, ricavare il settore dalla
     * geometria, scrivere come assegnato o come conflitto se la posizione e'
     * gia' presa.
     *
     * Non solleva mai: un problema sul codice non deve far perdere
     * l'aggiornamento di una traccia.
     */
    private function registerTrailRegistryCode(EcTrack $ecTrack): void
    {
        if (! FeaturesService::isEnabled('trail_registry')) {
            return;
        }

        $ref = $ecTrack->properties['ref'] ?? '';

        // Nessun ref, nessun codice. E' anche cio' che taglia fuori gli
        // itinerari: nessuno dei 147 sui dati reali ne ha uno. Non serve un
        // controllo separato sul tipo del tracciato.
        if (trim((string) $ref) === '') {
            return;
        }

        try {
            // La geometria si rilegge dal database: la variabile locale del
            // metodo puo' essere null quando la traccia esisteva gia' e il GPX
            // non era disponibile, mentre la riga conserva quella di prima.
            $row = DB::selectOne(
                'SELECT ST_AsText(geometry) AS wkt FROM ec_tracks WHERE id = ? AND geometry IS NOT NULL',
                [$ecTrack->id],
            );

            if ($row === null) {
                return;
            }

            $outcome = app(TrailRegistryService::class)
                ->registerExistingCode($ecTrack->id, (string) $ref, $row->wkt);

            $this->logTrailRegistryOutcome($ecTrack, (string) $ref, $outcome);
        } catch (\Throwable $e) {
            Log::error('Catasto: registrazione del codice fallita', [
                'ec_track_id' => $ecTrack->id,
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Logga solo le anomalie: `alreadyRegistered` e' il caso normale di ogni
     * re-import (767 tracce a ogni giro) e riempirebbe il log di righe che
     * nessuno legge.
     */
    private function logTrailRegistryOutcome(EcTrack $ecTrack, string $ref, TrailCodeRegistrationOutcome $outcome): void
    {
        if (in_array($outcome->status, ['alreadyAssigned', 'sectorMismatch', 'unparsableRef', 'noSector'], true)) {
            Log::warning('Catasto: anomalia nella registrazione del codice', [
                'ec_track_id' => $ecTrack->id,
                'ref' => $ref,
                'status' => $outcome->status,
                'full_code' => $outcome->fullCode,
                // Solo su `alreadyAssigned`: e' il sentiero che quel codice
                // lo porta gia', l'unico dato che rende il log azionabile
                // senza dover andare a cercare chi sia.
                'holder_ec_track_id' => $outcome->holder?->ec_track_id,
            ]);
        }

        if ($outcome->sectorMismatch) {
            Log::warning('Catasto: il settore dedotto dalla geometria non coincide con quello nel codice', [
                'ec_track_id' => $ecTrack->id,
                'ref' => $ref,
                'status' => $outcome->status,
                'full_code' => $outcome->fullCode,
            ]);
        }
    }

    /**
     * @param  list<string>  $gpxUrls
     */
    /**
     * @param  array<int, string>  $gpxUrls
     * @param  array<int, string>  $failures  Collects why each URL was unusable, for error reporting.
     */
    private function getGeometryFromGpx(array $gpxUrls, array &$failures = []): ?string
    {
        if ($gpxUrls === []) {
            $failures[] = 'the source published no GPX url';

            return null;
        }

        foreach ($gpxUrls as $gpxUrl) {
            try {
                $gpxContent = $this->client->getGpxContent($gpxUrl);
            } catch (\Exception $e) {
                $failures[] = "{$gpxUrl}: download failed ({$e->getMessage()})";

                continue;
            }

            $reason = null;
            $geometry = $this->parseGpxToWkt($gpxContent, $reason);

            if ($geometry !== null) {
                return $geometry;
            }

            $failures[] = "{$gpxUrl}: {$reason}";
        }

        return null;
    }

    /**
     * @param  string|null  $reason  Set to a human readable explanation when the GPX yields no geometry.
     */
    private function parseGpxToWkt(string $gpxContent, ?string &$reason = null): ?string
    {
        // Strip default namespace so SimpleXML can traverse elements without namespace prefix
        $gpxContent = preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $gpxContent, 1) ?? $gpxContent;

        $xml = simplexml_load_string($gpxContent);

        if ($xml === false) {
            $reason = 'the file is not valid XML';

            return null;
        }

        $segments = [];

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                $points = $this->gpxPointsToCoordinates($seg->trkpt);

                if (count($points) >= 2) {
                    $segments[] = '('.implode(', ', $points).')';
                }
            }
        }

        // Some sources publish the itinerary as a route (<rte>/<rtept>) instead of
        // a track (<trk>/<trkseg>/<trkpt>) — typically GPX converted from KML.
        foreach ($xml->rte as $rte) {
            $points = $this->gpxPointsToCoordinates($rte->rtept);

            if (count($points) >= 2) {
                $segments[] = '('.implode(', ', $points).')';
            }
        }

        if (empty($segments)) {
            $reason = 'the GPX contains no <trk> or <rte> with at least two points';

            return null;
        }

        return 'MULTILINESTRING Z ('.implode(', ', $segments).')';
    }

    /**
     * @return array<int, string>
     */
    private function gpxPointsToCoordinates(\SimpleXMLElement $gpxPoints): array
    {
        $points = [];

        foreach ($gpxPoints as $pt) {
            $lon = (float) $pt['lon'];
            $lat = (float) $pt['lat'];
            $ele = isset($pt->ele) ? (float) $pt->ele : 0.0;
            $points[] = "{$lon} {$lat} {$ele}";
        }

        return $points;
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

    /**
     * Scrive tutte le attività della traccia con un solo `sync()`: termini
     * `tipologia_sentieri`, attività derivata dal tipo, attività dei cammini.
     *
     * Il `sync()` gira senza eventi: l'observer del pivot accoda un job di
     * ricalcolo dei layer per ogni riga aggiunta o tolta, migliaia a ogni
     * import. I layer li ricalcola il job di fine batch (oc:8827).
     * `withoutEvents` spegne il dispatcher di tutti i modelli per la durata
     * della closure: dentro ci va solo il `sync()`.
     */
    private function syncTrackActivities(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        // Gli id delle attività `accessible-trails` ecc. vengono dal `field_geohub_identifier` del termine Drupal.
        $ids = collect($response->taxonomies->tipologia_sentieri)
            ->flatMap(fn ($apiId) => $this->resolveActivityIdsForVocabulary((string) $apiId, 'tipologia_sentieri'))
            ->filter()
            ->all();

        if ($response->type !== null && $response->type !== '') {
            $ids[] = $this->resolveActivityByIdentifier(
                self::TYPE_ACTIVITY_PREFIX.$response->type,
                ucfirst($response->type)
            )->id;
        }

        if (in_array(self::CAMMINI_TERM_ID, $response->taxonomies->tipologia_itinerari, true)) {
            $ids[] = $this->resolveActivityByIdentifier(
                self::CAMMINO_ACTIVITY_IDENTIFIER,
                self::CAMMINO_ACTIVITY_NAME
            )->id;
        }

        $ids = array_values(array_unique($ids));

        TaxonomyActivityable::withoutEvents(fn () => $ecTrack->taxonomyActivities()->sync($ids));
    }

    /**
     * L'attivita' con questo identifier, creandola se manca.
     *
     * I job di import girano a decine in parallelo e i tipi si ripetono:
     * `sentiero` arriva da centinaia di tracciati. Un `firstOrNew` seguito da
     * un `save` sono due passi distinti, quindi due job che leggono insieme
     * non trovano nulla e inseriscono entrambi: il perdente riceve una
     * violazione di unicita' su `identifier`, muore, e rientra in coda in
     * fondo — dietro il post-processing accodato nel frattempo. Un solo job
     * caduto cosi' tiene il batch aperto per ore, e il ricalcolo delle
     * anomalie agganciato alla sua fine non parte (oc:8607).
     *
     * La corsa non si assorbe catturando l'eccezione: sotto una transazione
     * Postgres la aborta, e ogni query successiva — la rilettura compresa —
     * fallisce. Si evita a monte, con un inserimento che sul conflitto non
     * fa nulla: chi arriva secondo rilegge la riga del primo, che e'
     * esattamente quella che voleva scrivere.
     */
    private function resolveActivityByIdentifier(string $identifier, string $fallbackName): TaxonomyActivity
    {
        $existing = TaxonomyActivity::query()->where('identifier', $identifier)->first();

        if ($existing !== null) {
            if (empty($existing->getAttribute('icon'))) {
                $existing->setAttribute('icon', $this->resolveIconNameByIdentifier($identifier));
                $existing->saveQuietly();
            }

            return $existing;
        }

        $now = now();

        TaxonomyActivity::query()->insertOrIgnore([
            'identifier' => $identifier,
            'name' => json_encode(['it' => $fallbackName], JSON_UNESCAPED_UNICODE),
            'icon' => $this->resolveIconNameByIdentifier($identifier),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $activity = TaxonomyActivity::query()->where('identifier', $identifier)->first();

        if ($activity === null) {
            throw new \RuntimeException("Impossibile risolvere l'attivita' {$identifier} dopo l'inserimento.");
        }

        return $activity;
    }

    /**
     * Cosa dice `field_frubilita` di un sentiero, in termini del modello unico
     * (oc:8910). Non scrive niente.
     *
     * Per ogni tid, col padre che la copia di Drupal dà nella gerarchia:
     * - radice → ignorata (vedi sotto);
     * - figlio fra i nove delle scale di accessibilità → due legami: il grado in
     *   `inclusivity-difficulty` e la categoria in `inclusivity-disabilities`;
     * - altro figlio → il termine `sardegnasentieri:tid:<tid>` nella sua vocabolario;
     * - un tid che il modello non conosce → anomalia, non eccezione.
     *
     * Le righe che puntano a una radice (Trekking, Mountain Bike, ...) non
     * producono niente, di proposito: dicono «percorso in mountain bike», ma le
     * attività di un sentiero vengono da `field_tipologia_sentiero`
     * (vocabolario `tipologia_sentieri`), che l'import legge già in
     * `syncTrackActivities()`. Dedurle da qui sarebbe ridondante e
     * rischierebbe di dare al sentiero un'attività che non ha. Non è una
     * dimenticanza, e nemmeno un'anomalia: la riga non ha un grado da scrivere.
     *
     * Un vocabolario a scelta singola con due termini diversi dopo il collasso
     * tiene il primo, nell'ordine di Drupal, e segnala gli altri: nessuna
     * scelta di dominio, ma nemmeno un'informazione persa in silenzio.
     *
     * @return array{
     *     terms: array<string, list<string>>,
     *     anomalies: list<array<string, mixed>>
     * }
     */
    private function classifyFruibilita(ApiTrackResponse $response): array
    {
        $wanted = [];
        $anomalies = [];

        foreach (array_values(array_unique($response->taxonomies->categorie_fruibilita_sentieri)) as $tid) {
            $tid = (string) $tid;
            $parent = $response->taxonomies->fruibilita_padri[$tid] ?? null;
            // Una radice non ha grado da scrivere: si ignora (vedi sopra). Un termine di cui la
            // copia non dà il padre passa invece dal controllo dei termini noti.
            if ($parent === '0') {
                continue;
            }

            if (isset(self::ACCESSIBILITY_TIDS[$tid])) {
                [$grade, $category] = self::ACCESSIBILITY_TIDS[$tid];
                $wanted['inclusivity-difficulty'][] = $grade;
                $wanted['inclusivity-disabilities'][] = $category;

                continue;
            }

            $term = $this->classificationTerms()->get(self::DRUPAL_TERM_PREFIX.$tid);
            // La tassonomia a cui il termine appartiene, non il vocabolario di Drupal.
            $taxonomy = $term?->parent?->identifier;

            if ($taxonomy === null) {
                $anomalies[] = ['type' => 'unknown_term', 'tid' => $tid];

                continue;
            }

            $wanted[$taxonomy][] = $term->identifier;
        }

        foreach ($wanted as $taxonomy => $identifiers) {
            $identifiers = array_values(array_unique($identifiers));
            $wanted[$taxonomy] = $identifiers;

            if (count($identifiers) > 1 && $this->classificationTaxonomies()->get($taxonomy)?->single_choice) {
                $wanted[$taxonomy] = [$identifiers[0]];
                $anomalies[] = [
                    'type' => 'multiple_terms_single_choice',
                    'taxonomy' => $taxonomy,
                    'kept' => $identifiers[0],
                    'discarded' => array_slice($identifiers, 1),
                ];
            }
        }

        return ['terms' => $wanted, 'anomalies' => $anomalies];
    }

    /**
     * Scrive i legami dei termini del sentiero (oc:8910).
     *
     * I legami di ogni vocabolario dell'import si sostituiscono: dopo il
     * reimport restano quelli che Drupal dice adesso, mai la somma. La
     * tabella non ha vincoli di unicità, quindi gli unici a evitare i doppioni
     * siamo noi: si aggiunge ciò che manca, si toglie ciò che non c'è più, e
     * un vocabolario che i doppioni li ha già si riscrive da zero.
     *
     * Il resto dei legami (vocabolari di Nova, tematismi, tipo percorso) non si tocca.
     *
     * `withoutSyncingToSearch`: senza, ogni riga di pivot farebbe partire un
     * reindex sincrono su Elasticsearch, e il reindex di fine batch lo fa già.
     * Conta la classe del package: l'observer del pivot risolve l'alias del
     * morph map in `Wm\WmPackage\Models\EcTrack`, ed è lì che il sync va
     * spento. La chiamata sulla classe di Forestas è ridondante e innocua; si
     * tiene per non dipendere da come si risolve l'alias.
     *
     * Non solleva mai per una classificazione che non torna: la segnala.
     */
    private function syncTrackTerms(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $vocabularies = $this->classificationTaxonomies();

        // Il vocabolario lo crea `sardegnasentieri:configure-taxonomy-terms`, che lancia il comando di import.
        if ($vocabularies->isEmpty()) {
            Log::channel('import')->warning("Vocabolari dei termini non trovate: legami del sentiero {$ecTrack->id} non scritti.");

            return;
        }

        $classification = $this->classifyFruibilita($response);
        $anomalies = $classification['anomalies'];
        $terms = $this->classificationTerms();

        if (($routeType = $this->routeTypeIdentifier($response)) !== null) {
            $classification['terms']['route-type'] = [$routeType];
        }

        // Tipologie di itinerario: multiple, senza il 4728 che è diventato un tipo di percorso.
        // Un sentiero non ne prende (il vocabolario si svuota comunque: è fra quelle dell'import).
        $classification['terms']['itinerary-type'] = $this->itineraryTypeIdentifiers($response);

        // Avvertenze: erano una tassonomia a sé con il suo pivot, ora sono un vocabolario come le
        // altre e seguono la stessa regola di sostituzione (oc:8910).
        $classification['terms'][self::WARNINGS_TAXONOMY] = array_map(
            fn ($apiId) => self::WARNING_TERM_PREFIX.$apiId,
            $response->taxonomies->tipologia_di_avvertenze ?? []
        );

        $wantedIds = [];
        foreach ($classification['terms'] as $vocabulary => $identifiers) {
            foreach ($identifiers as $identifier) {
                $term = $terms->get($identifier);
                if ($term === null) {
                    $anomalies[] = ['type' => 'missing_term', 'vocabulary' => $vocabulary, 'identifier' => $identifier];

                    continue;
                }
                $wantedIds[$term->parent_id][] = $term->id;
            }
        }

        $hasAccessibility = array_intersect(
            array_map('strval', $response->taxonomies->categorie_fruibilita_sentieri),
            array_map('strval', array_keys(self::ACCESSIBILITY_TIDS))
        ) !== [];

        $replaced = array_unique(array_merge(
            self::IMPORT_OWNED_TAXONOMIES,
            $hasAccessibility ? self::ACCESSIBILITY_FAMILIES : [],
            array_keys($classification['terms'])
        ));

        $current = $ecTrack->taxonomyTerms()->get()->groupBy('parent_id');

        \Wm\WmPackage\Models\EcTrack::withoutSyncingToSearch(fn () => EcTrack::withoutSyncingToSearch(function () use ($ecTrack, $vocabularies, $replaced, $wantedIds, $current) {
            foreach ($replaced as $vocabularyIdentifier) {
                $vocabulary = $vocabularies->get($vocabularyIdentifier);
                if ($vocabulary === null) {
                    continue;
                }

                $want = array_values(array_unique($wantedIds[$vocabulary->id] ?? []));
                $have = $current->get($vocabulary->id, collect())->pluck('id')->map(fn ($id) => (int) $id)->all();

                if (count($have) !== count(array_unique($have))) {
                    $ecTrack->taxonomyTerms()->detach($have);
                    $have = [];
                }

                $toDetach = array_values(array_diff($have, $want));
                $toAttach = array_values(array_diff($want, $have));

                if ($toDetach !== []) {
                    $ecTrack->taxonomyTerms()->detach($toDetach);
                }
                if ($toAttach !== []) {
                    $ecTrack->taxonomyTerms()->attach($toAttach);
                }
            }
        }));

        $ecTrack->unsetRelation('taxonomyTerms');

        $this->rememberClassificationAnomalies($ecTrack, $anomalies);
    }

    /**
     * Tipo di percorso dal bundle del nodo (oc:8910): `sentiero` è un sentiero; `itinerario`
     * è un cammino se porta il termine 4728 in `field_tipologia_itinerario`, altrimenti un
     * itinerario. Il cammino esce dall'itinerario, non si somma.
     */
    private function routeTypeIdentifier(ApiTrackResponse $response): ?string
    {
        return match ($response->type) {
            'sentiero' => 'route-type-trail',
            'itinerario' => in_array(self::CAMMINI_TERM_ID, array_map('strval', $response->taxonomies->tipologia_itinerari), true)
                ? 'route-type-walk'
                : 'route-type-itinerary',
            default => null,
        };
    }

    /**
     * Identifier dei termini di `itinerary-type`: i tid di `field_tipologia_itinerario` tranne il
     * 4728, e solo per gli itinerari.
     *
     * @return list<string>
     */
    private function itineraryTypeIdentifiers(ApiTrackResponse $response): array
    {
        if ($response->type !== 'itinerario') {
            return [];
        }

        return collect($response->taxonomies->tipologia_itinerari)
            ->map(fn ($tid) => (string) $tid)
            ->reject(fn (string $tid) => $tid === self::CAMMINI_TERM_ID)
            ->unique()
            ->map(fn (string $tid) => self::DRUPAL_TERM_PREFIX.$tid)
            ->values()
            ->all();
    }

    /**
     * Segna sul sentiero ciò che la classificazione non ha saputo scrivere, o
     * toglie il segno quando non c'è più niente da segnalare, e lo mette nel
     * log `import`. Stesso schema di `rememberEntiMancanti`: il sentiero
     * porta con sé cosa va corretto in Drupal (oc:8910).
     *
     * @param  list<array<string, mixed>>  $anomalies
     */
    private function rememberClassificationAnomalies(EcTrack $ecTrack, array $anomalies): void
    {
        $properties = is_array($ecTrack->properties) ? $ecTrack->properties : [];
        $before = $properties['forestas'][self::CLASSIFICATION_ANOMALIES_KEY] ?? [];

        foreach ($anomalies as $anomaly) {
            Log::channel('import')->warning("Sentiero {$ecTrack->id}: classificazione anomala.", $anomaly);
        }

        if ($before === $anomalies) {
            return;
        }

        if ($anomalies !== []) {
            $properties['forestas'][self::CLASSIFICATION_ANOMALIES_KEY] = $anomalies;
        } else {
            unset($properties['forestas'][self::CLASSIFICATION_ANOMALIES_KEY]);
        }

        $ecTrack->properties = $properties;
        $ecTrack->saveQuietly();
    }

    /**
     * I vocabolari del modello unico per identifier. Lette una volta per istanza.
     *
     * @return Collection<string, TaxonomyTerm>
     */
    private function classificationTaxonomies(): Collection
    {
        return $this->termTaxonomies ??= TaxonomyTerm::query()->taxonomies()->get()->keyBy('identifier');
    }

    /**
     * I termini del modello unico per identifier, ciascuno con la sua vocabolario.
     *
     * @return Collection<string, TaxonomyTerm>
     */
    private function classificationTerms(): Collection
    {
        return $this->termsByIdentifier ??= TaxonomyTerm::query()
            ->whereNotNull('parent_id')->with('parent')->get()->keyBy('identifier');
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
        $this->entiMancanti = false;
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
            $enteId = $this->resolveEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => $ruolo];
            }
        }

        $operatori = is_array($ente['riferimento_operatori_guid'] ?? null)
            ? $ente['riferimento_operatori_guid']
            : [];

        foreach ($operatori as $nodeId) {
            $enteId = $this->resolveEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'operatore'];
            }
        }

        // Anche senza enti: un POI a cui in Drupal li hanno tolti tutti deve
        // perdere i collegamenti, come i sentieri (oc:8513).
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

        $this->rememberEntiMancanti($ecPoi);
    }

    private function syncEnti(EcTrack $ecTrack, ApiTrackResponse $response): void
    {
        $this->entiMancanti = false;
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
            $enteId = $this->resolveEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => $ruolo];
            }
        }

        $operatori = is_array($ente['riferimento_operatori_guid'] ?? null)
            ? $ente['riferimento_operatori_guid']
            : [];

        foreach ($operatori as $nodeId) {
            $enteId = $this->resolveEnte((int) $nodeId);
            if ($enteId !== null) {
                $pairs[] = ['ente_id' => $enteId, 'ruolo' => 'operatore'];
            }
        }

        if ($response->complesso_forestale !== null) {
            $enteId = $this->resolveEnte((int) $response->complesso_forestale);
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

        $this->rememberEntiMancanti($ecTrack);
    }

    /**
     * Segna nel nodo se ha saltato un ente mancante, o toglie il segno quando
     * li ha trovati tutti: il comando rimette in coda i nodi segnati anche
     * con lo stesso `changed`, altrimenti il collegamento in produzione
     * tornerebbe solo al prossimo cambio del nodo in Drupal (oc:8513).
     */
    private function rememberEntiMancanti(EcPoi|EcTrack $model): void
    {
        $properties = is_array($model->properties) ? $model->properties : [];
        $before = $properties['forestas']['enti_mancanti'] ?? false;

        if ($before === $this->entiMancanti) {
            return;
        }

        if ($this->entiMancanti) {
            $properties['forestas']['enti_mancanti'] = true;
        } else {
            unset($properties['forestas']['enti_mancanti']);
        }

        $model->properties = $properties;
        $model->saveQuietly();
    }

    /**
     * L'id locale dell'ente richiamato da un POI o da un sentiero.
     *
     * Non lo importa: gli enti hanno un batch loro, che il comando fa girare
     * prima di quello di POI e sentieri (oc:8513). Un ente assente vuol dire
     * che il suo job e' fallito: il collegamento si salta e il nodo si
     * marca con `properties.forestas.enti_mancanti`, cosi' il comando lo
     * rilegge al giro dopo anche se in Drupal non e' cambiato.
     */
    private function resolveEnte(int $nodeId): ?int
    {
        $cacheKey = (string) $nodeId;

        if (! array_key_exists($cacheKey, $this->enteCache)) {
            $this->enteCache[$cacheKey] = Ente::where('sardegnasentieri_id', $cacheKey)->value('id');

            if ($this->enteCache[$cacheKey] === null) {
                Log::channel('import')->warning("Ente {$nodeId} non ancora importato: collegamento saltato.");
            }
        }

        if ($this->enteCache[$cacheKey] === null) {
            $this->entiMancanti = true;
        }

        return $this->enteCache[$cacheKey];
    }

    /**
     * Scrive l'ente com'e' in Drupal: un campo svuotato la' si svuota qui, e
     * le modifiche fatte in Nova si perdono al primo aggiornamento, perche'
     * finche' Drupal e' vivo la fonte e' una sola (oc:8513).
     *
     * Salva il `changed` di Drupal in `properties.forestas.updated_at`, come
     * sentieri e POI, per non riscrivere al giro dopo un ente non cambiato.
     * Un errore arriva al job, che si ritenta; un'immagine che non si scarica
     * resta un avviso nel log, come per sentieri e POI.
     */
    public function importOrUpdateEnte(string $sardegnaSentieriId, string $changed): int
    {
        $node = $this->source->getNodeDetail((int) $sardegnaSentieriId);
        $title = $node['title'][0]['value'] ?? null;

        if (empty($title)) {
            throw new \RuntimeException("Ente {$sardegnaSentieriId} senza titolo nella copia del DB Drupal");
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
                    $term = $this->source->getTaxonomyTerm($tipoEnteId);
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

        $ente = Ente::firstOrNew(['sardegnasentieri_id' => $sardegnaSentieriId]);
        $ente->setTranslations('name', ['it' => $title, 'en' => $title]);
        // Campi translatable come name: assegnarli come stringa nuda
        // popolerebbe solo la locale corrente.
        if ($contatti !== null) {
            $ente->setTranslations('contatti', ['it' => $contatti, 'en' => $contatti]);
        } else {
            $this->clearTranslatable($ente, 'contatti');
        }
        $ente->pagina_web = $paginaWeb;
        if ($description !== null && $description !== '') {
            $ente->setTranslations('description', ['it' => $description, 'en' => $description]);
        } else {
            $this->clearTranslatable($ente, 'description');
        }
        if ($tipoEnteValue instanceof TipoEnte) {
            $ente->tipo_ente = $tipoEnteValue;
        } else {
            // Arbitrary slug (not yet in enum): bypass Eloquent enum cast
            $rawAttrs = $ente->getAttributes();
            $rawAttrs['tipo_ente'] = $tipoEnteValue;
            $ente->setRawAttributes($rawAttrs);
        }
        $ente->geometry = ($lat !== null && $lon !== null) ? "POINT({$lon} {$lat})" : null;

        $properties = is_array($ente->properties) ? $ente->properties : [];
        $forestas = is_array($properties['forestas'] ?? null) ? $properties['forestas'] : [];
        unset($forestas['deleted_from_source']);
        $properties['forestas'] = ['updated_at' => $changed] + $forestas;
        $ente->properties = $properties;
        $ente->saveQuietly();

        // Senza immagine in Drupal la lista vuota toglie quella importata.
        $this->mediaSync->syncImportedImages($ente, $featureImageUrl === null ? [] : [
            ['url' => $featureImageUrl, 'autore' => '', 'credits' => '', 'order' => 0],
        ]);

        return $ente->id;
    }

    /**
     * Svuota un campo translatable: assegnare null passerebbe dal trait e
     * svuoterebbe solo la locale corrente, lasciando le altre.
     */
    private function clearTranslatable(Ente $ente, string $field): void
    {
        $ente->setRawAttributes(array_merge($ente->getAttributes(), [$field => null]));
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

        // La cache d'istanza qui sopra dura quanto il job che ci sta dentro, e
        // i job sono migliaia: senza quella condivisa, ognuno richiedeva di
        // nuovo alla sorgente gli stessi tre o quattro vocabolari, ed erano
        // quelle richieste — non i singoli POI — a far cadere l'import in
        // timeout. I vocabolari cambiano una volta al giorno, un'ora di
        // validita' e' abbondante (oc:8607).
        $normalized = Cache::remember(
            "sardegnasentieri:taxonomy:{$vocabulary}",
            now()->addHour(),
            function () use ($vocabulary) {
                $terms = $this->source->getTaxonomy($vocabulary);

                $normalized = [];
                foreach ($terms as $apiId => $term) {
                    if (is_array($term)) {
                        $normalized[(string) $apiId] = $term;
                    }
                }

                return $normalized;
            }
        );

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

    private const ICON_FALLBACK_MAP = [
        'crossroads' => 'txn-guidepost',
        'places-of-transhumance' => 'txn-horse',
        'monumental-tree' => 'txn-olive-tree',
        'natural-sprin' => 'txn-spring',
        'natural-spring' => 'txn-spring',
        'foresteria' => 'txn-lodging',
        'natural-cave-entrance' => 'txn-cave-entrance',
        'rifugio' => 'refuge',
        'natural-wood' => 'txn-natural',
        'coast-seaside' => 'txn-beach',
        'nature' => 'txn-park',
        'natural-park' => 'txn-park-alt',
        'archaeological-site' => 'txn-ruins',
        'public-transport' => 'txn-bus',
        'tlc' => 'communications-tower',
        'disabled-access' => 'txn-wheelchair',
        'sardegnasentieri:type:sentiero' => 'txn-hiking',
        'sardegnasentieri:type:itinerario' => 'txn-trail',
        'accessible-trails' => 'txn-mobility-disability',
        'educational-trails' => 'txn-environmental-education',
    ];

    private function resolveIconNameByIdentifier(string $identifier): ?string
    {
        $iconNames = $this->getIconNames();
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

        return self::ICON_FALLBACK_MAP[$identifier] ?? null;
    }

    /** @return array<int, string> */
    private function getIconNames(): array
    {
        if ($this->iconNamesCache !== null) {
            return $this->iconNamesCache;
        }

        $iconsData = $this->readIconsJson();
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

    /**
     * Legge icons.json direttamente dal path scritto da wm:generate-icons
     * (Wm\WmPackage\Services\StorageService::getShardBasePath($appId).'icons.json'),
     * bypassando GlobalFileHelper che costruisce un path diverso (bug noto in wm-package,
     * cerca /{shard}/json/icons.json invece di /{shard}/{appId}/icons.json) e non trova mai il file.
     *
     * @return array<string, mixed>|null
     */
    private function readIconsJson(): ?array
    {
        $path = app(StorageService::class)->getShardBasePath(self::IMPORT_APP_ID).'icons.json';

        if (! Storage::disk('wmfe')->exists($path)) {
            return null;
        }

        $content = Storage::disk('wmfe')->get($path);

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
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
