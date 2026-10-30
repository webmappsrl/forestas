> Ticket: oc:8513

# Enti importati e aggiornati come sentieri e POI — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** l'import legge la lista di tutti gli enti di Drupal con il loro `changed`, accoda un job per ogni ente nuovo o cambiato prima di POI e sentieri, e marca gli enti spariti.

**Architecture:** la fonte espone `getEnteList()` come `getTrackList()`; un nuovo `ImportSardegnaSentieriEnteJob` chiama `importOrUpdateEnte()`, che diventa uno specchio di Drupal e salva il `changed`; il comando concatena il batch degli enti e quello di POI e sentieri con `Bus::chain()`; i job di POI e sentieri collegano solo enti esistenti.

**Tech Stack:** Laravel 12, PHP 8.4, Pest, Horizon (coda `sardegnasentieri-import`), PostgreSQL.

**Spec:** `docs/features/8513-gli-ente-importati-non-vengono-mai-aggiornati-dalla-sorgente/overview.md`

## Global Constraints

- Repo `forestas` soltanto: `wm-package` e `wp-forestas` non si toccano.
- Commenti e documentazione in italiano; commit `fix(oc:8513): …`, **solo su richiesta esplicita del dev**.
- Test: `docker exec php-forestas vendor/bin/pest` (isolamento su `forestas_testing`, `.claude/rules/test.md`).
- Coda dei job di import: `ImportSardegnaSentieriTrackJob::QUEUE` (`sardegnasentieri-import`), riassegnata sul batch con `->onQueue()` (oc:8607).
- Date di `changed` nel formato della lista dei sentieri: `Y-m-d H:i:s` ora italiana (`DrupalValues::dateTime`).
- Nessun filtro `status = 1`: le bozze restano.
- Dopo ogni modifica alla fonte DB: `sardegnasentieri:compare-source` (oc:8900).

## Review Focus

