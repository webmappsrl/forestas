> Ticket: oc:9001

# Aree geografiche nella fase di import — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `sardegnasentieri:import` porta nello shard le 39 aree geografiche di Geohub come Taxonomy
Where, un Layer per area (solo percorsi) e la FeatureCollection `aree_geografiche` in `generated`,
con i colori degli overlay corretti.

**Architecture:** due repo. Nel **wm-package** (si mergia per primo): i colori a 8 cifre degli
overlay e la logica di «Crea Layer» spostata in `LayerService`. In **forestas**: il service delle
aree diventa parametrico sulla sorgente, un service nuovo fa il passo delle aree (Taxonomy Where,
Layer, FeatureCollection), il comando lo chiama a ogni giro e con `--only=aree`.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Pest, Nova 5.

**Spec:** [overview.md](overview.md) e
`wm-package/docs/features/9001-aree-geografiche-nell-import/overview.md`.

## Global Constraints

- Branch `feature/oc-9001-aree-geografiche-nell-import` in forestas e nel wm-package.
- **Nessun commit, push o branch da parte di chi esegue:** i passi «Commit» sono testi da proporre
  al dev, che decide.
- Identificatori in inglese; commenti, log, messaggi e documentazione in italiano.
- Fonte delle aree geografiche: `properties.source = 'geohub_conf_32_geographic_areas'`. Le
  catastali restano `geohub_conf_32`, identiche.
- Geojson: `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/AreeGeograficheConLayers.geojson`;
  config: `https://wmfe.s3.eu-central-1.amazonaws.com/geohub/conf/32.json` (già nel service).
- App di import: `SardegnaSentieriImportService::IMPORT_APP_ID` (1).
- FeatureCollection: `name = 'aree_geografiche'`, `app_id` = app di import.
- Log: `Log::channel('import')` (`storage/logs/import-AAAA-MM-GG.log`).
- **Test forestas:** prima di lanciarli verificare l'isolamento (`.claude/rules/test.md`):
  `phpunit.xml` → `DB_DATABASE=forestas_testing`, il database esiste. `.env.testing` oggi manca
  (servirebbe solo ai test con JWT, che qui non si toccano). I subagent non lanciano test senza
  questa verifica.
- **Test del package:** si scrivono in `wm-package/tests/` con `uses(Tests\TestCase::class,
  DatabaseTransactions::class)` e si lanciano **da forestas**:
  `docker exec php-forestas vendor/bin/pest wm-package/tests/<file>`. Il package non ha il suo
  `vendor` installato, quindi i test con `Wm\WmPackage\Tests\TestCase` qui non girano.
- Dopo aver modificato un job: `docker restart horizon-forestas`.
- Ordine dei merge: PR del wm-package verso `develop` prima; poi in forestas il puntatore del
  submodule in un commit a sé, nella stessa PR del ticket.

## Review Focus

