> Ticket: oc:8827

# Tracce assegnate ai layer giusti a ogni import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a ogni import da Sardegna Sentieri, reset notturno compreso, ogni traccia finisce nei layer
giusti (Sentieri, Itinerari, Cammini di Sardegna, Sentieri accessibili, aree catastali), e l'app lo
vede.

**Architecture:** l'import scrive sulle tracce anche l'attività dei cammini e lo fa senza eventi sul
pivot; il reset conserva le attività e i loro legami con i layer; un job nella catena di fine batch
ricalcola i layer con il `LayerService` del package, prima del reindex; un comando idempotente
associa le attività ai 4 layer di tipologia.

**Tech Stack:** Laravel 12, PHP 8.4 (container `php-forestas`), PostgreSQL + PostGIS, Pest, wm-package
(non modificato).

**Spec:** [overview.md](overview.md)

## Global Constraints

- Tutto il codice in forestas; **nessuna modifica a `wm-package/`**
- Commenti, documentazione e messaggi di commit in italiano; termini tecnici in inglese
- **Nessun `git add`/`commit`/`push`/`checkout`**: i commit sotto sono istruzioni per il dev
- **Prima di lanciare qualsiasi test** verificare l'isolamento (`.claude/rules/test.md`): esiste
  `.env.testing` con `DB_DATABASE=forestas_testing`, ed esiste il database `forestas_testing`. Se
  non è verificabile, non lanciare e chiedere al dev
- Test: `docker exec php-forestas vendor/bin/pest --filter='<nome>'`
- Dopo aver modificato un job: `docker restart horizon-forestas` prima di verificare a mano
- Nessun id numerico di attività nel codice: si risolvono per `identifier`
- Identifier della nuova attività: `sardegnasentieri:cammino`, nome `Cammini di Sardegna`; termine
  Drupal `4728` del campo `field_tipologia_itinerario`
- Mappa layer→attività: `Sentieri` → `sardegnasentieri:type:sentiero`, `Itinerari` →
  `sardegnasentieri:type:itinerario`, `Cammini di Sardegna` → `sardegnasentieri:cammino`,
  `Sentieri Accessibili` → `accessible-trails` (nomi italiani esatti come su UAT)
- Su UAT solo letture (`ssh uat.forestas`, container `php-forestasuat`)

## Review Focus

1. **Morph type in due forme** — le righe pivot possono avere `App\Models\EcTrack` o
   `Wm\WmPackage\Models\EcTrack` (idem per EcPoi): il reset deve cancellarle entrambe e **mai** le
   righe con `…\Layer`. Test nel Task 2.
2. **Layer automatico senza filtro** — non deve essere svuotato dal job. Test nel Task 3.
3. **Hourly import senza cambi** — il ricalcolo rilanciato non cambia `layerables` né accoda job per
   riga. Test di idempotenza nel Task 3.
4. **Traccia che perde il termine 4728 in Drupal** — al reimport perde l'attività del cammino. Test
   nel Task 1.
5. **Comando lanciato prima del primo import** (attività dei cammini assente) — fallisce in modo
   esplicito senza toccare nulla. Test nel Task 5.

---

### Task 0: Verifica dell'isolamento dei test

- [ ] **Step 1:** `test -f .env.testing && grep '^DB_DATABASE=' .env.testing` → atteso
      `DB_DATABASE=forestas_testing`; `grep DB_DATABASE phpunit.xml` → atteso `forestas_testing`
- [ ] **Step 2:** `docker exec postgres-forestas psql -U <utente del .env> -lqt | cut -d'|' -f1 | grep -w forestas_testing`
      → atteso una riga. Se uno dei due controlli fallisce: fermarsi e chiedere al dev

### Task 1: L'import scrive l'attività dei cammini, senza eventi sul pivot

