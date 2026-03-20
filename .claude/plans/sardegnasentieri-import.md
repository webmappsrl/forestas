# Piano: Import POI e Track da Sardegna Sentieri API

## Contesto

Import schedulato (giornaliero) di EcPoi ed EcTrack dall'API pubblica di Sardegna Sentieri.
NON usa il pattern Geohub — è un import custom da API esterna.

> **Piano vivo** — aggiornare questo file man mano che l'implementazione avanza o cambiano i requisiti. Aggiungere note su decisioni prese, problemi incontrati e cambiamenti al mapping.

**API endpoints — POI:**
- List: `GET https://www.sardegnasentieri.it/ss/listpoi/?_format=json` → `{"id": "timestamp"}`
- Detail: `GET https://www.sardegnasentieri.it/ss/poi/{id}?_format=json` → GeoJSON Feature

**API endpoints — Track:**
- List: `GET https://www.sardegnasentieri.it/ss/list-tracks/?_format=json` → `{"id": "timestamp"}` (~700 track)
- Detail: `GET https://www.sardegnasentieri.it/ss/track/{id}?_format=json` → GeoJSON Feature (geometry sempre null, coordinate nei file GPX)

**API endpoints — Taxonomy:**
- `GET https://www.sardegnasentieri.it/ss/tassonomia/tipologia_poi?_format=json` → TaxonomyPoiType
- `GET https://www.sardegnasentieri.it/ss/tassonomia/servizi?_format=json` → properties->forestas (nessun modello diretto)
- `GET https://www.sardegnasentieri.it/ss/tassonomia/categorie_fruibilita_sentieri?_format=json` → TaxonomyActivity
- `GET https://www.sardegnasentieri.it/ss/tassonomia/stato_di_validazione?_format=json` → properties->forestas
- `GET https://www.sardegnasentieri.it/ss/tassonomia/tipologia_itinerari?_format=json` → properties->forestas
- `GET https://www.sardegnasentieri.it/ss/tassonomia/tipologia_sentieri?_format=json` → properties->forestas

**Entità hardcoded da creare:**
- App: name=`Sardegna Sentieri`, sku=`it.webmapp.sardegnasentieri`
- User: name=`forestas`, email=`forestas@webmapp.it`, ruolo=`Editor`

## Mapping Campi — EcPoi

| Campo API | Campo EcPoi | Note |
|-----------|-------------|------|
| `properties.id` | `out_source_feature_id` | ID esterno |
| `properties.name` (it/en) | `name` | Translatable nativo |
| `properties.description` (it/en) | `properties->description` | Translatable nativo |
| `geometry` [lon,lat] | `geometry` | Z calcolata da DEM auto via `setGeometryAttribute()` |
| `properties.addr_locality` | `addr_complete` | Campo nativo |
| `properties.codice` | `properties->forestas->codice` | |
| `properties.collegamenti` | `properties->forestas->collegamenti` | |
| `properties.come_arrivare` | `properties->forestas->come_arrivare` | |
| `properties.url` | `properties->forestas->url` | |
| `properties.updated_at` | `properties->forestas->updated_at` | Per sync incrementale |
| `properties.immagine_principale` | EcMedia (immagine principale) | |
| `properties.galleria` | EcMedia (galleria) | |
| `taxonomies.tipologia_poi` | `taxonomyPoiTypes` sync | Via `identifier = geohub_identifier` |
| `taxonomies.zona_geografica` | `properties->forestas->zona_geografica` | Raw IDs, nessun modello diretto |
| *(hardcoded)* | `app_id` | App "Sardegna Sentieri" |
| *(hardcoded)* | `user_id` | User "forestas" |

## Mapping Campi — EcTrack

