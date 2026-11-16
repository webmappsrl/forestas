> Ticket: oc:8965

# API degli enti per il nuovo sito WordPress — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** forestas espone a WordPress, dietro un token statico, un JSON unico con tutti gli enti e
una ricerca degli enti su Elasticsearch, con descrizioni in HTML filtrato, slug ASCII e immagini
con le miniature.

**Architecture:** rotte `api/v1/entes/*` registrate in `AppServiceProvider` (come il SUS) e
protette da un middleware che confronta un token di `config/forestas.php`. Il JSON è una
`JsonResource` su `Ente` con eager loading; la ricerca è Scout (Matchish) su `Ente`, con il mapping
aggiunto in `AppServiceProvider::boot()` e una query `multi_match` costruita da una classe dedicata.
Il filtro HTML è un mutator del modello, quindi vale per import, Nova e `tinker`.

**Tech Stack:** Laravel 12, PHP 8.4, Pest, Scout + `matchish/laravel-scout-elasticsearch`,
`ezyang/htmlpurifier` 4.19, `spatie/laravel-medialibrary`, `spatie/laravel-translatable`, Nova 5.7.6.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Repo: solo **forestas**. Il `wm-package` non si modifica.
- Identificatori in inglese; commenti, docblock e documentazione in italiano, con `(oc:8965)`
  dove il commento spiega una scelta di questo ticket.
- Nessun `git commit`, `git add`, `git push` durante l'esecuzione: i passi «Commit» sotto sono
  istruzioni testuali per il dev.
- **Prima di lanciare qualsiasi test leggi `.claude/rules/test.md`** e verifica che `.env.testing`
  esista e punti a `forestas_testing`. Se non è verificabile, non lanciare e chiedi.
- Nei test `SCOUT_DRIVER=collection` (`.env.testing-example:25`): nessun test richiede
  Elasticsearch acceso.
- Token: `config('forestas.wp_api_token')` da `WP_API_TOKEN`; vuoto → sempre `403`,
  token mancante o errato → `401`, come `wm-package/src/Http/Middleware/EnsureExportToken.php`.
- Whitelist HTML: `p`, `br`, `h2`, `h3`, `h4`, `h5`, `h6`, `a[href]`, `em`, `strong`, `ul`, `ol`, `li`.
- Conversioni immagini: `config('wm-package.services.image.thumbnail_sizes')` (12 misure),
  collection `default`, accodate.
- Comandi: `docker exec php-forestas vendor/bin/pest --filter=<nome>`;
  `docker exec php-forestas vendor/bin/phpstan analyse`; `docker exec php-forestas composer format`.

## Review Focus

- **Ricerca con caratteri della sintassi Lucene** (`Comune di Oliena (NU`, `a:b`, `~`): la rotta
  risponde 200, la query non usa `query_string`. Test in Task 5.
- **Token configurato vuoto e richiesta con `Authorization: Bearer ` vuoto**: `403`, mai `200`
  (`hash_equals('', '')` sarebbe vero). Test in Task 3.
- **Descrizione salvata da Nova o `tinker` con `<script>`, `style=`, `<a target onclick>`**: esce
  filtrata, anche quando si scrive una sola lingua con `setTranslation()`. Test in Task 2.
- **Ente marcato `deleted_from_source`**: assente dal JSON e non indicizzabile; tracce e POI marcati
  assenti dai collegamenti. Test in Task 1 e Task 4.
- **Slug da Drupal con accenti** (`comune-di-lodè`): salvato `comune-di-lode`. Test in Task 6.

---

### Task 1: Modello `Ente` — POI collegati, enti attivi, conversioni

**Files:**
- Modify: `app/Models/Ente.php`
- Test: `tests/Feature/Ente/EnteModelTest.php` (nuovo)

**Interfaces:**
- Produces:
  - `Ente::ecPois(): BelongsToMany` — verso `App\Models\EcPoi` su `enteables`
    (`enteable_id` → `ente_id`), `->wherePivot('enteable_type', Wm\WmPackage\Models\EcPoi::class)`,
    `->withPivot('ruolo')`; speculare a `EcPoi::enti()` (`app/Models/EcPoi.php:63-67`).
  - `Ente::scopeActive(Builder $query): Builder` — esclude
    `(properties->'forestas'->>'deleted_from_source') = 'true'`.
  - `Ente::registerMediaConversions(?Media $media = null): void` — come
    `GeometryModel::registerMediaConversions()` (`wm-package/src/Models/Abstracts/GeometryModel.php`):
    una conversione per misura di `MediaService::make()->getThumbnailSizes()`, nome da
    `getMediaConversionNameByWidthAndHeight()`, `Fit::Crop`, `performOnCollections('default')`, `queued()`.