1. **Geohub non risponde o risponde con errore** durante l'import notturno: l'import di tracce e
   POI deve proseguire. Test in Task 5 («l'import prosegue se il passo delle aree fallisce»).
2. **Secondo giro dell'import**: nessun Layer e nessuna Taxonomy Where in più. Test in Task 4
   («un secondo giro non crea doppioni»).
3. **`--reset --only=aree`**: deve essere rifiutato prima del troncamento, altrimenti tracce e POI
   vengono cancellati e non reimportati. Test in Task 5.
4. **Colore a 7 cifre o con spazi** (`#a61c006`, `#a61c0066 `): deve ricadere sul colore
   principale dell'app, mai far fallire la generazione del `config.json`. Test in Task 1.
5. **`--only=aree` con la copia Drupal spenta** (in locale lo è di default): il passo non dipende
   da Drupal e deve girare lo stesso. Test in Task 5.

---

## wm-package

### Task 1: colori degli overlay con la trasparenza

**Files:**
- Modify: `wm-package/src/helpers.php` (accanto a `sanitizeHexColor()`)
- Modify: `wm-package/src/Services/Models/App/AppConfigService.php:660-661`
- Test: `wm-package/tests/Feature/AppConfigServiceFeatureCollectionAlphaColorTest.php` (nuovo)

**Interfaces:**
- Produces: `sanitizeHexColorWithAlpha(mixed $value, string $fallback): string` — restituisce
  `$value` se è esattamente `#RRGGBB` o `#RRGGBBAA`, altrimenti `$fallback`.
  `sanitizeHexColor()` resta identica (la usano anche tema e story share).

- [x] **Step 1: test che falliscono**, con un'App con `primary_color = '#abcdef'` e una
  FeatureCollection in `config_overlays.OVERLAYS` (stesso schema di
  `AppConfigServiceMapFeatureCollectionColorTest.php:80-118`):
  - `fill_color = '#a61c0066'`, `stroke_color = '#a61c00'` → `fillColor === 'rgba(166, 28, 0, 0.4)'`,
    `strokeColor === 'rgba(166, 28, 0, 1)'`;
  - `fill_color = '#a61c006'` e `fill_color = '#a61c0066 '` → `fillColor === hexToRgba('#abcdef')`,
    nessuna eccezione;
  - `sanitizeHexColor('#a61c0066', '#000000') === '#000000'` (la funzione vecchia non cambia).
- [x] **Step 2:** `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/AppConfigServiceFeatureCollectionAlphaColorTest.php` → FAIL sul primo caso.
- [x] **Step 3:** aggiungere `sanitizeHexColorWithAlpha()` (regex `/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/`,
  docblock che spiega perché è separata da `sanitizeHexColor()`: `hexToRgba()` accetta 6 e 8 cifre,
  la story share no) e usarla nelle due righe di `config_section_map()`.
- [x] **Step 4:** stesso comando → PASS. Il test esistente
  `AppConfigServiceMapFeatureCollectionColorTest.php` usa il TestCase del package e da qui non
  gira: il nuovo test ripete i suoi due casi di ripiego (`'#fff'` e `'red'` → `hexToRgba('#abcdef')`).
- [ ] **Step 5: commit proposto:** `fix(oc:9001): i colori degli overlay con la trasparenza non escono più neri`

### Task 2: creazione del Layer da una Taxonomy Where in `LayerService`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-eventi-spenti-solo-sul-collegamento-allarea)

**Files:**
- Modify: `wm-package/src/Services/Models/LayerService.php`
- Modify: `wm-package/src/Nova/Actions/CreateLayerFromTaxonomyWhere.php` (il ciclo `handle()` e
  `syncFeatureImageFromTaxonomy()` passano nel service; `resolveLayerName()` pure)
- Test: `wm-package/tests/Feature/LayerServiceCreateFromTaxonomyWhereTest.php` (nuovo)

**Interfaces:**
- Produces: `LayerService::createLayerFromTaxonomyWhere(TaxonomyWhere $taxonomyWhere, App $app): Layer`
  — crea il Layer (`app_id`, `user_id = $app->user_id`), lo collega alla Taxonomy Where
  (`taxonomyWheres()->attach()`), copia:
  - `name` ← `$taxonomyWhere->getTranslations('name')` (tutte le lingue; ripiego sulla logica di
    `resolveLayerName()` se vuoto);
  - `properties->description` e `properties->subtitle` ← `properties['description']` e
    `properties['subtitle']` della Taxonomy Where, se presenti (array per lingua, come salvati);
  - immagine ← `properties['feature_image']`, come oggi (media `default`, `source_feature_image_url`).
  Gli eventi li decide chi chiama: il metodo non li spegne.
- Produces: l'action chiama il metodo per ogni modello; messaggi e controlli sull'App invariati.

- [x] **Step 1: test che falliscono** (`Http::fake()` per l'immagine, `Storage::fake()` dei dischi media):
  - una Taxonomy Where con `name = ['it' => 'Goceano', 'en' => 'Goceano']` e
    `properties = ['description' => ['it' => 'Testo'], 'subtitle' => ['it' => 'Sotto']]` →
    `$layer->getTranslations('name') === ['it' => 'Goceano', 'en' => 'Goceano']`,
    `$layer->properties['description']['it'] === 'Testo'`, `$layer->properties['subtitle']['it'] === 'Sotto'`,
    `$layer->taxonomyWheres->pluck('id')` contiene l'area, `$layer->app_id === $app->id`;
  - chiamato dentro `TaxonomyWhereable::withoutEvents(...)` → il layer non ha tracce
    (`$layer->ecTracks()->count() === 0`) anche con una traccia dentro il poligono.
- [x] **Step 2:** `docker exec php-forestas vendor/bin/pest wm-package/tests/Feature/LayerServiceCreateFromTaxonomyWhereTest.php` → FAIL (metodo inesistente).
- [x] **Step 3:** implementare il metodo e far usare il metodo all'action.
- [x] **Step 4:** stesso comando → PASS.
- [ ] **Step 5: commit proposto:** `feat(oc:9001): la creazione del Layer da una Taxonomy Where passa in LayerService, con traduzioni e descrizione`

---

## forestas

### Task 3: service delle aree parametrico sulla sorgente, con log

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-una-transazione-per-area)

**Files:**
- Modify: `app/Services/Import/TaxonomyWhereLayersImportService.php`
- Test: `tests/Feature/Import/TaxonomyWhereLayersImportServiceTest.php`

**Interfaces:**
- Produces: `public const GEOGRAPHIC_AREAS_SOURCE_KEY = 'geohub_conf_32_geographic_areas';`
  (`SOURCE_KEY = 'geohub_conf_32'` invariata: la usano anche `EcTrackCadastralAreaFilter` e
  l'action).
- Produces: `import(?int $appId = null, string $sourceKey = self::SOURCE_KEY): array` — stesso
  array di contatori di oggi; l'URL del geojson si sceglie da una mappa privata
  `sourceKey => url`; una chiave sconosciuta lancia `InvalidArgumentException`. `findExisting()`
  cerca con la `$sourceKey` ricevuta.
- Produces: per ogni area finita nel `catch`, `Log::channel('import')->error('Area non importata',
  ['source', 'layer_id', 'name', 'error'])`; a fine metodo `Log::channel('import')->info('Import
  delle aree completato', ['source' => …] + $counters)`.

- [x] **Step 1: test che falliscono** (stesso `Http::fake` del test esistente, URL della sorgente giusta):
  - `import(null, GEOGRAPHIC_AREAS_SOURCE_KEY)` → `created === 1`, la Taxonomy Where ha
    `properties['source'] === 'geohub_conf_32_geographic_areas'`;
  - con una catastale già presente (`source = geohub_conf_32`, `external_layer_id = '101'`), l'import
    delle geografiche con `layer_id = 101` ne crea una nuova e la catastale resta com'era (nome e
    `updated_at` invariati);
  - `import(null, 'sconosciuta')` lancia `InvalidArgumentException`;
  - un'area con geometria rifiutata da PostGIS (es. `{"type":"Polygon","coordinates":[[[0,0]]]}`)
    → `errors === 1` e `Log::spy()` ha ricevuto `channel('import')` con un `error` che contiene il
    `layer_id`.
- [x] **Step 2:** verificare l'isolamento, poi `docker exec php-forestas vendor/bin/pest tests/Feature/Import/TaxonomyWhereLayersImportServiceTest.php` → FAIL.
- [x] **Step 3:** implementare.
- [x] **Step 4:** stesso comando → PASS (compreso il test esistente delle catastali); poi
  `docker exec php-forestas vendor/bin/pest tests/Feature/EcTrack/EcTrackFiltersTest.php` → PASS.
- [ ] **Step 5: commit proposto:** `feat(oc:9001): il service delle aree importa anche le aree geografiche, e registra le aree non importate`

### Task 4: il passo delle aree geografiche

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-featurecollection-salvata-senza-eventi)

