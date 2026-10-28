> Ticket: oc:8880

# Aree catastali a fine import — Piano di implementazione

> **Per chi esegue:** sub-skill `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`).
> **Nessun `git commit`, `git add` o `git push`**: i commit sono istruzioni per il dev.

**Obiettivo:** a fine batch dell'import Sardegna Sentieri accodare sempre la catena
`SyncTaxonomyWhereJob` → `RegenerateTaxonomyWhereOutputsJob(IMPORT_APP_ID)` sulla coda
`sardegnasentieri-import`.

**Architettura:** una sola modifica nella callback `finally` di
`ImportSardegnaSentieriCommand::dispatchImportBatch()`. La catena (`Bus::chain`) è la prima
istruzione, prima delle uscite anticipate per `--only`, import incrementale e reset oltre soglia. I
due job sono del wm-package e non si modificano.

**Stack:** Laravel 12, Pest, Horizon.

**Spec:** [overview.md](overview.md)

## Vincoli generali

- Repo: solo forestas. Nessuna modifica al wm-package.
- Coda: `ImportSardegnaSentieriTrackJob::QUEUE` (`'sardegnasentieri-import'`), mai `default`.
- App: `SardegnaSentieriImportService::IMPORT_APP_ID` (`1`).
- Commenti e documentazione in italiano, termini tecnici in inglese.
- Prima di lanciare qualunque test: verificare l'isolamento come da `.claude/rules/test.md`
  (`.env.testing` punta a `forestas_testing`, il database `forestas_testing` esiste). Se non è
  verificabile, fermarsi e chiedere.
- Dopo la modifica, `docker restart horizon-forestas` prima di provare l'import in locale.

## Da guardare in review

1. **Ordine nella catena:** il reindex deve partire dopo il sync, non in parallelo — coperto da
   `assertChained`.
2. **Coda del secondo job:** `RegenerateTaxonomyWhereOutputsJob` non dichiara una coda nel
   costruttore, quindi eredita `chainQueue`. Il test verifica `chainQueue` sul primo job.
3. **Uscite anticipate:** la catena deve partire anche con `--only` e con il reset oltre soglia —
   coperto dai test dei quattro rami.
4. **Comportamento invariato del resto del `finally`:** i test esistenti su anomalie e registro
   devono restare verdi senza modifiche.
5. **Ramo senza job** (`$jobs === []`, nessun batch): la catena non parte, e va bene così perché
   non è cambiato nulla — nessun test nuovo, i test esistenti `Nessun job da importare` restano
   verdi.

---

### Task 1: catena delle aree nel `finally` del batch

**File:**
- Modifica: `app/Console/Commands/ImportSardegnaSentieriCommand.php` — `dispatchImportBatch()`,
  callback `finally` (righe ~202-239) e docblock del metodo
- Test: `tests/Feature/Import/ImportRegistroCatastaleChainTest.php` (contiene già
  `fakeFinishedBatch()` e il setup HTTP; un secondo file ridichiarerebbe la funzione globale)

**Interfacce:**
- Usa: `Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob` (costruttore senza argomenti),
  `Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob(int $appId)`,
  `ImportSardegnaSentieriTrackJob::QUEUE`, `SardegnaSentieriImportService::IMPORT_APP_ID`
- Produce: nessuna interfaccia nuova