**Files:**
- Modify: `app/Dto/Api/ApiTaxonomiesData.php`
- Modify: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php:111-117` (`getTrackDetail()`)
- Modify: `app/Services/Import/SardegnaSentieriImportService.php:449-451, 699-726`
- Test: `tests/Feature/Import/SardegnaSentieriImportServiceTest.php`

**Interfaces:**
- Produces: `ApiTaxonomiesData::$tipologia_itinerari` (`list<string>`);
  `SardegnaSentieriImportService::CAMMINO_ACTIVITY_IDENTIFIER = 'sardegnasentieri:cammino'`,
  `SardegnaSentieriImportService::CAMMINI_TERM_ID = '4728'` (costanti pubbliche, usate dal Task 5)

- [ ] **Step 1: test che falliscono**, con `minimalTrackFeature()` del file:
  - `it('scrive l attività dei cammini sugli itinerari con il termine Cammini di Sardegna (oc:8827)')`:
    risposta `type = itinerario`, `taxonomies.tipologia_itinerari = ['4728', '4731']` → la traccia ha
    le attività con identifier `sardegnasentieri:type:itinerario` e `sardegnasentieri:cammino`, e
    nessun'altra derivata da `tipologia_itinerari`
  - `it('toglie l attività dei cammini quando il termine sparisce (oc:8827)')`: primo import con
    `['4728']`, secondo con `[]` → resta solo `sardegnasentieri:type:itinerario`
  - `it('non accoda job dei layer quando l import scrive le attività (oc:8827)')`: `Bus::fake()`, un
    `Layer` dell'app con l'attività `sardegnasentieri:type:sentiero` associata, import di un
    sentiero → `Bus::assertNotDispatched(SyncAutoLayerAfterTrackTaxonomyChangeJob::class)`
- [ ] **Step 2:** lancia i tre test → FAIL (proprietà `tipologia_itinerari` assente, job accodato)
- [ ] **Step 3: implementa**
  - `ApiTaxonomiesData`: nuovo parametro `tipologia_itinerari` letto in `fromArray()` come gli altri
  - `getTrackDetail()`: in `taxonomies` aggiungi
    `'tipologia_itinerari' => $this->references($record, 'field_tipologia_itinerario')`
  - Service: sostituisci `syncTrackTaxonomies()` e `syncTrackType()` con
    `private function syncTrackActivities(EcTrack $ecTrack, ApiTrackResponse $response): void`, che
    raccoglie gli id di: termini `tipologia_sentieri` (come oggi), attività del tipo (come oggi),
    `resolveActivityByIdentifier(self::CAMMINO_ACTIVITY_IDENTIFIER, 'Cammini di Sardegna')` se
    `CAMMINI_TERM_ID` è in `tipologia_itinerari`; poi un solo `sync()` dentro
    `TaxonomyActivityable::withoutEvents(fn () => …)`. Commento: perché senza eventi (i layer li
    ricalcola il job di fine batch, oc:8827). Nota: `withoutEvents` spegne il dispatcher di tutti i
    modelli per la durata della closure: dentro ci va solo il `sync()`
- [ ] **Step 4:** rilancia i tre test e l'intero file → PASS
- [ ] **Step 5 (dev):** `fix(oc:8827): attività dei cammini scritta dall'import senza eventi sul pivot`

### Task 2: Il reset conserva attività e legami dei layer

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`resetData()`)
- Test: `tests/Feature/Import/ImportResetNormalizeTest.php`

- [ ] **Step 1: test che falliscono** (stile di `it('azzera anche le domande di iscrizione')`,
      `Bus::fake()` e `Artisan::call('sardegnasentieri:import', ['--reset' => true])`):
  - `it('il reset conserva le attività e i loro legami con i layer (oc:8827)')`: attività
    `sardegnasentieri:type:sentiero` con id X legata a un layer → dopo il reset esiste con lo stesso
    id X ed è ancora legata al layer
  - `it('il reset cancella i legami di tracce e POI con attività e layer (oc:8827)')`: righe in
    `taxonomy_activityables` e `layerables` con type `App\Models\EcTrack`,
    `Wm\WmPackage\Models\EcTrack`, `App\Models\EcPoi`, `Wm\WmPackage\Models\EcPoi` e
    `Wm\WmPackage\Models\Layer` → restano solo quelle `…\Layer`
- [ ] **Step 2:** lancia → FAIL
- [ ] **Step 3: implementa** in `resetData()`: togli il `TRUNCATE taxonomy_activities`; aggiungi due
      `DELETE` (query builder) su `taxonomy_activityables.taxonomy_activityable_type` e
      `layerables.layerable_type` con `whereIn` sulle 4 classi di traccia e POI elencate sopra.
      Commento nello stile delle righe vicine: perché le attività non si troncano più (i legami dei
      layer devono sopravvivere alla notte) e perché i pivot vanno cancellati a mano (nessuna FK,
      `RESTART IDENTITY`)