**Files:**
- Create: `app/Services/Import/GeographicAreasImportService.php`
- Test: `tests/Feature/Import/GeographicAreasImportServiceTest.php`

**Interfaces:**
- Consumes: `TaxonomyWhereLayersImportService::import()` e `GEOGRAPHIC_AREAS_SOURCE_KEY` (Task 3);
  `LayerService::createLayerFromTaxonomyWhere()` (Task 2).
- Produces: `GeographicAreasImportService::run(): array{areas: array<string,int>, layers_created: int}`:
  1. `import(IMPORT_APP_ID, GEOGRAPHIC_AREAS_SOURCE_KEY)`;
  2. per ogni Taxonomy Where con quella `source` che non ha un Layer collegato: crea il Layer dentro
     `TaxonomyWhereable::withoutEvents(...)`, poi `$layer->setPoiMode('manual')`;
  3. FeatureCollection: `FeatureCollection::firstOrCreate(['app_id' => IMPORT_APP_ID, 'name' =>
     'aree_geografiche'], [...])` con, se nasce, i valori di Geohub: `label` it «Aree geografiche» /
     en «Geographical areas», `fill_color '#a61c0066'`, `stroke_color '#a61c00'`, `stroke_width 2`,
     `enabled`, `clickable`, `mode 'generated'`;
  4. `layers()->syncWithoutDetaching()` con i layer di tutte le aree della sorgente;
  5. se la FeatureCollection non è `generated` la porta a `generated` con `save()` (l'observer del
     modello accoda generazione e `config.json`); se lo era già e sono nati layer, accoda
     `GenerateFeatureCollectionJob::dispatch($fc->id)`.