- [ ] **Step 1: Scrivi i test che falliscono**

  - `it('collega i POI con il tipo del package e il ruolo')`: un `Ente`, un `App\Models\EcPoi`
    creato `withoutEvents`, riga in `enteables` con `enteable_type = WmEcPoi::class`, `ruolo = 'operatore'`
    → `$ente->ecPois->pluck('id')` contiene il POI e `->first()->pivot->ruolo === 'operatore'`.
    Una riga con `enteable_type = App\Models\EcPoi::class` **non** compare.
  - `it('esclude gli enti spariti da Drupal')`: due enti, uno con
    `properties = ['forestas' => ['deleted_from_source' => true]]` → `Ente::active()->count() === 1`.
    Un ente con `properties = null` è attivo.
  - `it('registra una conversione per ogni misura')`: `$ente->registerMediaConversions()` poi
    `count($ente->mediaConversions) === count(config('wm-package.services.image.thumbnail_sizes'))`.

- [ ] **Step 2: Lancia i test e verifica che falliscano**

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Ente/EnteModelTest.php`
  Expected: FAIL, metodi `ecPois`, `active` non definiti.

- [ ] **Step 3: Implementa le tre interfacce in `app/Models/Ente.php`**

- [ ] **Step 4: Lancia i test e verifica che passino**

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Ente/EnteModelTest.php`
  Expected: PASS

- [ ] **Step 5: Commit** — `feat(oc:8965): POI collegati, enti attivi e miniature sul modello Ente`

---

### Task 2: HTML filtrato su descrizione e contatti

**Files:**
- Modify: `composer.json`, `composer.lock` (`docker exec php-forestas composer require ezyang/htmlpurifier:^4.19`)
- Create: `app/Support/EnteHtmlSanitizer.php`
- Modify: `app/Models/Ente.php` (mutator)
- Modify: `app/Services/Import/SardegnaSentieriImportService.php:1454-1456` (togli `strip_tags`)
- Modify: `app/Nova/Ente.php:65,84` (pulsanti del `Tiptap`)
- Test: `tests/Feature/Ente/EnteHtmlSanitizerTest.php` (nuovo)

**Interfaces:**
- Produces:
  - `App\Support\EnteHtmlSanitizer::clean(?string $html): ?string` — `null` e stringa vuota dopo il
    filtro → `null`. HTMLPurifier con `HTML.Allowed` = la whitelist dei Global Constraints,
    `URI.AllowedSchemes` = `http`, `https`, `mailto`, `tel`, `Cache.SerializerPath` =
    `storage_path('framework/cache/htmlpurifier')` (creata se manca, come in
    `wm-package/src/Nova/Fields/Concerns/HasEmbeddableRichText.php`). Istanza riusata (static).
  - Mutator su `description` e `contatti` di `Ente` che applica `clean()` a ogni lingua. Deve
    valere per `fill(['description' => ['it' => …]])`, `setTranslation('description', 'it', …)` e
    `setTranslations(...)`: con `spatie/laravel-translatable` un mutator `setDescriptionAttribute`
    riceve il valore della singola lingua. Mutator, non evento `saving`: l'import salva con
    `saveQuietly()`.

- [ ] **Step 1: Scrivi i test che falliscono**

  - `it('tiene la formattazione ammessa')`: `clean('<h4>Titolo</h4><p>Testo <a href="https://x.it">link</a> <em>c</em> <strong>g</strong></p><ul><li>a</li></ul>')`
    restituisce lo stesso HTML.
  - `it('toglie tag e attributi non ammessi')`: `clean('<p style="color:red" onclick="x()">a</p><script>alert(1)</script><iframe src="https://x"></iframe><a href="javascript:alert(1)" target="_blank">b</a>')`
    → `'<p>a</p><a>b</a>'`.
  - `it('restituisce null per testo vuoto')`: `clean(null)`, `clean('')`, `clean('<script></script>')` → `null`.
  - `it('filtra anche le scritture dirette sul modello')`: `$ente->setTranslation('description', 'it', '<p onclick="x">a</p>')`
    poi `save()` e `fresh()` → `getTranslation('description', 'it') === '<p>a</p>'`; stesso controllo
    per `contatti` con `fill()`.

