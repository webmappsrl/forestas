> Ticket: oc:8959

# Distanza dei percorsi vicini al POI configurabile — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il raggio dei percorsi vicini al POI diventa una colonna di `apps`, modificabile in Nova,
esposta nel dettaglio del POI; cambiarla ricalcola i collegamenti dei soli POI.

**Architecture:** tutto in forestas. Una migration aggiunge la colonna; una classe di supporto
centralizza nome della colonna e default; il blocco di calcolo legge il raggio in SQL dall'App del
POI; un job nuovo accoda solo i blocchi dei POI più la rigenerazione esistente; un observer sul
modello `App` del package lo accoda al cambio del valore; `pois_tab()` è ridefinita nella Resource
`App` di forestas.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5, Pest, Horizon.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Branch `feature/oc-8959-distanza-dei-percorsi-vicini-al-poi-configurabile` creato da `origin/develop`.
- Nessuna modifica in `wm-package/` né in `wp-forestas/`.
- Colonna: `apps.ec_poi_nearby_ec_tracks_max_distance_meters`, intero, not null, default 1000.
- Chiave del dettaglio: `properties.forestas.nearby_tracks_max_distance_meters`.
- Campo Nova: label «Distanza massima dei percorsi vicini al POI (m)», regole `required|integer|min:1`, nessun massimo.
- Se il raggio manca si usa 1000, mai 0.
- Identificatori in inglese; commenti, docblock e documentazione in italiano.
- **Nessun commit, `git add` o push automatico**: i commit qui sotto sono istruzioni per il dev.
- **Prima di lanciare qualunque test** verificare l'isolamento come da `.claude/rules/test.md`
  (`.env.testing` su `forestas_testing`, database esistente). Se non è verificabile, fermarsi e chiedere.
- Dopo aver modificato un job, `docker restart horizon-forestas`.

## Review Focus