- [x] **Step 1: test che falliscono** (`Http::fake` di geojson con 2 aree e di `32.json`;
  `Bus::fake()`; `config(['cache.stores.redis.driver' => 'array'])` nel `beforeEach`, perché
  `UpdateAppConfigJob` è `ShouldBeUnique` su Redis):
  - `run()` → `layers_created === 2`; ogni Layer ha l'area collegata, `configuration['poi_mode'] === 'manual'`,
    nessuna riga in `layerables` (eventi spenti anche con una traccia dentro un poligono);
  - la FeatureCollection `aree_geografiche` esiste, `mode === 'generated'`, ha i 2 layer;
  - una FeatureCollection `aree_geografiche` già presente in `upload` passa a `generated` e tiene
    `fill_color` e `label` che aveva;
  - **un secondo giro non crea doppioni**: `layers_created === 0`, `Layer::count()` e
    `TaxonomyWhere::count()` invariati;
  - i layer delle catastali già presenti (area `geohub_conf_32` con il suo layer) non vengono toccati.
- [x] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import/GeographicAreasImportServiceTest.php` → FAIL.
- [x] **Step 3:** implementare.
- [x] **Step 4:** stesso comando → PASS.
- [ ] **Step 5: commit proposto:** `feat(oc:9001): il passo delle aree geografiche crea aree, layer e overlay`

### Task 5: il passo nel comando di import e `--only=aree`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-nessun-test-parla-con-la-rete) e [notes.md](notes.md#task-5-reindex-dopo---onlyaree)

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (firma `--only`, validazione
  righe 102-114, giro normale dopo `configure-layers`, ramo `$jobs === []`)
- Test: `tests/Feature/Import/ImportResetGuardsTest.php` e
  `tests/Feature/Import/ImportGeographicAreasCommandTest.php` (nuovo)

**Interfaces:**
- Consumes: `GeographicAreasImportService::run()` (Task 4); `RecalculateSardegnaSentieriLayersJob`
  (senza argomenti, si mette da solo sulla coda dell'import).
- Produces:
  - `--only` accetta `aree` (descrizione dell'opzione aggiornata); messaggio d'errore
    «usa pois, tracks, enti o aree»;
  - `--reset --only=aree` rifiutato come `--only=enti`, prima di backup e troncamento;
  - `--only=aree`: subito dopo la validazione, **prima** di lock, prerequisiti e raggiungibilità
    della copia Drupal, esegue il passo, accoda `RecalculateSardegnaSentieriLayersJob`, scrive il
    riepilogo e ritorna `SUCCESS`;
  - giro normale: il passo gira dopo `configure-layers` e prima di `importAll()`, dentro un
    `try/catch (\Throwable)` che scrive `Log::channel('import')->error(...)` e un `warn` e
    **prosegue**; se il passo ha creato layer e `$jobs === []`, accoda
    `RecalculateSardegnaSentieriLayersJob` (con i job, ci pensa già la catena di fine batch).

- [x] **Step 1: test che falliscono** (`Bus::fake()`; il service del Task 4 sostituito nel container
  con un mock):
  - `--reset --only=aree` → exit `FAILURE`, `resetData()` mai chiamato (stesso schema del test di
    `--only=enti` in `ImportResetGuardsTest`);
  - `--only=aree` con la copia Drupal non raggiungibile → exit `SUCCESS`, `run()` chiamato una volta,
    `RecalculateSardegnaSentieriLayersJob` accodato;
  - **l'import prosegue se il passo delle aree fallisce**: il mock lancia `RuntimeException` →
    il comando non esce con l'eccezione e accoda comunque i job di tracce e POI;
  - `--only=foo` → messaggio che elenca anche `aree`.
- [x] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import/ImportResetGuardsTest.php tests/Feature/Import/ImportGeographicAreasCommandTest.php` → FAIL.
- [x] **Step 3:** implementare.
- [x] **Step 4:** stesso comando → PASS; poi tutta la cartella
  `docker exec php-forestas vendor/bin/pest tests/Feature/Import` → PASS.