| Campo API | Campo EcTrack | Note |
|-----------|---------------|------|
| `properties.id` | `properties->sardegnasentieri_id` | ID esterno per updateOrCreate |
| `properties.name` (it/en) | `name` | Translatable nativo |
| `properties.description` (it/en) | `properties->description` | Translatable nativo |
| `properties.excerpt` (it/en) | `properties->excerpt` | Translatable nativo |
| `geometry` (null→GPX) | `geometry` | Parse GPX → WKT MULTILINESTRING Z con elevazione |
| `properties.lunghezza` | `properties->forestas->lunghezza` | Distanza in metri (string) |
| `properties.dislivello_totale` | `properties->forestas->dislivello_totale` | Dislivello in metri (string) |
| `properties.durata` | `properties->forestas->durata` | Durata in secondi (string) |
| `properties.type` | `properties->forestas->type` | es. "itinerario" |
| `properties.allegati` | `properties->forestas->allegati` | Array URL PDF |
| `properties.video` | `properties->forestas->video` | Array URL YouTube |
| `properties.gpx` | `properties->forestas->gpx` | Array URL file GPX |
| `properties.url` | `properties->forestas->url` | |
| `properties.updated_at` | `properties->forestas->updated_at` | Per sync incrementale |
| `properties.partenza` | `ecPois()->sync()` | EcPoi via `out_source_feature_id` |
| `properties.arrivo` | `ecPois()->sync()` | EcPoi via `out_source_feature_id` (stessa relazione) |
| `properties.immagine_principale` | EcMedia | |
| `properties.galleria_immagini` | EcMedia | |
| `taxonomies.categorie_fruibilita_sentieri` | `taxonomyActivities()->sync()` | Via `identifier = geohub_identifier` |
| `taxonomies.stato_di_validazione` | `stato_validazione` (colonna nativa) | Enum `StatoValidazione`, mappa per ID |
| `taxonomies.zona_geografica` | `properties->forestas->zona_geografica` | Raw IDs |
| *(hardcoded)* | `app_id` | App "Sardegna Sentieri" |
| *(hardcoded)* | `user_id` | User "forestas" |

**Note EcTrack:**
- `EcTrack` non ha `out_source_feature_id` — usare `properties->sardegnasentieri_id` come chiave per `updateOrCreate` con `whereRaw("properties->>'sardegnasentieri_id' = ?", [$id])`
- `partenza` e `arrivo` sono entrambi linkati come `ecPois()` (BelongsToMany via `ec_poi_ec_track`) — sync di entrambi insieme
- Geometry da GPX: struttura `<trkpt lat="..." lon="..."><ele>...</ele></trkpt>` → WKT `MULTILINESTRING Z ((lon lat ele, ...))`
- Se più track segment nel GPX → ogni `<trkseg>` diventa un segmento del MULTILINESTRING
- La Z viene dall'elevazione nel GPX stesso (non dal DEM) — EcTrack non ha `setGeometryAttribute()` automatico

## Phase 0 — Documentation Discovery (COMPLETATA)

**Findings chiave:**
- `EcPoi::setGeometryAttribute()` calcola automaticamente la Z dal DEM — passare solo WKT `POINT(lon lat)`
- `TaxonomyPoiType` ha campo `identifier` (unique) — matching con `geohub_identifier` dell'API
- Sync tassonomie via `$ecPoi->taxonomyPoiTypes()->sync($ids)` (morph-to-many)
- `out_source_feature_id` è il campo per l'ID esterno
- Nessun service/command custom esistente in `app/` — tutto da creare
- Pattern HTTP client esistente: `wm-package/src/Http/Clients/OsmClient.php`

**Anti-pattern identificati:**
- NON usare `attach()` per tassonomie (crea duplicati) — usare `sync()`
- NON passare geometry come GeoJSON — usare WKT
- NON importare Z per EcPoi dalla API — lasciarla calcolare dal DEM
- Per EcTrack la Z viene dal GPX (elevazione già presente nei trkpt)
- NON usare `App::find()` senza fallback — App/User potrebbero non esistere se il seeder non è stato eseguito

---

## Phase 1 — Enum + Migration StatoValidazione

**Files da creare:**
- `app/Enums/StatoValidazione.php`
- `database/migrations/YYYY_MM_DD_add_stato_validazione_to_ec_tracks_table.php`