- **Due ricalcoli sovrapposti** (due salvataggi ravvicinati, o un salvataggio durante l'import): i
  blocchi con la stessa chiave vanno ritentati, non scartati → test sul middleware in Task 2.
- **Il raggio non cambia ma l'App si salva** (qualunque altro campo della tab): nessun ricalcolo → test in Task 4.
- **App diversa da quella dell'import**: nessun ricalcolo → test in Task 4.
- **POI senza percorsi vicini**: la chiave del raggio c'è lo stesso → test in Task 5.
- **Tentativi esauriti dai rilasci**: il supervisor ha `tries => 5` (`config/horizon.php:92`), e ogni
  rilascio conta un tentativo; con `releaseAfter` pari a `timeout + 60` (360 s) un blocco aspetta
  fino a circa 24 minuti, più della durata di un blocco (≤ 300 s) → valore fissato in Task 2.

---

### Task 1: Colonna su `apps` e classe di supporto

**Files:**
- Create: `database/migrations/2026_11_16_000000_add_ec_poi_nearby_ec_tracks_max_distance_meters_to_apps_table.php`
- Create: `app/Support/PoiNearbyTracksDistance.php`
- Test: `tests/Feature/Import/PoiNearbyTracksDistanceTest.php`

**Interfaces:**
- Produces: `App\Support\PoiNearbyTracksDistance` con
  - `public const COLUMN = 'ec_poi_nearby_ec_tracks_max_distance_meters';`
  - `public const DEFAULT_METERS = 1000;`
  - `public static function forApp(?\Wm\WmPackage\Models\App $app): int` — il valore della colonna, o `DEFAULT_METERS` se l'App è `null` o il valore è `null`/≤ 0.

- [ ] **Step 1: test che falliscono**

```php
it('aggiunge la colonna con default 1000', function () {
    $app = App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);

    expect($app->fresh()->{PoiNearbyTracksDistance::COLUMN})->toBe(1000);
});

it('ricade su 1000 senza App o senza valore', function () {
    expect(PoiNearbyTracksDistance::forApp(null))->toBe(1000)
        ->and(PoiNearbyTracksDistance::forApp(new App))->toBe(1000);
});

it('legge il valore dell\'App', function () {
    $app = App::factory()->createQuietly([PoiNearbyTracksDistance::COLUMN => 2500]);

    expect(PoiNearbyTracksDistance::forApp($app->fresh()))->toBe(2500);
});
```

- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import/PoiNearbyTracksDistanceTest.php` → FAIL (classe e colonna assenti).
- [ ] **Step 3:** migration: `up()` aggiunge `integer(COLUMN)->default(1000)` (not null); `down()` la toglie con `dropColumn`. Docblock in italiano col ticket.
- [ ] **Step 4:** classe `PoiNearbyTracksDistance` con le firme sopra.
- [ ] **Step 5:** stesso comando → PASS. Lanciare anche la migration sul DB locale: `docker exec php-forestas php artisan migrate`.
- [ ] **Step 6 (dev):** commit `feat(oc:8959): colonna della distanza dei percorsi vicini al POI su apps`.

---

### Task 2: Il blocco di calcolo legge il raggio dall'App e non scarta i blocchi sovrapposti

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-il-blocco-di-calcolo)

**Files:**
- Modify: `app/Jobs/Import/CalculatePoiTracksChunkJob.php` (docblock, `middleware()`, query di `handle()`, log)
- Modify: `config/sardegnasentieri.php:48` (togliere `poi_tracks_radius_meters`)
- Modify: `tests/Feature/Import/PoiTracksTest.php`

**Interfaces:**
- Consumes: `PoiNearbyTracksDistance::COLUMN`, `DEFAULT_METERS`.

- [ ] **Step 1: test che falliscono** in `PoiTracksTest.php`:
  - sostituire `usa il raggio dei POI, non quello dei sentieri correlati` con
    `usa il raggio dell'App del POI`: App con `COLUMN => 50`, config `nearby_tracks_radius_meters => 5000`, POI a ~111 m dalla traccia → `linkRows()` è `[]`; poi `COLUMN => 200` e nuovo `handle()` → una riga.
  - `ritenta un blocco che trova lo stesso blocco in corso`:
    ```php
    $middleware = (new CalculatePoiTracksChunkJob([1]))->middleware()[0];
    expect($middleware->releaseAfter)->toBe(360);
    ```
  - aggiornare il docblock del file (il raggio viene dall'App, non dalla config).
- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import/PoiTracksTest.php` → FAIL.
- [ ] **Step 3:** in `handle()` la query fa `JOIN apps a ON a.id = p.app_id` e usa
  `COALESCE(NULLIF(a.<COLUMN>, 0), <DEFAULT_METERS>)` come distanza di `ST_DWithin`, con colonna e
  default presi dalla classe di supporto. Niente più `config(...)`. Il log riporta il raggio letto
  (selezionarlo nella stessa query, o con una seconda lettura dell'App del primo POI).
- [ ] **Step 4:** `middleware()`: `->releaseAfter($this->timeout + 60)` al posto di `->dontRelease()`;
  aggiornare il commento (perché si ritenta: con due ricalcoli sovrapposti l'ultimo a girare scrive con l'ultimo raggio).
- [ ] **Step 5:** togliere `poi_tracks_radius_meters` da `config/sardegnasentieri.php`;
  `grep -rn "poi_tracks_radius_meters" app config tests` deve restituire solo commenti aggiornati o niente.
- [ ] **Step 6:** stesso comando del passo 2 → PASS.
- [ ] **Step 7 (dev):** commit `feat(oc:8959): il calcolo dei percorsi dei POI legge il raggio dall'App`.

---

### Task 3: Job che ricalcola solo i POI

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-il-job-che-ricalcola-solo-i-poi)

**Files:**
- Create: `app/Jobs/Import/CalculatePoiNearbyTracksJob.php`
- Modify: `app/Jobs/Import/CalculateNearbyTracksJob.php` (usa `chunks()` del job nuovo per i blocchi dei POI)
- Test: `tests/Feature/Import/PoiTracksTest.php`

**Interfaces:**
- Consumes: `CalculatePoiTracksChunkJob`, `CalculateNearbyTracksJob::regenerateOutputs()`.
- Produces: `App\Jobs\Import\CalculatePoiNearbyTracksJob` (`ShouldQueue`, coda `ImportSardegnaSentieriTrackJob::QUEUE`, `$timeout = 300`) con
  - `public static function chunks(): list<CalculatePoiTracksChunkJob>` — i POI dell'App di import in ordine di id, a blocchi di `sardegnasentieri.nearby_tracks_chunk_size`;
  - `handle(): void` — se non ci sono blocchi chiama `regenerateOutputs()`, altrimenti `Bus::batch(chunks())->name('sardegnasentieri poi nearby tracks')->onQueue(...)->allowFailures()->finally(fn () => CalculateNearbyTracksJob::regenerateOutputs())->dispatch()`.

- [ ] **Step 1: test che falliscono**
  - `ricalcola solo i blocchi dei POI senza toccare i sentieri correlati`: chunk size 2, tre POI, una riga
    in `ec_track_related_tracks`; `(new CalculatePoiNearbyTracksJob)->handle()` → la riga c'è ancora;
    `Bus::assertBatched` con soli `CalculatePoiTracksChunkJob`, `poiIds` `[[$a,$b],[$c]]`, nessun `CalculateNearbyTracksChunkJob`.
  - il test esistente `accoda i blocchi dei POI nello stesso batch dei correlati…` resta verde (regressione di `CalculateNearbyTracksJob`).
- [ ] **Step 2:** pest su `PoiTracksTest.php` → FAIL.
- [ ] **Step 3:** creare il job; in `CalculateNearbyTracksJob::handle()` sostituire la costruzione di `$poiChunks` con `CalculatePoiNearbyTracksJob::chunks()`, tenendo log e conteggi.
- [ ] **Step 4:** pest → PASS.
- [ ] **Step 5 (dev):** commit `feat(oc:8959): ricalcolo dei soli percorsi dei POI`.

---

### Task 4: Observer sul salvataggio dell'App

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-lobserver)