- [x] **Step 5:** `docker exec php-forestas vendor/bin/phpstan analyse` → nessun errore nuovo.
- [ ] **Step 6: commit proposto:** `feat(oc:9001): l'import porta le aree geografiche a ogni giro, e --only=aree le porta da sole`

### Task 6: documentazione, puntatore e verifica su dev e UAT

**Files:**
- Create o modify: `docs/knowledge/` — la pagina sulle aree (vedi `update-context` di wm-plan),
  con la procedura per tornare indietro dell'overview e la nota «una FeatureCollection creata dal
  passo su un ambiente nuovo va aggiunta a mano agli overlay dell'App (`config_overlays`)»
- Modify: `.claude/rules/import-sardegna-sentieri.md` — riga sul passo delle aree e su `--only=aree`
- Modify: `wm-package` (puntatore del submodule, commit a sé, dopo il merge della PR del package)

- [x] **Step 1:** in locale `docker exec php-forestas php artisan sardegnasentieri:import --only=aree`,
  poi `docker restart horizon-forestas` se servono i job; verificare: 39 Taxonomy Where con
  `source = geohub_conf_32_geographic_areas`, 39 Layer con `poi_mode = manual`, FeatureCollection
  `aree_geografiche` in `generated` con 39 layer, e nel `config.json` gli overlay con
  `fillColor` `rgba(166, 28, 0, 0.4)`.
- [ ] **Step 2:** dopo il deploy su dev, lo stesso comando sul dev; nella webapp del dev il click
  su un'area geografica (es. Goceano) apre il layer con descrizione e percorsi; gli overlay non
  sono neri.
- [ ] **Step 3:** lo stesso su UAT, insieme a oc:9020 (vedi rischio «Comune» nell'overview).
- [ ] **Step 4: commit proposti:** `docs(oc:9001): …` e, a sé, `chore(oc:9001): puntatore di wm-package con colori e Crea Layer`.