- [ ] **Step 2: Lancia i test e verifica che falliscano**

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Ente/EnteHtmlSanitizerTest.php`
  Expected: FAIL, classe `EnteHtmlSanitizer` non trovata.

- [ ] **Step 3: `composer require`, poi implementa `EnteHtmlSanitizer::clean()` e il mutator**

- [ ] **Step 4: Nell'import togli `strip_tags` dalla descrizione** (resta `trim`), senza chiamare
  il filtro: lo fa il mutator. Aggiorna l'eventuale test di `EnteImportTest` /
  `SardegnaSentieriEnteImportTest` che si aspettava il testo senza HTML.

- [ ] **Step 5: Nova** — `Tiptap::make(...)->buttons(['heading', 'bold', 'italic', 'link', 'bulletList', 'orderedList'])->headingLevels([2, 3, 4, 5, 6])`
  su `description` e `contatti` (verifica i nomi dei metodi nel campo `Tiptap` installato).

- [ ] **Step 6: Lancia i test e verifica che passino**

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Ente tests/Feature/Import --filter=Ente`
  Expected: PASS

- [ ] **Step 7: Commit** — `feat(oc:8965): descrizione e contatti degli enti in HTML filtrato`

---

### Task 3: Token statico e rotte `api/v1/entes`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-nomi-e-registrazione-delle-rotte)

**Files:**
- Create: `app/Http/Middleware/VerifyWpApiToken.php`
- Create: `routes/website.php`
- Modify: `app/Providers/AppServiceProvider.php` (dopo il gruppo SUS, riga ~115)
- Modify: `config/forestas.php` (`'wp_api_token' => env('WP_API_TOKEN')`, con commento sul
  congelamento della configurazione come per `admin_password`)
- Modify: `.env-example`, `.env-deploy` (`WP_API_TOKEN=` vuota, commento su
  `openssl rand -hex 32`)
- Test: `tests/Feature/Entes/WpApiTokenTest.php` (nuovo)

**Interfaces:**
- Produces:
  - `VerifyWpApiToken::handle(Request $request, Closure $next): Response` — copia lo schema di
    `EnsureExportToken`: `abort(403, 'Website API is not configured on this instance.')` se il token
    configurato è vuoto; `abort(401, 'Invalid website token.')` se
    `! hash_equals((string) $configured, (string) $request->bearerToken())`.
  - Gruppo: `Route::name('website.')->middleware(['api', VerifyWpApiToken::class])->prefix('api/v1/entes')->group(base_path('routes/website.php'))`.
  - In `routes/website.php`, da riempire nei Task 4 e 5: `GET entes` → `entes.index` con
    `throttle:60,1`, `GET entes/search` → `entes.search` con `throttle:600,1` (tutto il sito
    passa da un solo client: la ricerca ha un limite più alto). In questo task una rotta `GET ping` →
    `website.ping` che risponde `{"ok":true}`, usata dai test del token.

- [ ] **Step 1: Scrivi i test che falliscono** (su `GET /api/v1/entes/ping`)

  - `it('risponde 401 senza header')`, `it('risponde 401 con token errato')`,
    `it('risponde 403 se il token configurato è vuoto')` (config `''` e header `Bearer ` vuoto;
    anche config `null`),
    `it('risponde 200 con il token giusto')` (`config(['forestas.wp_api_token' => 'secret'])`,
    header `Authorization: Bearer secret`).

- [ ] **Step 2: Verifica che falliscano** — `docker exec php-forestas vendor/bin/pest tests/Feature/Entes/WpApiTokenTest.php` → FAIL (404)

- [ ] **Step 3: Implementa middleware, config, gruppo di rotte e `ping`; aggiorna `.env-example` e `.env-deploy`**

- [ ] **Step 4: Verifica che passino** — stesso comando → PASS

- [ ] **Step 5: Commit** — `feat(oc:8965): token statico per le rotte del sito WordPress`

---

### Task 4: JSON di tutti gli enti

**Files:**
- Create: `app/Http/Controllers/Api/EnteController.php`
- Create: `app/Http/Resources/EnteResource.php`
- Modify: `routes/website.php`
- Test: `tests/Feature/Entes/EnteIndexTest.php` (nuovo)