- [ ] **Step 4:** rilancia il file → PASS
- [ ] **Step 5 (dev):** `fix(oc:8827): il reset conserva le attività e i legami dei layer`

### Task 3: Job che ricalcola i layer

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-ricalcolo-condizionato-e-protetto)

**Files:**
- Create: `app/Jobs/Import/RecalculateSardegnaSentieriLayersJob.php`
- Test: `tests/Feature/Import/RecalculateSardegnaSentieriLayersJobTest.php`

**Interfaces:**
- Produces: `RecalculateSardegnaSentieriLayersJob` (`ShouldQueue`), costruttore senza argomenti,
  `queue` = `ImportSardegnaSentieriTrackJob::QUEUE`;
  `handle(LayerService $layerService, PBFGeneratorService $pbfGeneratorService): void`

- [ ] **Step 1: test che falliscono** (tracce con geometria reale e attività create nel test; il job
      eseguito con `dispatchSync`; `Bus::fake([GenerateOptimizedPBFChainJob::class, UpdateLayerGeometryJob::class, SyncAutoLayerAfterTrackTaxonomyChangeJob::class])`):
  - `it('assegna a un layer le tracce con la sua attività e toglie le altre (oc:8827)')`: layer
    Sentieri con `sardegnasentieri:type:sentiero`, 2 sentieri + 1 itinerario, più una riga
    `layerables` vecchia verso l'itinerario → il layer ha esattamente i 2 sentieri
  - `it('aggiorna properties.layers delle tracce prima di finire (oc:8827)')`: dopo il job
    `properties['layers']` dei 2 sentieri contiene l'id del layer, quello dell'itinerario no
  - `it('non svuota un layer automatico senza attività né aree (oc:8827)')`: layer senza filtro con
    una riga in `layerables` → la riga resta
  - `it('lascia vuoti i POI dei layer di tipologia (oc:8827)')`: layer con attività e una riga POI
    in `layerables` → nessuna riga POI
  - `it('accoda PBF e geometria una volta per layer, non per riga (oc:8827)')`: un layer con 2
    tracce nuove → `GenerateOptimizedPBFChainJob` e `UpdateLayerGeometryJob` accodati 1 volta
    ciascuno; `SyncAutoLayerAfterTrackTaxonomyChangeJob` mai
  - `it('rilanciato non cambia nulla (oc:8827)')`: due esecuzioni → stesse righe `layerables` con
    gli stessi id
- [ ] **Step 2:** lancia → FAIL (classe assente)
- [ ] **Step 3: implementa**: layer dell'app `SardegnaSentieriImportService::IMPORT_APP_ID`
      (`app_id` o `associatedApps`) con `isAutoTrackMode()` e almeno un'attività o una
      `taxonomyWheres`; per ognuno, dentro `Layerable::withoutEvents()`:
      `assignTracksByTaxonomy()` e `assignPoisByTaxonomy()`; poi, fuori, in modo sincrono
      `updateLayersPropertyOnLayeredFeature($layer, $classe)` per la classe traccia
      (`config('wm-package.ec_track_model')`) e per EcPoi, quindi
      `updateLayerGeometryWithJob($layer)` e `$pbfGeneratorService->regeneratePbfsForLayer($layer)`.
      Docblock: cosa fa, perché senza eventi, perché sta prima del reindex (oc:8827)
- [ ] **Step 4:** rilancia → PASS
- [ ] **Step 5 (dev):** `fix(oc:8827): job di ricalcolo dei layer a fine import`

### Task 4: Il job entra nella catena di fine batch

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (callback `finally`, `Bus::chain`)
- Test: `tests/Feature/Import/ImportRegistroCatastaleChainTest.php` (`assertCatenaAree()`)

**Interfaces:**
- Consumes: `RecalculateSardegnaSentieriLayersJob` (Task 3)

- [ ] **Step 1:** in `assertCatenaAree()` la catena attesa diventa `SyncTaxonomyWhereJob`,
      `RecalculateSardegnaSentieriLayersJob`, `RegenerateTaxonomyWhereOutputsJob`; il controllo su
      `chained[0]->appId` passa a `chained[1]`
