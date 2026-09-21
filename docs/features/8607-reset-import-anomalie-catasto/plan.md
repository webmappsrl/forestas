> Ticket: oc:8607

# Rigenerazione delle anomalie legata alla fine dell'import — Piano implementativo

> **Per chi esegue:** usare `superpowers:subagent-driven-development` o
> `superpowers:executing-plans`. I passi usano checkbox (`- [ ]`).
> **Nessun commit automatico:** i passi «Commit» sono istruzioni per il dev, che committa lui.

**Goal:** far ripartire la ricalcolo delle anomalie del Catasto quando i job di import hanno
davvero finito di scrivere, invece che quando il comando ritorna.

**Architettura:** i job di import diventano `Batchable` e vengono dispatchati dentro un
`Bus::batch()`. Il batch porta una callback `then()` che lancia
`wm-package:trail-registry-normalize --force` solo se tutti i job sono riusciti, e una `catch()`
che registra l'errore senza lanciare il normalize su un archivio parziale. Il `->then()` dello
scheduler sparisce.

**Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Redis/Horizon, Pest.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- **Nessun commit senza istruzione esplicita del dev.** Vale anche per i subagent.
- **Non lanciare `vendor/bin/pest` senza aver verificato l'isolamento del DB** secondo
  [.claude/rules/test.md](../../../.claude/rules/test.md): `.env.testing` presente e puntato a
  `forestas_testing`, e quel database esistente. Se non è verificabile, chiedere.
- **Il submodule `wm-package` non si tocca.** `TrailRegistryNormalizeCommand` viene solo invocato.
- **Documentazione e commenti in italiano**, termini tecnici in inglese.
- **Commit convention:** `fix(oc:8607): ...` / `feat(oc:8607): ...` / `refactor(oc:8607): ...`
- **Nessuna esecuzione su UAT.** Si riproduce e si verifica in locale.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `database/migrations/*_create_job_batches_table.php` | tabella di stato dei batch, oggi assente |
| `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php` | aggiunta del trait `Batchable` |
| `app/Jobs/Import/ImportSardegnaSentieriPoiJob.php` | aggiunta del trait `Batchable` |
| `app/Console/Commands/ImportSardegnaSentieriCommand.php` | dispatch dentro un batch, callback, `resetData()` che azzera anche le iscrizioni |
| `routes/console.php` | rimozione del `->then()` |
| `tests/Feature/Import/ImportResetNormalizeTest.php` | copertura del nuovo comportamento |
| `tests/Feature/Import/ImportResetGuardsTest.php` | esistente, va mantenuto verde |

---

### Task 1: tabella `job_batches`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-tabella-job_batches)

`Bus::batch()` non funziona senza di essa e nel repo non esiste.

**Files:**
- Create: `database/migrations/<timestamp>_create_job_batches_table.php` (generata da artisan)

**Interfaces:**
- Produce: la tabella `job_batches`, usata dai task successivi.

- [ ] **Step 1: generare la migration**

```bash
docker exec -it php-forestas php artisan make:queue-batches-table
```

- [ ] **Step 2: eseguirla**

```bash
docker exec -it php-forestas php artisan migrate
```

- [ ] **Step 3: verificare che la tabella esista**

```bash
docker exec -it php-forestas php artisan tinker --execute="var_dump(Schema::hasTable('job_batches'));"
```

Atteso: `bool(true)`

- [ ] **Step 4: commit** — `feat(oc:8607): tabella job_batches per i batch di import`

---

### Task 2: i job di import diventano raggruppabili

**Files:**
- Modify: `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php`
- Modify: `app/Jobs/Import/ImportSardegnaSentieriPoiJob.php`
- Test: `tests/Feature/Import/ImportResetNormalizeTest.php`

**Interfaces:**
- Consuma: la tabella del Task 1.
- Produce: i due job espongono l'API di `Illuminate\Bus\Batchable` (`$this->batch()`), requisito
  del Task 3.

- [ ] **Step 1: scrivere il test che fallisce**

```php
<?php

use App\Jobs\Import\ImportSardegnaSentieriPoiJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use Illuminate\Bus\Batchable;

it('rende raggruppabili in batch i job di import', function () {
    expect(in_array(Batchable::class, class_uses_recursive(ImportSardegnaSentieriTrackJob::class), true))->toBeTrue()
        ->and(in_array(Batchable::class, class_uses_recursive(ImportSardegnaSentieriPoiJob::class), true))->toBeTrue();
});
```