1. Un sentiero il cui gestore non è ancora in `entes` (job dell'ente fallito): il collegamento si salta con un warning, il sentiero si importa lo stesso — test in Task 2.
2. Un ente con contatti, descrizione, posizione o immagine svuotati in Drupal: in Forestas tornano vuoti — test in Task 2.
3. `--reset`: gli enti restano con lo stesso id, `enteables` è vuota prima dei job — test in Task 4.
4. Nessun ente cambiato ma POI o sentieri sì: il batch di POI e sentieri parte da solo, senza chain — test in Task 4.
5. Un ente marcato sparito che ricompare in Drupal: perde il marchio al suo import — test in Task 2.

---

### Task 1: lista degli enti dalla fonte

**Files:**
- Modify: `app/Services/Import/Source/SardegnaSentieriSource.php`, `SardegnaSentieriDatabaseSource.php`, `SardegnaSentieriApiSource.php`
- Modify: `tests/Pest.php` (`fonteSardegnaSentieriFinta`)

**Interfaces:**
- Produces: `SardegnaSentieriSource::getEnteList(): array<string, string>` (`nid => changed`, come `getTrackList()`); `fonteSardegnaSentieriFinta(array $tracks = [], array $pois = [], bool $available = true, array $enti = [])`.

- [ ] **Step 1:** aggiungi `getEnteList()` all'interfaccia con lo stesso docblock di `getTrackList()`.
- [ ] **Step 2:** in `SardegnaSentieriDatabaseSource` implementalo con `changedList(['ente_istituzione_societa'])`; costante `ENTE_TYPE = 'ente_istituzione_societa'`.
- [ ] **Step 3:** in `SardegnaSentieriApiSource` lancia `\LogicException('Le API non espongono la lista degli enti')`: la fonte API serve solo al confronto.
- [ ] **Step 4:** aggiungi il parametro `$enti` all'helper e `shouldReceive('getEnteList')->andReturn($enti)`.
- [ ] **Step 5:** verifica sulla copia di UAT (tunnel aperto):
  `docker exec -e DB_SARDEGNASENTIERI_HOST=host.docker.internal -e DB_SARDEGNASENTIERI_PORT=3307 php-forestas php artisan tinker --execute='echo count(app(App\Services\Import\Source\SardegnaSentieriSource::class)->getEnteList());'`
  Expected: `93`.
- [ ] **Step 6:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import` → tutto verde.

### Task 2: l'ente come specchio di Drupal

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php` (`importOrUpdateEnte`, `resolveOrImportEnte`, chiamanti in `syncEntiPoi`/`syncEnti`)
- Test: `tests/Feature/Import/SardegnaSentieriEnteImportTest.php` (nuovo)

**Interfaces:**
- Consumes: `SardegnaSentieriSource::getNodeDetail(int): array`.
- Produces: `importOrUpdateEnte(string $sardegnaSentieriId, string $changed): int` (lancia eccezione invece di restituire null); `resolveEnte(int $nodeId): ?int` al posto di `resolveOrImportEnte`.

- [ ] **Step 1: test che falliscono** (fonte finta con `getNodeDetail` e `getTaxonomyTerm` mockati, `SardegnaSentieriMediaSyncService` mockato):
  - `scrive l ente e salva il changed di Drupal` → `properties['forestas']['updated_at'] === '2026-10-30 10:00:00'`, nome `it` ed `en` dal titolo.
  - `svuota i campi svuotati in Drupal` → ente esistente con contatti, descrizione, pagina web e geometria; nodo senza quei campi → tutti null dopo l'import.
  - `toglie l immagine rimossa in Drupal` → nodo senza `field_immagine_principale` → `syncImportedImages($ente, [])` chiamato una volta.
  - `toglie il marchio di ente sparito` → ente con `deleted_from_source = true` → dopo l'import la chiave non c'è.
  - `fallisce se il nodo non si legge` → `getNodeDetail` lancia → l'eccezione arriva al chiamante.
  - `un sentiero collega solo enti esistenti` → `importTrackFromResponse` con gestore `5281` presente e manutentore `9999` assente → una riga in `enteables` (ruolo `gestore`), nessun nuovo ente, `getNodeDetail` mai chiamato.
- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest tests/Feature/Import/SardegnaSentieriEnteImportTest.php` → FAIL.
- [ ] **Step 3:** riscrivi `importOrUpdateEnte()`:
  - niente `try/catch` esterno; titolo vuoto → `\RuntimeException`;
  - contatti e descrizione: `setTranslations` se valorizzati, altrimenti `null`; `geometry` null se manca la posizione;
  - `properties`: conserva le chiavi esistenti, scrive `forestas.updated_at = $changed`, toglie `forestas.deleted_from_source`;
  - immagine: `syncImportedImages($ente, $url === null ? [] : [[...]])`, così un'immagine tolta in Drupal si cancella.
- [ ] **Step 4:** sostituisci `resolveOrImportEnte()` con `resolveEnte()`: cache per istanza, lookup per `sardegnasentieri_id`, se assente `Log::channel('import')->warning("Ente {$nodeId} non ancora importato: collegamento saltato")` e `null`. Aggiorna i 5 chiamanti.
- [ ] **Step 5:** test del Task 2 → PASS; poi `vendor/bin/pest tests/Feature/Import` → verde.

### Task 3: il job dell'ente

**Files:**
- Create: `app/Jobs/Import/ImportSardegnaSentieriEnteJob.php`
- Test: `tests/Feature/Import/SardegnaSentieriEnteImportTest.php`

**Interfaces:**
- Consumes: `importOrUpdateEnte(string, string): int`.
- Produces: `new ImportSardegnaSentieriEnteJob(int $externalId, string $changed)`.

- [ ] **Step 1: test che falliscono:**
  - `il job importa l ente` → `handle()` con service mockato → `importOrUpdateEnte('5281', '2026-10-30 10:00:00')` chiamato una volta.
  - `il job aspetta la ricarica della copia` → lock tenuto (`forestas.sardegnasentieri.reload_lock_path`) → service mai chiamato.
  - `il job sta sulla coda dell import` → `->queue === 'sardegnasentieri-import'`.
- [ ] **Step 2:** → FAIL.
- [ ] **Step 3:** crea il job ricalcando `ImportSardegnaSentieriPoiJob`: `Batchable`, `$tries = 5`, stesso `backoff()`, `$timeout = 120`, coda nel costruttore, `release(600)` con `DrupalDumpLock::isHeld()`.
- [ ] **Step 4:** → PASS.

### Task 4: il comando — lista, marcatura, batch in due tempi, reset

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php`
- Test: `tests/Feature/Import/ImportSardegnaSentieriEntiCommandTest.php` (nuovo)

**Interfaces:**
- Consumes: `getEnteList()`, `ImportSardegnaSentieriEnteJob`.
- Produces: `importEnti(SardegnaSentieriSource $source): array{0: list<int>, 1: int, 2: list<ImportSardegnaSentieriEnteJob>}`; `markRemovedEnti(array $sourceIds): int`; `makeImportBatch(...): PendingBatch` estratto da `dispatchImportBatch()`.

- [ ] **Step 1: test che falliscono** (`Bus::fake()`, `fonteSardegnaSentieriFinta(..., enti: [...])`):
  - `accoda prima gli enti e poi POI e sentieri` → un ente e un sentiero cambiati → `Bus::assertChained([Bus::chainedBatch(enti), Bus::chainedBatch(sentieri)])`, entrambi sulla coda `sardegnasentieri-import`.
  - `non riaccoda un ente non cambiato` → ente con `forestas.updated_at` uguale alla lista → nessun `ImportSardegnaSentieriEnteJob`.
  - `senza enti cambiati accoda solo POI e sentieri` → `Bus::assertBatched` sul solo batch dei sentieri, nessuna chain.
  - `marca gli enti spariti` → ente locale `123` assente dalla lista → `deleted_from_source = true`; lista vuota di enti con sentieri presenti → nessun marchio (fonte vuota bloccata da `isAvailable`).
  - `con --only=tracks importa comunque gli enti` → chain come sopra.
  - `il reset svuota enteables e tiene gli enti` → con backup mockato a successo: dopo il comando `entes` ha ancora l'ente con lo stesso id, `enteables` è vuota, l'ente è in coda anche con `changed` uguale.
- [ ] **Step 2:** → FAIL.
- [ ] **Step 3:** `importEnti()` ricalca `importTracks()`: confronto su `properties->'forestas'->>'updated_at'` di `Ente` per `sardegnasentieri_id`, forzato con `--force`/`--reset`, poi `markRemovedEnti()` sul modello dei sentieri.
- [ ] **Step 4:** in `handle()` gli enti si leggono sempre, prima di POI e sentieri; dispatch:
  - enti e POI/sentieri → `Bus::chain([Bus::batch($entiJobs)->name('sardegnasentieri enti')->onQueue(QUEUE)->allowFailures(), $this->makeImportBatch(...)])->onQueue(QUEUE)->dispatch()`;
  - solo enti → batch degli enti da solo, poi il ramo «Nessun job» di oggi per il registro catastale;
  - solo POI/sentieri → come oggi.
- [ ] **Step 5:** `resetData()`: aggiungi `DB::statement('TRUNCATE TABLE enteables RESTART IDENTITY')` con commento (niente chiavi esterne, gli id dei sentieri si riusano); `entes` non si tocca.
- [ ] **Step 6:** log `import` del run con `enti_dispatched` ed `enti_removed`.
- [ ] **Step 7:** test del Task 4 → PASS; poi la suite intera `docker exec php-forestas vendor/bin/pest` → verde; `vendor/bin/phpstan analyse` → nessun errore nuovo.

### Task 5: verifica in locale dalla copia di UAT

- [ ] **Step 1:** salva i collegamenti di oggi:
  `docker exec postgres-forestas psql -U forestas -d forestas -tAc "select e.sardegnasentieri_id, t.properties->>'sardegnasentieri_id', x.ruolo from enteables x join entes e on e.id=x.ente_id join ec_tracks t on t.id=x.enteable_id and x.enteable_type like '%EcTrack' order by 1,2,3" > <scratchpad>/enteables-prima.txt`
- [ ] **Step 2:** avvia Horizon con le variabili del tunnel e lancia `sardegnasentieri:import --force`; aspetta la fine del secondo batch (`job_batches.finished_at`).
- [ ] **Step 3:** stessa query → `enteables-dopo.txt`; `diff` → nessuna riga persa (righe in più ammesse solo per sentieri modificati in Drupal dopo l'ultimo import locale).
- [ ] **Step 4:** `select count(*), count(properties->'forestas'->>'updated_at') from entes` → `93 | 93`; job falliti dell'import → 0. Ferma Horizon.

### Task 6: documentazione

**Files:**
- Modify: `docs/knowledge/import-asincrono-e-anomalie.md` (ordine dei batch), `docs/knowledge/copia-database-drupal.md` (lista degli enti), `.claude/rules/import-sardegna-sentieri.md`
- Create: `docs/features/8513-…/notes.md`

- [ ] **Step 1:** nella pagina sull'import asincrono: «prima gli enti, poi POI e sentieri», con il motivo (collegamenti dopo il reset).
- [ ] **Step 2:** trappole dell'import, una riga ciascuna: gli enti sono uno specchio di Drupal (le modifiche in Nova si perdono, oc:8513); il `--reset` svuota `enteables` ma non `entes` (oc:8513).
- [ ] **Step 3:** `notes.md` con decisioni e follow-up (immagini non ritentate per tutti e tre i tipi).
- [ ] **Step 4: Commit** (solo dopo il «sì» del dev): `fix(oc:8513): enti importati e aggiornati dalla copia come sentieri e POI`.