**Files:**
- Create: `app/Observers/AppNearbyTracksDistanceObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrazione accanto agli observer di oc:8959, ~riga 98)
- Test: `tests/Feature/Import/AppNearbyTracksDistanceObserverTest.php`

**Interfaces:**
- Consumes: `PoiNearbyTracksDistance::COLUMN`, `CalculatePoiNearbyTracksJob`, `SardegnaSentieriImportService::IMPORT_APP_ID`.
- Produces: `AppNearbyTracksDistanceObserver::saved(\Wm\WmPackage\Models\App $app): void`, registrato con `\Wm\WmPackage\Models\App::observe(...)`.

- [ ] **Step 1: test che falliscono** (con `Bus::fake([CalculatePoiNearbyTracksJob::class])`):
  - `accoda il ricalcolo quando cambia la distanza`: App import, `update([COLUMN => 2000])` → `Bus::assertDispatched(CalculatePoiNearbyTracksJob::class)`.
  - `non ricalcola se la distanza non cambia`: `update(['name' => 'Altro'])` → `assertNotDispatched`.
  - `non ricalcola per un'altra App`: App con id diverso, cambio della colonna → `assertNotDispatched`.
- [ ] **Step 2:** pest sul file → FAIL.
- [ ] **Step 3:** `saved()`: se `$app->id === IMPORT_APP_ID` e `$app->wasChanged(COLUMN)`, `CalculatePoiNearbyTracksJob::dispatch()->afterCommit()` (Nova avvolge il salvataggio in una transazione; vedi trappola Nova del package). Docblock in italiano.
- [ ] **Step 4:** registrazione in `AppServiceProvider::boot()` con un commento di una riga.
- [ ] **Step 5:** pest → PASS. Poi verifica che `tests/TestCase.php` col suo `Bus::fake` globale non nasconda il dispatch (se lo fa, il test usa `Bus::fake` esplicito come sopra).
- [ ] **Step 6 (dev):** commit `feat(oc:8959): ricalcolo dei percorsi dei POI al cambio della distanza`.

---

### Task 5: Campo Nova e chiave nel dettaglio del POI

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-campo-nova-e-dettaglio)