**Files da modificare:**
- `app/Nova/EcTrack.php`

**Enum (`app/Enums/StatoValidazione.php`):**
```php
enum StatoValidazione: string
{
    case NonVerificato           = 'non_verificato';
    case NonPercorribile         = 'non_percorribile';
    case InRevisioneValidazione  = 'in_revisione_validazione';
    case InPreAccatastamento     = 'in_pre_accatastamento';
    case Percorribile            = 'percorribile';
    case Validato                = 'validato';
    case Certificato             = 'certificato';

    // Mappa hardcodata ID API → enum case (stato_di_validazione non ha geohub_identifier)
    public static function fromApiId(string $id): ?self
    {
        return match ($id) {
            '4871' => self::NonVerificato,
            '4870' => self::NonPercorribile,
            '4869' => self::InRevisioneValidazione,
            '4868' => self::InPreAccatastamento,
            '4187' => self::Percorribile,
            '4188' => self::Validato,
            '4189' => self::Certificato,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NonVerificato          => 'Non verificato',
            self::NonPercorribile        => 'Non percorribile',
            self::InRevisioneValidazione => 'In revisione-validazione',
            self::InPreAccatastamento    => 'In pre-accatastamento o manutenzione',
            self::Percorribile           => 'Percorribile',
            self::Validato               => 'Validato',
            self::Certificato            => 'Certificato',
        };
    }
}
```

**Migration:**
```php
Schema::table('ec_tracks', function (Blueprint $table) {
    $table->string('stato_validazione')->nullable()->after('osmid');
});
```

**Nova EcTrack (`app/Nova/EcTrack.php`):**
```php
use Laravel\Nova\Fields\Select;
use App\Enums\StatoValidazione;

class EcTrack extends WmNovaEcTrack
{
    public function fields(NovaRequest $request): array
    {
        return array_merge(parent::fields($request), [
            Select::make('Stato validazione', 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
        ]);
    }
}
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan migrate
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  echo \Wm\WmPackage\Models\EcTrack::whereNotNull('stato_validazione')->count();
"
```

---

## Phase 2 — App + User Seeder (ex Phase 1)

**Files da creare:**
- `database/seeders/SardegnaSentieriSeeder.php`

**Files da modificare:**
- `database/seeders/DatabaseSeeder.php`

**Implementazione:**
```php
// App
App::firstOrCreate(
    ['sku' => 'it.webmapp.sardegnasentieri'],
    ['name' => 'Sardegna Sentieri']
);

// User
$user = User::firstOrCreate(
    ['email' => 'forestas@webmapp.it'],
    [
        'name'     => 'forestas',
        'password' => Hash::make(Str::random(32)),
    ]
);
$user->assignRole('Editor');
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan db:seed --class=SardegnaSentieriSeeder
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  echo \Wm\WmPackage\Models\App::where('sku','it.webmapp.sardegnasentieri')->value('id');
  echo \App\Models\User::where('email','forestas@webmapp.it')->value('id');
"
```

---

## Phase 2 — HTTP Client + Taxonomy Import Service

**Files da creare:**
- `app/Http/Clients/SardegnaSentieriClient.php`
- `app/Services/Import/SardegnaSentieriTaxonomyService.php`

**Client (`SardegnaSentieriClient`):**
```php
const BASE_URL = 'https://www.sardegnasentieri.it/ss';

public function getPoiList(): array             // GET /listpoi/?_format=json
public function getPoiDetail(int $id): array    // GET /poi/{id}?_format=json
public function getTrackList(): array           // GET /list-tracks/?_format=json
public function getTrackDetail(int $id): array  // GET /track/{id}?_format=json
public function getTaxonomy(string $vocab): array  // GET /tassonomia/{vocab}?_format=json
public function getGpxContent(string $url): string // HTTP GET raw GPX file
```
- Usare `Http::get()` con gestione errori (`throw_if`, `Http::timeout()`)