**Interfaces:**
- Consumes: `Ente::active()`, `Ente::ecPois()` (Task 1); `VerifyWpApiToken` (Task 3).
- Produces:
  - `EnteController::index(): JsonResponse` — `{"generated_at": "<ISO 8601>", "data": [EnteResource…]}`.
    Query: `Ente::active()->with(['media', 'ecTracks' (escluse le tracce marcate), 'ecPois' (esclusi i POI marcati), relazioni della tassonomia dello stato di validazione delle tracce])->orderBy('id')`.
  - `EnteResource::toArray()` — chiavi: `id`, `slug`, `name`, `type` (`tipo_ente?->value`),
    `description`, `contacts` (traduzioni complete), `website` (`{url, title}` o `null` se
    `pagina_web` vuota), `geometry` (GeoJSON `Point` 2D o `null`), `feature_image` (prima media della
    collection `default` per `order_column`, `MediaResource` risolta, o `null`), `image_gallery` (le
    altre, `MediaResource`), `related_url` (`properties.related_url` o `[]`), `created_at`
    (`drupal_created_at` ISO o `null`), `updated_at` (`properties.forestas.updated_at` o `null`),
    `tracks` (`[{id, ref, name, validation_status, roles: [..]}]`, un elemento dell'array per traccia, con i ruoli
    raggruppati, `validation_status` = identifier del termine di `EcTrack::VALIDATION_STATUS_TAXONOMY`
    o `null`), `pois` (`[{id, name, roles}]`).
  - Verifica la convenzione di `feature_image` / galleria contro `ForestasDetailParts::trackCard()`
    (`app/Support/ForestasDetailParts.php:122`, prima media = immagine principale).

- [ ] **Step 1: Scrivi i test che falliscono**

  - `it('restituisce gli enti con la forma concordata')`: ente con nome, descrizione, contatti, pagina
    web, punto, una traccia con ruoli `gestore` e `rilevatore`, un POI `operatore` → `assertJsonStructure`
    con tutte le chiavi sopra; `tracks.0.roles` == `['gestore', 'rilevatore']` (ordinati); `generated_at` presente.
  - `it('esclude gli enti, le tracce e i POI spariti da Drupal')`.
  - `it('non fa una query per ente')`: con 5 enti con tracce e POI, `DB::enableQueryLog()` → numero
    di query uguale a quello con 1 ente.
  - `it('richiede il token')`: senza header → `401`.

- [ ] **Step 2: Verifica che falliscano** — `docker exec php-forestas vendor/bin/pest tests/Feature/Entes/EnteIndexTest.php` → FAIL

- [ ] **Step 3: Implementa controller, resource e rotta `GET entes`**

- [ ] **Step 4: Verifica che passino** — stesso comando → PASS

- [ ] **Step 5: Commit** — `feat(oc:8965): JSON di tutti gli enti per il sito WordPress`

---

### Task 5: Ricerca degli enti su Elasticsearch

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-indice-senza-settings-propri)

**Files:**
- Modify: `app/Models/Ente.php` (Scout)
- Create: `app/Search/EnteSearchIndex.php` (mapping e settings)
- Create: `app/Search/EnteSearchQuery.php` (corpo della query)
- Modify: `app/Providers/AppServiceProvider.php` (`boot()`: mapping)
- Modify: `app/Http/Controllers/Api/EnteController.php` (`search`)
- Modify: `routes/website.php`
- Test: `tests/Feature/Entes/EnteSearchTest.php` (nuovo)