**Files:**
- Modify: `app/Nova/App.php` (ridefinisce `pois_tab()`)
- Modify: `lang/it.json`, `lang/en.json`
- Modify: `app/Support/ForestasPoiDetail.php` (`build()`, ~riga 54)
- Test: `tests/Feature/EcPoi/PoiDetailForestasTest.php`, `tests/Feature/Nova/AppPoisTabTest.php`

**Interfaces:**
- Consumes: `PoiNearbyTracksDistance::COLUMN`, `forApp()`.

- [ ] **Step 1: test che falliscono**
  - in `PoiDetailForestasTest.php`, `espone la distanza massima dei percorsi vicini`: App con
    `COLUMN => 1500`, POI senza percorsi → `forestas.nearby_tracks_max_distance_meters === 1500`
    e `nearby_tracks` assente; senza toccare la colonna → `1000`.
  - `tests/Feature/Nova/AppPoisTabTest.php`, `aggiunge la distanza ai campi della tab pois`: i campi di
    `pois_tab()` (chiamata via reflection o da una sottoclasse di test) contengono un `Number` con
    `attribute === COLUMN` e regole `['required', 'integer', 'min:1']`, e contengono ancora `poi_interaction` (campi del package invariati).
- [ ] **Step 2:** pest sui due file → FAIL.
- [ ] **Step 3:** `app/Nova/App.php`: `protected function pois_tab(): array` restituisce
  `[...parent::pois_tab(), Number::make(__('Maximum distance of tracks near the POI (m)'), COLUMN)->rules('required', 'integer', 'min:1')->step(1)->hideFromIndex()->help(__('…'))]`.
  Testo della help (chiave inglese, voce italiana in `lang/it.json`):
  «Distanza in linea d'aria entro cui una traccia è collegata al POI. Salvando un valore diverso si
  ricalcolano i percorsi vicini di tutti i POI e si riscrivono i file delle tracce: per qualche
  minuto il dettaglio di un POI può mostrare la distanza nuova con i percorsi vecchi.»
  Label italiana: «Distanza massima dei percorsi vicini al POI (m)».
- [ ] **Step 4:** `ForestasPoiDetail::build()`: aggiungere
  `'nearby_tracks_max_distance_meters' => PoiNearbyTracksDistance::forApp($poi->app)` dopo `nearby_tracks_count`; aggiornare il docblock della classe.
- [ ] **Step 5:** pest → PASS.
- [ ] **Step 6 (dev):** commit `feat(oc:8959): distanza dei percorsi vicini nella tab pois e nel dettaglio del POI`.

---

### Task 6: Documentazione e verifica finale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-documentazione-e-verifica)

**Files:**
- Modify: `docs/knowledge/dettaglio-del-poi-dati-forestas.md`
- Create: `docs/features/8959-distanza-dei-percorsi-vicini-al-poi-configurabile/notes.md`

- [ ] **Step 1:** nella pagina di conoscenza: il raggio non è più in config ma in
  `apps.ec_poi_nearby_ec_tracks_max_distance_meters` (Nova, tab `pois`); la chiave
  `nearby_tracks_max_distance_meters` nella tabella delle chiavi; il ricalcolo al salvataggio con
  `CalculatePoiNearbyTracksJob`; perché non in `config.json` (in «Perché così», con oc:8959); la
  finestra di disallineamento accettata. Prima e dopo mostrati al dev.
- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import tests/Feature/EcPoi tests/Feature/EcTrack tests/Feature/Nova` → tutti verdi.
- [ ] **Step 3:** `docker exec php-forestas vendor/bin/phpstan analyse` → nessun errore nuovo sui file toccati.
- [ ] **Step 4:** `docker exec php-forestas composer format`, poi `git status`: scartare file fuori dal lavoro.
- [ ] **Step 5:** prova manuale in locale: `docker restart horizon-forestas`; in Nova cambiare la
  distanza dell'App 1 a 2000 → in Horizon parte `CalculatePoiNearbyTracksJob` e il batch
  `sardegnasentieri poi nearby tracks`; `ec_poi_ec_track` cresce; `api/ec/poi/274` espone `2000`.
  Rimettere 1000.
- [ ] **Step 6 (dev):** commit `docs(oc:8959): distanza dei percorsi vicini al POI configurabile`.