**Taxonomy Service (`SardegnaSentieriTaxonomyService`):**
```php
// Mappati a modelli Laravel:
const POI_VOCABULARIES   = ['tipologia_poi'];                    // → TaxonomyPoiType
const TRACK_VOCABULARIES = ['categorie_fruibilita_sentieri'];    // → TaxonomyActivity

// Solo salvati in properties->forestas (nessun modello diretto):
// 'servizi', 'tipologia_itinerari', 'tipologia_sentieri'
// 'stato_di_validazione' → colonna nativa ec_tracks.stato_validazione (enum)

public function importAll(): void
// Per ogni POI_VOCABULARY:
//   TaxonomyPoiType::updateOrCreate(['identifier' => $term['geohub_identifier']], ['name' => $term['name']])
// Per ogni TRACK_VOCABULARY:
//   TaxonomyActivity::updateOrCreate(['identifier' => $term['geohub_identifier']], ['name' => $term['name']])
// Skippare term con geohub_identifier null/vuoto

public function resolvePoiTypeId(string $apiTermId): ?int
// Cerca TaxonomyPoiType via geohub_identifier mappando l'apiTermId
// Cache locale per evitare query ripetute

public function resolveActivityId(string $apiTermId): ?int
// Stesso pattern per TaxonomyActivity
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  app(\App\Services\Import\SardegnaSentieriTaxonomyService::class)->importAll();
  echo 'PoiTypes: ' . \Wm\WmPackage\Models\TaxonomyPoiType::count();
  echo 'Activities: ' . \Wm\WmPackage\Models\TaxonomyActivity::count();
"
```

---

## Phase 3 — EcPoi Import Service + Job

**Files da creare:**
- `app/Services/Import/SardegnaSentieriPoiImportService.php`
- `app/Jobs/Import/ImportSardegnaSentieriPoiJob.php`

**Import Service — mapping:**
```php
$forestasData = [
    'codice'          => $props['codice'] ?? null,
    'collegamenti'    => $props['collegamenti'] ?? [],
    'come_arrivare'   => $props['come_arrivare'] ?? null,
    'url'             => $props['url'] ?? null,
    'updated_at'      => $props['updated_at'] ?? null,
    'zona_geografica' => $props['taxonomies']['zona_geografica'] ?? [],
];

$data = [
    'app_id'                => App::where('sku', 'it.webmapp.sardegnasentieri')->value('id'),
    'user_id'               => User::where('email', 'forestas@webmapp.it')->value('id'),
    'out_source_feature_id' => (string) $externalId,
    'name'                  => $props['name'],            // già array {it, en}
    'addr_complete'         => $props['addr_locality'] ?? null,
    'geometry'              => "POINT({$coords[0]} {$coords[1]})",  // WKT, Z dal DEM
    'properties'            => array_merge($existing ?? [], [
        'description' => $props['description'],           // array {it, en}
        'forestas'    => $forestasData,
    ]),
];

$ecPoi = EcPoi::updateOrCreate(
    ['out_source_feature_id' => (string) $externalId],
    $data
);
```

**Taxonomy sync:**
```php
$poiTypeIds = collect($props['taxonomies']['tipologia_poi'] ?? [])
    ->map(fn($apiId) => $this->resolvePoiTypeId($apiId))
    ->filter()
    ->values();

$ecPoi->taxonomyPoiTypes()->sync($poiTypeIds);
```

**Job (`ImportSardegnaSentieriPoiJob`):**
```php
// implements ShouldQueue
// Queue: 'default'
// Tries: 3, Timeout: 120s
public function __construct(public readonly int $externalId) {}

public function handle(SardegnaSentieriPoiImportService $service): void
{
    $service->importPoi($this->externalId);
}
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  \App\Jobs\Import\ImportSardegnaSentieriPoiJob::dispatchSync(94);
  \$poi = \Wm\WmPackage\Models\EcPoi::where('out_source_feature_id','94')->first();
  echo \$poi->name . ' | geometry: ' . \$poi->getRawOriginal('geometry');
"
```

---