**Interfaces:**
- Consumes: `Ente::active()` (Task 1), `EnteController` (Task 4).
- Produces:
  - `Ente` usa `Laravel\Scout\Searchable`; `searchableAs(): string` → `'entes'`;
    `shouldBeSearchable(): bool` → falso se `deleted_from_source` (stessa condizione di
    `scopeActive`); `toSearchableArray(): array` → `id`, `slug`, `type`, `name` (stringa `it`),
    `description` (stringa `it` senza tag, `strip_tags`), `feature_image` (`{url, thumbnail}` della
    prima media o `null`), `name_translations` (array, non indicizzato). Mai `contatti`.
  - `EnteSearchIndex::mappings(): array` e `EnteSearchIndex::settings(): array` — `name` `text` con
    sottocampo `edge` sull'analizzatore edge n-gram (riprendi `edge_ngram_analyzer` e
    `edge_ngram_filter` da `wm-package/config/wm-elasticsearch.php:56,121-150`), `description`
    `text`, `slug` e `type` `keyword`, `feature_image` e `name_translations` `object` con `enabled: false`.
  - In `AppServiceProvider::boot()`: `config(['elasticsearch.indices.mappings.entes' => EnteSearchIndex::mappings(), 'elasticsearch.indices.settings.entes' => EnteSearchIndex::settings()])`
    — dopo `WmPackageServiceProvider`, che sostituisce `elasticsearch.indices`
    (`wm-package/src/WmPackageServiceProvider.php:607-608`). Commento che lo spiega.
  - `EnteSearchQuery::body(string $text): array` — `bool.must` con `multi_match` su
    `['name^3', 'name.edge^2', 'description']`, `fuzziness: 'AUTO'`, `operator: 'and'`; mai
    `query_string`. `size` 50.
  - `EnteController::search(Request $request): JsonResponse` — `query` obbligatorio, stringa,
    max 200 (`422` altrimenti). Esegue la ricerca con la callback di Matchish
    (`Ente::search($text, fn (Client $client, Search $body) => $client->search(['index' => 'entes', 'body' => EnteSearchQuery::body($text)]))`,
    come `ElasticsearchController.php:104,200`) e restituisce `{"data": [{id, slug, name, type, feature_image}]}`
    dai `_source`, nell'ordine dei risultati. `index_not_found_exception` → `503`
    `{"message":"Indice degli enti assente: lanciare scout:import \"App\\Models\\Ente\"."}`.
  - Rotta `GET entes/search` → `entes.search`.

- [ ] **Step 1: Scrivi i test che falliscono**

  - `it('aggiunge il mapping degli enti senza togliere quello delle tracce')`:
    `config('elasticsearch.indices.mappings.entes')` non nullo e
    `config('elasticsearch.indices.mappings.default')` non nullo.
  - `it('non usa la sintassi Lucene')`: `json_encode(EnteSearchQuery::body('Comune di Oliena (NU'))`
    non contiene `query_string` e contiene `multi_match` con il testo invariato.
  - `it('non indicizza i contatti né gli enti spariti')`: `toSearchableArray()` senza chiave
    `contatti`; `shouldBeSearchable()` falso per un ente marcato.
  - `it('restituisce le card nell\'ordine dei risultati')`: rimpiazza la ricerca con un doppio (estrai
    l'esecuzione in un metodo `EnteController::runSearch(string $text): array` o in una classe
    iniettabile, e fai il bind nel test) che restituisce due `_source` → la risposta ha le due card
    nello stesso ordine.
  - `it('risponde 503 se l\'indice manca')`: il doppio lancia l'eccezione di Elasticsearch per
    `index_not_found_exception` → `503`.
  - `it('accetta caratteri della sintassi Lucene')`: `query=Comune (NU:` → `200`.
  - `it('richiede query')` → `422`; senza token → `401`.

- [ ] **Step 2: Verifica che falliscano** — `docker exec php-forestas vendor/bin/pest tests/Feature/Entes/EnteSearchTest.php` → FAIL

- [ ] **Step 3: Implementa Scout su `Ente`, `EnteSearchIndex`, `EnteSearchQuery`, il mapping in `boot()`, `search()` e la rotta**

- [ ] **Step 4: Verifica che passino** — stesso comando → PASS

- [ ] **Step 5: Verifica a mano in locale (Elasticsearch acceso)**

  Run: `docker exec php-forestas php artisan scout:import "App\Models\Ente"` poi
  `curl -s -H "Authorization: Bearer $TOKEN" "http://localhost:<DOCKER_SERVE_PORT>/api/v1/entes/search?query=tepilora"`
  Expected: la card dell'ente 13 (Oasi Tepilora) prima; `query=tepil` la trova (prefisso);
  `query=Comune%20(NU` risponde 200.

- [ ] **Step 6: Commit** — `feat(oc:8965): ricerca degli enti su Elasticsearch`

---