- [ ] **Step 2:** lancia il file → FAIL
- [ ] **Step 3:** inserisci `new RecalculateSardegnaSentieriLayersJob` fra i due job della catena;
      aggiorna il commento sopra (`Sync aree catastali, layer e reindex`) e la riga di log
- [ ] **Step 4:** rilancia il file e `ImportResetNormalizeTest.php` → PASS
- [ ] **Step 5 (dev):** `fix(oc:8827): ricalcolo dei layer fra sync delle aree e reindex`

### Task 5: Comando di configurazione dei layer di tipologia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-messaggio-del-comando)

**Files:**
- Create: `app/Console/Commands/ConfigureSardegnaSentieriLayersCommand.php`
- Test: `tests/Feature/Import/ConfigureSardegnaSentieriLayersCommandTest.php`

**Interfaces:**
- Produces: signature `sardegnasentieri:configure-layers`; costante pubblica
  `LAYER_ACTIVITIES = ['Sentieri' => 'sardegnasentieri:type:sentiero', 'Itinerari' => 'sardegnasentieri:type:itinerario', 'Cammini di Sardegna' => SardegnaSentieriImportService::CAMMINO_ACTIVITY_IDENTIFIER, 'Sentieri Accessibili' => 'accessible-trails']`
- Consumes: `RecalculateSardegnaSentieriLayersJob` (Task 3), `RegenerateTaxonomyWhereOutputsJob` del
  package

- [ ] **Step 1: test che falliscono**:
  - `it('associa a ogni layer di tipologia solo la sua attività (oc:8827)')`: 4 layer e 4 attività,
    il layer Sentieri con anche un'attività estranea → exit 0, ogni layer ha esattamente la sua
  - `it('rilanciato non cambia nulla (oc:8827)')`: due esecuzioni → stesse righe
    `taxonomy_activityables` con gli stessi id
  - `it('fallisce senza toccare nulla se manca un attività (oc:8827)')`: manca
    `sardegnasentieri:cammino` → exit 1, messaggio che chiede di lanciare prima l'import, nessun
    legame scritto
  - `it('fallisce se un layer manca o il nome è ambiguo (oc:8827)')`: due layer «Sentieri» → exit 1
  - `it('accoda ricalcolo e reindex senza passare dagli observer (oc:8827)')`: `Bus::fake()` →
    catena `RecalculateSardegnaSentieriLayersJob` → `RegenerateTaxonomyWhereOutputsJob` accodata
    sulla coda dell'import; nessun job accodato dagli observer
- [ ] **Step 2:** lancia → FAIL
- [ ] **Step 3: implementa**: risolvi prima tutti i layer (per `name` italiano esatto, fra i layer
      dell'app di import) e tutte le attività (per `identifier`); se qualcosa manca o è ambiguo,
      errore e `FAILURE` prima di scrivere. Poi `taxonomyActivities()->sync([$id])` per ogni layer
      dentro `TaxonomyActivityable::withoutEvents()` (evita ricalcoli sincroni e scritture su S3
      dell'observer del package), stampa cosa è cambiato, e accoda la catena come la callback
      `finally`
- [ ] **Step 4:** rilancia → PASS
- [ ] **Step 5 (dev):** `fix(oc:8827): comando di configurazione dei layer di tipologia`

### Task 6: Verifica completa

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-verifica-sui-dati-reali)

- [ ] **Step 1:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import tests/Feature/EcTrack`
      → PASS (isolamento già verificato nel Task 0)
- [ ] **Step 2:** `vendor/bin/phpstan analyse` → nessun errore nuovo sui file toccati
- [ ] **Step 3:** `composer format` solo sui file toccati; `git status` per scartare file estranei
- [ ] **Step 4 (se la copia Drupal locale è accesa — `docs/howto/copia-database-drupal.md`):**
      `docker restart horizon-forestas`, import locale senza `--reset` (il reset in locale si ferma
      sulla guardia del backup), poi `sardegnasentieri:configure-layers`, poi conteggi dei 4 layer in
      `layerables`. Atteso, vicino ai numeri di UAT del 30/10: Sentieri ~614, Itinerari ~151, Cammini
      ~53, Accessibili ~2. Se la copia non è accesa, dirlo al dev: la verifica su UAT la fa il team
      dopo il deploy (comando da lanciare una volta)