- [ ] **Step 2: eseguirlo e vederlo fallire**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="raggruppabili in batch"
```

Atteso: FAIL, il trait non è presente.

- [ ] **Step 3: aggiungere il trait a entrambi i job**

In ciascuno dei due file, aggiungere l'import e il trait accanto agli altri:

```php
use Illuminate\Bus\Batchable;
...
class ImportSardegnaSentieriTrackJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    ...
```

- [ ] **Step 4: eseguire il test e vederlo passare**

```bash
docker exec -it php-forestas vendor/bin/pest --filter="raggruppabili in batch"
```

- [ ] **Step 5: commit** — `feat(oc:8607): i job di import diventano raggruppabili in batch`

---

### Task 3: il `--reset` lancia il normalize a job finiti

Il cuore del ticket. I dispatch sciolti diventano un batch; la ricalcolo si attacca alla sua
conclusione.

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`handle()`, `importPois()`,
  `importTracks()`)
- Test: `tests/Feature/Import/ImportResetNormalizeTest.php`

**Interfaces:**
- Consuma: i job `Batchable` del Task 2.
- Produce: il metodo
  `protected function dispatchImportBatch(array $jobs, string $runId, bool $withNormalize): void`,
  usato solo internamente.

**Nota sulla visibilità dell'errore.** Con il batch il comando ritorna prima che i job finiscano,
quindi l'exit code non può più segnalare un normalize fallito: il canale è il log `import`, dove il
comando già scrive (`Log::channel('import')`). Un fallimento silenzioso resta escluso, ma la sua
traccia sta lì, non nel codice di uscita.

- [ ] **Step 1: scrivere il test che fallisce**

```php
use Illuminate\Support\Facades\Bus;

it('raggruppa in un batch i job dispatchati da --reset', function () {
    Bus::fake();
    // ... fixture HTTP dell'API come in SardegnaSentieriImportServiceTest

    $this->artisan('sardegnasentieri:import', ['--reset' => true])->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() > 0);
});

it('non ricalcola le anomalie con --reset --only=pois', function () {
    Bus::fake();
    // ... fixture

    $this->artisan('sardegnasentieri:import', ['--reset' => true, '--only' => 'pois'])
        ->assertSuccessful();

    // Il batch parte comunque per i POI, ma senza ricalcolo: i tracciati
    // sono stati troncati e non reimportati.
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() > 0);
});

it('non raggruppa nulla quando --reset non è richiesto', function () {
    Bus::fake();
    // ... stesse fixture

    $this->artisan('sardegnasentieri:import')->assertSuccessful();

    Bus::assertNothingBatched();
});
```

- [ ] **Step 2: eseguirli e vederli fallire**

```bash
docker exec -it php-forestas vendor/bin/pest --filter=ImportResetNormalizeTest
```

Atteso: FAIL, nessun batch viene creato.

- [ ] **Step 3: raccogliere i job invece di dispatcharli**

In `importPois()` e `importTracks()`, sostituire il dispatch immediato con la costruzione
dell'elenco, restituendolo al chiamante:

```php
$jobs = [];
foreach ($this->sortTrackDispatchOrder($candidateIds, $trackList) as $id) {
    $jobs[] = new ImportSardegnaSentieriTrackJob($id);
    $dispatched[] = $id;
}
```

- [ ] **Step 4: dispatchare il batch in `handle()`**

Dopo le due importazioni, prima del log finale:

```php
$jobs = [...$poiJobs, ...$trackJobs];

if ($jobs === []) {
    $this->info('Nessun job da importare.');
} elseif (! $this->option('reset')) {
    // Import incrementale: nulla è stato troncato, non c'è niente da
    // ricalcolare. I job partono sciolti, come prima.
    foreach ($jobs as $job) {
        dispatch($job);
    }
} else {
    $this->dispatchImportBatch($jobs, $runId, $only !== 'pois');
}
```

Con `--only=pois` i tracciati sono stati troncati e non reimportati: l'archivio è vuoto per
volontà di chi ha lanciato il comando, non per un guasto. Ricalcolare le anomalie lì dentro
scriverebbe «catasto vuoto» come stato legittimo, quindi la ricalcolo si salta.

e il metodo:

```php
/**
 * Dopo un `--reset` le anomalie del Catasto sono state portate via dal
 * troncamento, e a ricalcolarle è `trail-registry-normalize`. Il momento
 * giusto per chiamarlo è quando i job hanno finito di scrivere: il comando
 * ritorna molto prima di loro, e un normalize lanciato allora leggerebbe un
 * archivio ancora vuoto — che è il difetto corretto da oc:8607.
 *
 * Su un batch con job falliti il normalize NON parte: le anomalie sono
 * relazioni fra tracciati, e calcolarle su un archivio incompleto non dà
 * meno anomalie, ne dà di sbagliate.
 */