- [ ] **Step 1: scrivere i test che falliscono**

  In `ImportRegistroCatastaleChainTest.php` aggiungere gli `use` dei due job del package e di
  `App\Jobs\Import\ImportSardegnaSentieriTrackJob`, poi un helper e quattro test:

  ```php
  /**
   * La catena delle aree (oc:8880): sync di taxonomy_where e poi reindex,
   * sulla coda dell'import, per l'app di Sardegna Sentieri.
   */
  function assertCatenaAree(): void
  {
      Bus::assertChained([
          SyncTaxonomyWhereJob::class,
          RegenerateTaxonomyWhereOutputsJob::class,
      ]);

      Bus::assertDispatched(SyncTaxonomyWhereJob::class, function ($job) {
          return $job->queue === ImportSardegnaSentieriTrackJob::QUEUE
              && $job->chainQueue === ImportSardegnaSentieriTrackJob::QUEUE
              && unserialize($job->chained[0])->appId === SardegnaSentieriImportService::IMPORT_APP_ID;
      });
  }
  ```

  Test, tutti con `Bus::fake()` e la callback invocata come nei test esistenti:
  - `it('con --reset riuscito accoda la catena delle aree')` — `['--reset' => true]`,
    `fakeFinishedBatch($n, 0)`, poi `assertCatenaAree()`
  - `it('con --reset oltre la soglia accoda comunque la catena delle aree')` — `['--reset' => true]`,
    `fakeFinishedBatch($n, $n)`, poi `assertCatenaAree()` e
    `Bus::assertNotDispatched(ImportRegistroCatastaleJob::class)`
  - `it('nell import orario accoda la catena delle aree')` — nessuna opzione,
    `fakeFinishedBatch($n, 0)`, poi `assertCatenaAree()`
  - `it('con --only accoda comunque la catena delle aree')` — `['--only' => 'tracks']`,
    `fakeFinishedBatch($n, 0)`, poi `assertCatenaAree()` e
    `Bus::assertNotDispatched(ImportRegistroCatastaleJob::class)`

- [ ] **Step 2: verificare l'isolamento e lanciare i test, che devono fallire**

  Controllare le condizioni di `.claude/rules/test.md`, poi:

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Import/ImportRegistroCatastaleChainTest.php --filter="catena delle aree"`
  Atteso: 4 FAIL («The expected [...SyncTaxonomyWhereJob] job was not dispatched»)

- [ ] **Step 3: implementare**

  Nella callback `finally`, **prima** di `if (! $withNormalize)`:

  ```php
  Bus::chain([
      new SyncTaxonomyWhereJob,
      new RegenerateTaxonomyWhereOutputsJob(SardegnaSentieriImportService::IMPORT_APP_ID),
  ])->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)->dispatch();
  ```

  Con una riga di log sul canale `import` (`[sardegnasentieri:{$runId}] Sync aree e reindex accodati.`)
  e un commento che spiega perché sta prima delle guardie: il sync ricalcola dalla geometria e si
  può ripetere senza danni, mentre le guardie proteggono anomalie e registro; la coda è quella
  dell'import perché su `default` girerebbe ore dopo (oc:8607). Aggiornare il docblock di
  `dispatchImportBatch()` con un paragrafo sulla catena (oc:8880).

- [ ] **Step 4: lanciare i test del file, che devono passare**

  Run: `docker exec php-forestas vendor/bin/pest tests/Feature/Import/`
  Atteso: tutti PASS, compresi i test preesistenti su anomalie e registro

- [ ] **Step 5: PHPStan**

  Run: `docker exec php-forestas vendor/bin/phpstan analyse app/Console/Commands/ImportSardegnaSentieriCommand.php tests/Feature/Import/ImportRegistroCatastaleChainTest.php`
  Atteso: nessun errore nuovo

- [ ] **Step 6: prova reale in locale**

  `docker restart horizon-forestas`, poi `docker exec php-forestas php artisan sardegnasentieri:import`.
  A batch concluso: nel log `import` la riga della catena, e
  `SELECT count(*) FROM ec_tracks WHERE properties ? 'taxonomy_where'` pari al totale delle tracce
  con geometria.

- [ ] **Step 7: commit (istruzione per il dev, non eseguire)**

  ```bash
  git add app/Console/Commands/ImportSardegnaSentieriCommand.php tests/Feature/Import/ImportRegistroCatastaleChainTest.php docs/
  git commit -m "fix(oc:8880): sync delle aree catastali e reindex a fine import Sardegna Sentieri"
  ```

### Dopo il merge: verifica su UAT

Dopo il reset delle 06:00:
- tracce con `taxonomy_where` > 0;
- filtro «Aree catastali» della lista EC Tracce con risultati;
- documenti Elasticsearch delle tracce con `taxonomyWheres` valorizzato.