### Task 6: Import — slug ASCII, `--only=enti`, indice allineato

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6---onlyenti-con---reset-rifiutato)

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php` (`importOrUpdateEnte`, riga 1408 e ~1477)
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (firma riga 80, righe 174-200, `markRemovedEnti` riga 430)
- Modify: `app/Nova/Ente.php` (slug: `->rules('nullable', 'max:255')->creationRules('unique:entes,slug')->updateRules('unique:entes,slug,{{resourceId}}')` e normalizzazione con `Str::slug` al salvataggio; slug vuoto → `Str::slug(name)`)
- Test: `tests/Feature/Import/EnteImportTest.php`, `tests/Feature/Import/ImportSardegnaSentieriEntiCommandTest.php`

**Interfaces:**
- Consumes: Scout su `Ente` (Task 5).
- Produces:
  - Slug in `importOrUpdateEnte`: `Str::slug($node['slug'] ?? '') ?: Str::slug($title)`.
  - Alla fine di `importOrUpdateEnte`, dopo `syncImportedImages`: `$ente->searchable()`.
  - In `markRemovedEnti`, per ogni ente marcato: `$ente->unsearchable()`.
  - `--only` accetta `pois`, `tracks`, `enti`; descrizione dell'opzione aggiornata. Con `enti`:
    `importEnti()` gira, `importPois()` e `importTracks()` no, nessun job di POI o sentieri accodato;
    anomalie e registro catastale non partono (come gli altri `--only`).

- [ ] **Step 1: Scrivi i test che falliscono**

  - `it('normalizza lo slug di Drupal in ASCII')` (fonte finta, come gli ultimi test di
    `EnteImportTest.php`): slug `comune-di-lodè` → `comune-di-lode`; slug assente e titolo
    `Département de la Haute-Corse` → `departement-de-la-haute-corse`.
  - `it('con --only=enti non accoda POI e sentieri')`: `Bus::fake()`, comando con `--only=enti` →
    nessun job di POI/tracce accodato, job degli enti sì.
  - `it('indicizza l\'ente importato e toglie quelli spariti')`: registra un motore Scout finto
    (`app(EngineManager::class)->extend('spy', fn () => $engine)` con `$engine = Mockery::spy(Laravel\Scout\Engines\Engine::class)`,
    `config(['scout.driver' => 'spy'])`). Dopo `importOrUpdateEnte` con fonte finta e un'immagine:
    `$engine->shouldHaveReceived('update')` con una collection che contiene l'ente, e l'ente ha già
    la media quando `update` viene chiamato (verificalo nella closure dell'aspettativa). Dopo
    `markRemovedEnti` su un ente non più nella fonte: `shouldHaveReceived('delete')` con quell'ente.

- [ ] **Step 2: Verifica che falliscano** — `docker exec php-forestas vendor/bin/pest tests/Feature/Import --filter=Ente` → FAIL

- [ ] **Step 3: Implementa slug, `searchable()` / `unsearchable()`, `--only=enti` e lo slug in Nova**

- [ ] **Step 4: Verifica che passino** — stesso comando, poi l'intera cartella `tests/Feature/Import` → PASS

- [ ] **Step 5: Commit** — `feat(oc:8965): slug ASCII, import dei soli enti e indice allineato`

---

### Task 7: Messa in opera e verifica finale

**Files:**
- Modify: `docs/howto/deploy-automatico.md` (fra ciò che resta a mano)

- [ ] **Step 1: Scrivi in `docs/howto/deploy-automatico.md`** i passi del primo deploy di oc:8965, in
  quest'ordine: impostare `WP_API_TOKEN` (`openssl rand -hex 32`) nel `.env` del server
  **prima** del deploy, perché `optimize` congela la configurazione, e passarlo a chi configura
  WordPress; dopo il deploy `php artisan scout:import "App\Models\Ente"`,
  `php artisan media-library:regenerate --only-missing` limitato agli enti, e
  `php artisan sardegnasentieri:import --only=enti --force` per riscrivere le descrizioni con
  l'HTML. Nota su UAT: il reset notturno riscrive comunque gli enti ogni notte.

- [ ] **Step 2: Suite e analisi statica**

  Run: `docker exec php-forestas vendor/bin/pest` (dopo il controllo di `.claude/rules/test.md`),
  `docker exec php-forestas vendor/bin/phpstan analyse`, `docker exec php-forestas composer format`
  Expected: test verdi, nessun errore PHPStan nuovo sui file toccati.

- [ ] **Step 3: Verifica a mano in locale** — `curl` su `api/v1/entes` con e senza token:
  `401` senza; con token, l'ente 13 ha 2 POI, i 4 sentieri con ruolo `gestore` e
  `generated_at`; l'ente 3 (GAL BMG) ha la galleria con `sizes` dopo `media-library:regenerate`.

- [ ] **Step 4: Commit** — `docs(oc:8965): passi a mano del primo deploy dell'API degli enti`