## Phase 4 — EcMedia (Immagini)

**Files da modificare:**
- `app/Services/Import/SardegnaSentieriPoiImportService.php` (aggiungere `syncMedia()`)

**Task:**
1. Leggere modello EcMedia in wm-package per campi disponibili
2. `immagine_principale` → `EcMedia::updateOrCreate(['properties->source_url' => $url], [...])`
3. `galleria` → stesso pattern per ogni item
4. Collegare con `$ecPoi->ecMedia()->sync($mediaIds)` (verificare nome relazione)

**Anti-pattern:** NON re-scaricare immagini già importate — check su `source_url`

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  \Wm\WmPackage\Models\EcPoi::where('out_source_feature_id','94')->first()->ecMedia()->count();
"
```

---

## Phase 5 — EcTrack Import Service + Job

**Files da creare:**
- `app/Services/Import/SardegnaSentieriTrackImportService.php`
- `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php`

**GPX Parser helper (dentro il service):**
```php
private function parseGpxToWkt(string $gpxContent): ?string
{
    $xml = simplexml_load_string($gpxContent);
    if (!$xml) return null;

    $segments = [];
    foreach ($xml->trk as $trk) {
        foreach ($trk->trkseg as $seg) {
            $points = [];
            foreach ($seg->trkpt as $pt) {
                $lon = (float) $pt['lon'];
                $lat = (float) $pt['lat'];
                $ele = isset($pt->ele) ? (float) $pt->ele : 0.0;
                $points[] = "$lon $lat $ele";
            }
            if (!empty($points)) {
                $segments[] = '(' . implode(', ', $points) . ')';
            }
        }
    }

    if (empty($segments)) return null;
    return 'MULTILINESTRING Z (' . implode(', ', $segments) . ')';
}
```

**Import Service — mapping:**
```php
public function importTrack(int $externalId): EcTrack
{
    $feature  = $this->client->getTrackDetail($externalId);
    $props    = $feature['properties'];
    $appId    = App::where('sku', 'it.webmapp.sardegnasentieri')->value('id');
    $userId   = User::where('email', 'forestas@webmapp.it')->value('id');

    // Geometry: scarica il primo GPX disponibile
    $geometry = null;
    foreach ($props['gpx'] ?? [] as $gpxUrl) {
        $gpxContent = $this->client->getGpxContent($gpxUrl);
        $geometry   = $this->parseGpxToWkt($gpxContent);
        if ($geometry) break;
    }

    $forestasData = [
        'source_id'               => (string) $externalId,
        'lunghezza'               => $props['lunghezza'] ?? null,
        'dislivello_totale'       => $props['dislivello_totale'] ?? null,
        'durata'                  => $props['durata'] ?? null,
        'type'                    => $props['type'] ?? null,
        'allegati'                => $props['allegati'] ?? [],
        'video'                   => $props['video'] ?? [],
        'gpx'                     => $props['gpx'] ?? [],
        'url'                     => $props['url'] ?? null,
        'updated_at'              => $props['updated_at'] ?? null,
        // stato_di_validazione → colonna nativa, non in forestas
        'zona_geografica'         => $props['taxonomies']['zona_geografica'] ?? [],
    ];

    $data = [
        'app_id'   => $appId,
        'user_id'  => $userId,
        'name'     => $props['name'],
        'properties' => array_merge($existing ?? [], [
            'sardegnasentieri_id' => (string) $externalId,
            'description'         => $props['description'] ?? null,
            'excerpt'             => $props['excerpt'] ?? null,
            'forestas'            => $forestasData,
        ]),
    ];
    if ($geometry) $data['geometry'] = $geometry;

    // Stato validazione → enum
    $statoId = $props['taxonomies']['stato_di_validazione'][0] ?? null;
    $data['stato_validazione'] = $statoId
        ? StatoValidazione::fromApiId($statoId)?->value
        : null;

    // updateOrCreate via JSONB key
    $ecTrack = EcTrack::firstWhere(
        DB::raw("properties->>'sardegnasentieri_id'"),
        (string) $externalId
    ) ?? new EcTrack();
    $ecTrack->fill($data)->save();

    // Sync POI relati (partenza + arrivo)
    $this->syncRelatedPois($ecTrack, $props);

    // Sync tassonomie
    $this->syncTaxonomies($ecTrack, $props);

    return $ecTrack;
}