protected function dispatchImportBatch(array $jobs, string $runId, bool $withNormalize): void
{
    Bus::batch($jobs)
        ->name("sardegnasentieri:{$runId}")
        ->allowFailures()
        ->then(function () use ($runId, $withNormalize) {
            if (! $withNormalize) {
                Log::channel('import')->info("[sardegnasentieri:{$runId}] Tracciati non reimportati (--only): anomalie non ricalcolate.");

                return;
            }

            Artisan::call('wm-package:trail-registry-normalize', ['--force' => true]);
            Log::channel('import')->info("[sardegnasentieri:{$runId}] Anomalie ricalcolate.");
        })
        ->catch(function (Batch $batch, Throwable $e) use ($runId) {
            Log::channel('import')->error(
                "[sardegnasentieri:{$runId}] Job falliti ({$batch->failedJobs}): anomalie NON ricalcolate.",
                ['exception' => $e->getMessage()]
            );
        })
        ->dispatch();
}
```

Import da aggiungere in testa al file: `Illuminate\Bus\Batch`, `Illuminate\Support\Facades\Bus`,
`Illuminate\Support\Facades\Artisan`, `Throwable`.

- [ ] **Step 5: eseguire i test e vederli passare**

```bash
docker exec -it php-forestas vendor/bin/pest --filter=ImportResetNormalizeTest
```

- [ ] **Step 6: verificare che le guardie esistenti siano ancora verdi**

```bash
docker exec -it php-forestas vendor/bin/pest --filter=ImportResetGuardsTest
```

- [ ] **Step 7: commit** — `fix(oc:8607): il reset ricalcola le anomalie a job finiti`

---

### Task 4: `--reset` azzera anche le domande di iscrizione

Oggi `trail_applications` non referenzia `ec_tracks`, quindi il `TRUNCATE ... CASCADE` non la
tocca: le domande restano, mentre i codici che avevano riservato spariscono con
`trail_registry_codes`.

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`resetData()`)
- Test: `tests/Feature/Import/ImportResetNormalizeTest.php`

- [ ] **Step 1: scrivere il test che fallisce**

```php
it('azzera anche le domande di iscrizione', function () {
    Bus::fake();
    // ... fixture, più una TrailApplication creata a mano

    $this->artisan('sardegnasentieri:import', ['--reset' => true])->assertSuccessful();

    expect(DB::table('trail_applications')->count())->toBe(0);
});
```

- [ ] **Step 2: eseguirlo e vederlo fallire**

Atteso: FAIL, la domanda sopravvive.

- [ ] **Step 3: troncare la tabella in `resetData()`**

Prima del troncamento di `ec_tracks`, perché i codici la referenziano:

```php
// Le domande di iscrizione non referenziano ec_tracks, quindi il CASCADE
// qui sotto non le tocca: senza questa riga resterebbero in piedi mentre i
// codici che avevano riservato spariscono con trail_registry_codes.
DB::statement('TRUNCATE TABLE trail_applications RESTART IDENTITY CASCADE');
$this->info('Truncated trail_applications.');
```

- [ ] **Step 4: eseguire il test e vederlo passare**

- [ ] **Step 5: commit** — `fix(oc:8607): il reset azzera anche le domande di iscrizione`

---

### Task 5: via il `->then()` dallo scheduler

**Files:**
- Modify: `routes/console.php:34-42`

- [ ] **Step 1: sostituire il blocco**

```php
if (env('SARDEGNASENTIERI_DAILY_RESET', false)) {
    // La ricalcolo delle anomalie non sta più qui: è agganciata al batch
    // dei job dentro il comando, l'unico punto che sa quando l'archivio è
    // completo (oc:8607).
    Schedule::command('sardegnasentieri:import --reset')->dailyAt('06:00');
} else {
    Schedule::command('sardegnasentieri:import')->hourly();
}
```

Rimuovere l'import di `Artisan` se non più usato nel file.

- [ ] **Step 2: verificare che lo scheduler registri ancora il job**

```bash
docker exec -it php-forestas php artisan schedule:list
```

Atteso: `sardegnasentieri:import --reset` presente, nessun errore.

- [ ] **Step 3: suite completa**

```bash
docker exec -it php-forestas vendor/bin/pest
```

- [ ] **Step 4: PHPStan**

```bash
docker exec -it php-forestas vendor/bin/phpstan analyse
```

- [ ] **Step 5: commit** — `refactor(oc:8607): la ricalcolo delle anomalie lascia lo scheduler`
