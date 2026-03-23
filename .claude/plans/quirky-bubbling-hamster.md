# DTO: tipizzare anche l'input (risposta API)

## Contesto

`fromApi(array $props)` è opaco quanto prima: nessun autocomplete sull'input, nessuna
validazione statica, nessuna garanzia che un campo esista. La soluzione è tipizzare anche
la risposta API con DTO dedicati, così la catena diventa completamente tipizzata:

```
JSON → ApiPoiResponse (input) → PoiPropertiesData (output DB)
                     ↓
              ForestasPoiData
```

Il developer vede i campi disponibili sia sull'input (ApiPoiResponse) sia sull'output
(PoiPropertiesData). Se usa un campo inesistente, PHPStan e l'IDE lo segnalano subito.

## Struttura DTO aggiornata

```
app/Dto/
├── Api/
│   ├── ApiPoiResponse.php         # risposta GET /poi/{id}
│   ├── ApiTrackResponse.php       # risposta GET /track/{id}
│   └── ApiTaxonomiesData.php      # sub-DTO per il blocco taxonomies comune
└── Import/
    ├── ForestasPoiData.php        # fromApiResponse(ApiPoiResponse) invece di fromApi(array)
    ├── ForestasTrackData.php      # fromApiResponse(ApiTrackResponse)
    ├── PoiPropertiesData.php      # fromApiResponse(ApiPoiResponse)
    └── TrackPropertiesData.php    # fromApiResponse(ApiTrackResponse, array $existingManual)
```

## DTO da creare

### `app/Dto/Api/ApiTaxonomiesData.php`
Sub-DTO condiviso da POI e Track:
```php
readonly class ApiTaxonomiesData
{
    public function __construct(
        /** @var list<string> */
        public array $tipologia_poi = [],
        /** @var list<string> */
        public array $categorie_fruibilita_sentieri = [],
        /** @var list<string> */
        public array $tipologia_sentieri = [],
        /** @var list<string> */
        public array $stato_di_validazione = [],
        /** @var list<string> */
        public array $zona_geografica = [],
    ) {}

    public static function fromArray(array $taxonomies): self { ... }
}
```

### `app/Dto/Api/ApiPoiResponse.php`
```php
readonly class ApiPoiResponse
{
    public function __construct(
        public string $id,
        /** @var array<string, string> Translatable */
        public array $name,
        /** @var array<string, string>|null */
        public ?array $description,
        public ?string $addr_locality,
        public ?string $codice,
        /** @var list<mixed> */
        public array $collegamenti,
        public ?string $come_arrivare,
        public ?string $url,
        public ?string $updated_at,
        /** @var array{type: string, coordinates: list<float>}|null */
        public ?array $geometry,
        public ApiTaxonomiesData $taxonomies,
    ) {}

    public static function fromJson(array $feature): self { ... }  // unico fromArray
}
```

### `app/Dto/Api/ApiTrackResponse.php`
```php
readonly class ApiTrackResponse
{
    public function __construct(
        public string $id,
        /** @var array<string, string> */
        public array $name,
        /** @var array<string, string>|null */
        public ?array $description,
        /** @var array<string, string>|null */
        public ?array $excerpt,
        public ?string $lunghezza,
        public ?string $dislivello_totale,
        public ?string $durata,
        public ?string $type,
        /** @var list<string> URL PDF */
        public array $allegati,
        /** @var list<string> URL YouTube */
        public array $video,
        /** @var list<string> URL GPX */
        public array $gpx,
        public ?string $url,
        public ?string $updated_at,
        public ?string $partenza,
        public ?string $arrivo,
        public ApiTaxonomiesData $taxonomies,
    ) {}

    public static function fromJson(array $feature): self { ... }
}
```

## Modifiche ai DTO esistenti

### `ForestasPoiData`, `ForestasTrackData`, `PoiPropertiesData`, `TrackPropertiesData`
- Sostituire `fromApi(array $props)` con `fromApiResponse(ApiPoiResponse|ApiTrackResponse $response)`
- Tutti i campi diventano accessi tipizzati: `$response->codice` invece di `$props['codice'] ?? null`

### `SardegnaSentieriClient`
- `getPoiDetail(int $id): ApiPoiResponse` invece di `array`
- `getTrackDetail(int $id): ApiTrackResponse` invece di `array`
- Il parsing JSON → DTO avviene nel client, una volta sola

### `SardegnaSentieriImportService`
- `importPoi()`: riceve `ApiPoiResponse` dal client direttamente
- `importTrack()`: riceve `ApiTrackResponse` dal client direttamente
- `$feature['properties'] ?? []` sparisce — tutto tipizzato

## Verifica

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse app/Dto/ app/Http/Clients/ app/Services/Import/
docker exec -it php-forestas vendor/bin/pest tests/Feature/Import/
```