private function syncRelatedPois(EcTrack $ecTrack, array $props): void
{
    $poiIds = collect([$props['partenza'] ?? null, $props['arrivo'] ?? null])
        ->filter()
        ->map(fn($sourceId) => EcPoi::where('out_source_feature_id', $sourceId)->value('id'))
        ->filter()
        ->values()
        ->toArray();

    $ecTrack->ecPois()->sync($poiIds);
}
```

**Job (`ImportSardegnaSentieriTrackJob`):**
```php
// implements ShouldQueue
// Queue: 'default'
// Tries: 3, Timeout: 300s (GPX download può essere lento)
public function __construct(public readonly int $externalId) {}

public function handle(SardegnaSentieriTrackImportService $service): void
{
    $service->importTrack($this->externalId);
}
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  \App\Jobs\Import\ImportSardegnaSentieriTrackJob::dispatchSync(75);
  \$t = \Wm\WmPackage\Models\EcTrack::whereRaw(\"properties->>'sardegnasentieri_id' = '75'\")->first();
  echo \$t->name . ' | pois: ' . \$t->ecPois()->count();
"
```

---

## Phase 6 — EcMedia per Track

**Files da modificare:**
- `app/Services/Import/SardegnaSentieriTrackImportService.php` (aggiungere `syncMedia()`)

**Task:**
- Stessa logica della Phase 4 (EcMedia per POI)
- `immagine_principale` e `galleria_immagini` → EcMedia via `properties->source_url`
- Collegare con la relazione media di EcTrack (verificare nome relazione nel modello)

---

## Phase 7 — Artisan Command + Scheduler

**Files da creare:**
- `app/Console/Commands/ImportSardegnaSentieriCommand.php`

**Files da modificare:**
- `routes/console.php`

**Command:**
```php
// Signature: sardegnasentieri:import {--force : Reimporta tutti ignorando updated_at}
//                                    {--only= : pois|tracks — importa solo un tipo}
public function handle(): void
{
    // 1. Import tassonomie (sempre)
    $this->info('Importing taxonomies...');
    $this->taxonomyService->importAll();

    $only = $this->option('only');

    // 2. POI
    if (!$only || $only === 'pois') {
        $poiList = $this->client->getPoiList();
        $poiCount = 0;
        foreach ($poiList as $id => $apiTimestamp) {
            if (!$this->option('force')) {
                $existing = EcPoi::where('out_source_feature_id', $id)
                    ->value('properties->forestas->updated_at');
                if ($existing === $apiTimestamp) continue;
            }
            ImportSardegnaSentieriPoiJob::dispatch((int) $id);
            $poiCount++;
        }
        $this->info("Dispatched {$poiCount} POI import jobs.");
    }

    // 3. Track
    if (!$only || $only === 'tracks') {
        $trackList = $this->client->getTrackList();
        $trackCount = 0;
        foreach ($trackList as $id => $apiTimestamp) {
            if (!$this->option('force')) {
                $existing = EcTrack::whereRaw("properties->>'sardegnasentieri_id' = ?", [$id])
                    ->value("properties->'forestas'->>'updated_at'");
                if ($existing === $apiTimestamp) continue;
            }
            ImportSardegnaSentieriTrackJob::dispatch((int) $id);
            $trackCount++;
        }
        $this->info("Dispatched {$trackCount} Track import jobs.");
    }
}
```

**Scheduler in `routes/console.php`:**
```php
Schedule::command('sardegnasentieri:import')->dailyAt('03:00');
```

**Verification:**
```bash
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --only=pois
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --only=tracks
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --force
docker exec -it php-${APP_NAME} php artisan schedule:list
```

---

## Phase 8 — Comando Reset (reimport da zero)

**Files da creare:**
- `app/Console/Commands/ResetSardegnaSentieriCommand.php`

**Obiettivo:** eliminare tutti i dati importati da Sardegna Sentieri e permettere un reimport pulito.

**Command:**
```php
// Signature: sardegnasentieri:reset {--yes : Salta la conferma interattiva}
public function handle(): void
{
    if (!$this->option('yes') && !$this->confirm('Eliminare tutti i dati Sardegna Sentieri? Questa operazione è irreversibile.')) {
        return;
    }

    $app = App::where('sku', 'it.webmapp.sardegnasentieri')->firstOrFail();

    // 1. EcPoi: rimuove relazioni e record
    $pois = EcPoi::where('app_id', $app->id)->get();
    foreach ($pois as $poi) {
        $poi->taxonomyPoiTypes()->detach();
        $poi->ecMedia()->detach();   // pivot, non elimina EcMedia
        $poi->delete();
    }
    $this->info("Eliminati {$pois->count()} EcPoi.");

    // 2. EcTrack: rimuove relazioni e record
    $tracks = EcTrack::where('app_id', $app->id)->get();
    foreach ($tracks as $track) {
        $track->taxonomyActivities()->detach();
        $track->ecPois()->detach();
        $track->ecMedia()->detach();
        $track->delete();
    }
    $this->info("Eliminati {$tracks->count()} EcTrack.");

    // 3. EcMedia orfani (source da sardegnasentieri — identificati da properties->source_url contenente il dominio)
    $mediaDeleted = EcMedia::where('properties->source_domain', 'sardegnasentieri.it')->delete();
    $this->info("Eliminati {$mediaDeleted} EcMedia.");

    $this->info('Reset completato. Eseguire sardegnasentieri:import --force per reimportare.');
}
```

**Uso:**
```bash
# Con conferma interattiva
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:reset

# Senza conferma (utile per script)
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:reset --yes

# Reset + reimport completo
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:reset --yes && \
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --force
```

**Note implementative:**
- NON eliminare l'App e l'utente `forestas` — solo i dati importati
- NON eliminare TaxonomyPoiType e TaxonomyActivity — sono condivisi con altri dati
- Aggiungere `properties->source_domain` in EcMedia durante l'import per facilitare l'identificazione degli orfani

---

## Phase 9 — PHPStan + Verifica Finale

**Task:**
1. `vendor/bin/phpstan analyse app/Enums/ app/Http/Clients/ app/Services/Import/ app/Jobs/Import/ app/Console/Commands/`
2. Fix errori PHPStan
3. Test end-to-end POI: import di 3 POI, verifica in Nova EcPoi (geometry con Z, tassonomie, EcMedia)
4. Test end-to-end Track: import di 3 track, verifica in Nova EcTrack (geometry MULTILINESTRING Z, ecPois linkati, stato_validazione, tassonomie)
5. Verificare `--only=pois` e `--only=tracks` separatamente
6. Verificare reset + reimport: `sardegnasentieri:reset --yes && sardegnasentieri:import --force`

**Anti-pattern checklist finale:**
- [ ] Geometry EcPoi: WKT `POINT(lon lat)` — Z dal DEM, non hardcodata
- [ ] Geometry EcTrack: WKT `MULTILINESTRING Z ((...))` — Z dall'elevazione GPX
- [ ] `sync()` per tassonomie e per ecPois, non `attach()`
- [ ] EcPoi: `updateOrCreate` su `out_source_feature_id`
- [ ] EcTrack: lookup via `whereRaw("properties->>'sardegnasentieri_id' = ?")`
- [ ] GPX parsing: gestire più `<trkseg>` → più segmenti nel MULTILINESTRING
- [ ] Nessuna `App::find()` / `User::find()` senza fallback
- [ ] EcMedia: salvare `properties->source_domain` per facilitare il reset
- [ ] Reset: NON eliminare App, User, TaxonomyPoiType, TaxonomyActivity
