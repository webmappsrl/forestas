> Ticket: oc:8900

# Import Sardegna Sentieri dalla copia del DB Drupal — piano di implementazione

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` (consigliata)
> o `superpowers:executing-plans`. I passi usano le checkbox (`- [ ]`).
>
> **Nessun `git commit`, `git add` o `git push` in autonomia** (regola in cima al `CLAUDE.md`):
> i passi «Commit» sono il messaggio da proporre all'utente, che committa lui.

**Obiettivo:** l'import legge i dati dalla copia SQL del DB Drupal invece che dalle API, con
risultato identico.

**Architettura:** un'interfaccia `SardegnaSentieriSource` con gli stessi metodi di lettura che oggi
ha `SardegnaSentieriClient` e che restituisce **le stesse strutture del JSON delle API**: così DTO,
service, job e comando non cambiano logica, cambia solo cosa c'è dietro l'interfaccia. Due
implementazioni: `SardegnaSentieriDatabaseSource` (la fonte dell'import) e
`SardegnaSentieriApiSource` (il codice HTTP di oggi, spostato, usato **solo** dal comando di
confronto). `SardegnaSentieriClient` resta solo per scaricare i file.

**Tech stack:** Laravel 12, PHP 8.4, Pest, MySQL 8 (connessione `sardegnasentieri`, utente
`readonly`), Docker Compose.

**Spec:** [overview.md](overview.md). Mappatura campo per campo API → tabelle della copia:
`docs/knowledge/copia-database-drupal.md`, sezione «Corrispondenza con le API usate
dall'import» — è il riferimento per ogni query, il piano non la ripete.

## Vincoli globali

- Cambia solo la fonte: campi scritti, destinazioni, scheduling (`routes/console.php`), job, code,
  incrementale, `--reset`, ricalcolo anomalie restano identici.
- **Nessun filtro su `status`** nelle query dell'import: le bozze (3 sentieri, 1 itinerario, 23 POI
  sulla copia di UAT) restano importate come oggi. Le query d'esempio della pagina di conoscenza
  hanno `status = 1`: **non copiarle così**.
- `changed`, `created`, `data_rilievo`: unix UTC nella copia, nelle API stringa `Y-m-d H:i:s` in
  ora italiana (`changed` 10:00:01 UTC di novembre → `2024-11-16 11:00:01`). Conversione in PHP con
  `Europe/Rome`, **mai** `FROM_UNIXTIME` (usa il fuso della sessione MySQL).
- `changed` del nodo dalla riga `default_langcode = 1` di `node_field_data`.
- URL dei file: `public://<percorso>` → `<base>/<percorso>` con **ogni segmento** codificato con
  `rawurlencode` (le API danno `Carta%201.%20Baronie%20e%20Montalbo%20%28unita%202%29.gpx`).
  `<base>` = `config('forestas.sardegnasentieri.files_url')`, default
  `https://www.sardegnasentieri.it/sites/default/files`, nessuna variabile `.env` obbligatoria.
- Nodo non trovato nella copia → `RuntimeException`, mai un array vuoto.
- Testi in italiano nei commenti, termini tecnici in inglese.
- Prima di lanciare la suite: verificare l'isolamento come da `.claude/rules/test.md`
  (`.env.testing` → `forestas_testing`, database esistente); se non verificabile, chiedere.

## Review Focus

1. **Nomi di file con spazi, parentesi, accenti** → URL identico a quello delle API (test in Task 2).
2. **Nodo cancellato fra lista e dettaglio** → eccezione, il job fallisce e non scrive (Task 2).
3. **Campo multilingua presente solo in `it`** → la chiave `en` manca come oggi nelle API, non
   compare vuota (verificato dal confronto, Task 4).
4. **Riferimento a un nodo/termine cancellato** (`partenza`, `poi_correlati`, enti) → scartato con
   join su `node_field_data`, come documentato nella pagina di conoscenza (Task 2/3, confronto).
5. **Lock rimasto da una ricarica fallita** → il comando salta e lo scrive nel log con l'età del
   lock, invece di fermarsi in silenzio (Task 5).

---

### Task 1: Interfaccia della fonte e spostamento del codice HTTP

Refactoring a comportamento invariato: alla fine l'import gira ancora sulle API, ma passando
dall'interfaccia.

**Files:**
- Create: `app/Services/Import/Source/SardegnaSentieriSource.php`
- Create: `app/Services/Import/Source/SardegnaSentieriApiSource.php`
- Modify: `app/Http/Clients/SardegnaSentieriClient.php` (resta solo `getGpxContent`)
- Modify: `app/Services/Import/SardegnaSentieriImportService.php:70` e chiamate a
  `getTaxonomy`/`getTaxonomyWarnings`/`getNodeDetail`/`getTaxonomyTerm`/`get*Detail`
- Modify: `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php:77`,
  `ImportSardegnaSentieriPoiJob.php:76`
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php:75,273,362,579`
- Modify: `app/Providers/AppServiceProvider.php` (binding)
- Test: `tests/Feature/Import/*.php` (i `Mockery::mock(SardegnaSentieriClient::class)` dei metodi
  di lettura diventano mock di `SardegnaSentieriSource`; `getGpxContent` resta sul client)

**Interfaces — Produces:**

```php
interface SardegnaSentieriSource
{
    public function isAvailable(): bool;                      // ex isReachable
    /** @return array<string, string> {nid: 'Y-m-d H:i:s'} */
    public function getPoiList(): array;
    public function getPoiDetail(int $id): ApiPoiResponse;
    /** @return array<string, string> */
    public function getTrackList(): array;
    public function getTrackDetail(int $id): ApiTrackResponse;
    /** @return array<int|string, array<string, mixed>> */
    public function getTaxonomy(string $vocabulary): array;
    /** @return array<int|string, array<string, mixed>> */
    public function getTaxonomyWarnings(): array;
    /** @return array<string, mixed> forma del REST /node/{id} */
    public function getNodeDetail(int $id): array;
    /** @return array<string, mixed> forma del REST /taxonomy/term/{id} */
    public function getTaxonomyTerm(int $id): array;
}
```

`SardegnaSentieriClient::getGpxContent(string $url): string` invariato.

- [ ] **Step 1:** creare l'interfaccia e `SardegnaSentieriApiSource`, spostando i metodi HTTP dal
  client senza cambiarne il corpo (`isReachable` → `isAvailable`, stesso commento sul 10/09).
- [ ] **Step 2:** sostituire il type-hint nei consumatori e registrare
  `SardegnaSentieriSource::class → SardegnaSentieriApiSource::class` nel service provider.
- [ ] **Step 3:** adattare i mock dei test; nessuna asserzione cambia.
- [ ] **Step 4:** `vendor/bin/pest tests/Feature/Import` (dentro `php-forestas`) → tutti verdi, stesso
  numero di test di prima.
- [ ] **Step 5:** `vendor/bin/phpstan analyse` → nessun errore nuovo.
- [ ] **Step 6: Commit** — `refactor(oc:8900): fonte dell'import dietro l'interfaccia SardegnaSentieriSource`

### Task 2: Fonte dalla copia — liste, sentieri e POI

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-codice-del-modulo-drupal-non-disponibile)

**Files:**
- Create: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`
- Create: `app/Services/Import/Source/DrupalValues.php` (conversioni pure, testabili senza MySQL)
- Modify: `config/forestas.php` (chiave `sardegnasentieri.files_url`)
- Test: `tests/Unit/Import/DrupalValuesTest.php`

**Interfaces — Produces:**
- `DrupalValues::dateTime(int $unixUtc): string` → `Y-m-d H:i:s` in `Europe/Rome`
- `DrupalValues::fileUrl(string $uri): ?string` → `null` per `private://` e URI non `public://`
- `SardegnaSentieriDatabaseSource` implementa `getPoiList`, `getTrackList`, `getPoiDetail`,
  `getTrackDetail`, `isAvailable` (gli altri metodi in Task 3). Usa
  `DB::connection('sardegnasentieri')`. Per i dettagli costruisce l'array nella forma del JSON delle
  API e lo passa a `ApiTrackResponse::fromJson` / `ApiPoiResponse::fromJson`: i DTO non cambiano.
- `isAvailable()`: `true` solo se la lista dei sentieri non è vuota (una connessione riuscita su
  una copia vuota non basta, overview → Requisiti).

- [ ] **Step 1: test delle conversioni**

```php
it('converte changed nell\'ora italiana come le API', function () {
    expect(DrupalValues::dateTime(1731751201))->toBe('2024-11-16 11:00:01');   // novembre, +1
    expect(DrupalValues::dateTime(1719828000))->toBe('2024-07-01 12:00:00');   // luglio, +2
});

it('ricostruisce l\'URL del file come le API', function () {
    expect(DrupalValues::fileUrl('public://2024-01/Carta 1. Baronie e Montalbo (unita 2).gpx'))
        ->toBe('https://www.sardegnasentieri.it/sites/default/files/2024-01/Carta%201.%20Baronie%20e%20Montalbo%20%28unita%202%29.gpx');
    expect(DrupalValues::fileUrl('public://207764.jpg'))
        ->toBe('https://www.sardegnasentieri.it/sites/default/files/207764.jpg');
    expect(DrupalValues::fileUrl('private://files_privati/x.pdf'))->toBeNull();
});
```

- [ ] **Step 2:** `vendor/bin/pest tests/Unit/Import/DrupalValuesTest.php` → FAIL (classe assente).
- [ ] **Step 3:** implementare `DrupalValues` e la chiave di config.
- [ ] **Step 4:** stesso comando → PASS.
- [ ] **Step 5:** implementare liste e dettagli seguendo la tabella di corrispondenza della pagina
  di conoscenza: nessun filtro su `status`; `changed` da `default_langcode = 1`; riferimenti a nodi
  cancellati scartati con join su `node_field_data`; `geometryFallback` solo `delta = 0`; dettaglio
  senza righe → `RuntimeException("Nodo {$id} non trovato nella copia del DB Drupal")`.
- [ ] **Step 6:** verifica manuale con il tunnel aperto (vedi howto) e
  `DB_SARDEGNASENTIERI_HOST=host.docker.internal`, `DB_SARDEGNASENTIERI_PORT=3307`:
  `php artisan tinker --execute="dump(count(app(App\Services\Import\Source\SardegnaSentieriDatabaseSource::class)->getTrackList()));"`
  → 766 (611 sentieri + 151 itinerari + 4 bozze), dato di UAT al 29/10.
- [ ] **Step 7: Commit** — `feat(oc:8900): lettura di liste, sentieri e POI dalla copia del DB Drupal`

### Task 3: Fonte dalla copia — tassonomie, avvertenze, enti, termini

**Files:**
- Modify: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`

**Interfaces — Produces:** `getTaxonomy`, `getTaxonomyWarnings`, `getNodeDetail`,
`getTaxonomyTerm` nella stessa forma delle API (`/ss/tassonomia/{voc}`, REST `/node/{id}` e
`/taxonomy/term/{id}` per i soli campi letti dal service — tabella «`/node/{id}`» della pagina di
conoscenza). `description` delle tassonomie **assente**, come nelle API.

- [ ] **Step 1:** implementare i quattro metodi; `getNodeDetail`/`getTaxonomyTerm` senza righe →
  `RuntimeException`.
- [ ] **Step 2:** `vendor/bin/phpstan analyse` → nessun errore nuovo.
- [ ] **Step 3: Commit** — `feat(oc:8900): lettura di tassonomie ed enti dalla copia del DB Drupal`

### Task 4: Comando di confronto API contro copia

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-url-del-nodo-confrontato-e-non-escluso)

**Files:**
- Create: `app/Console/Commands/CompareSardegnaSentieriSourceCommand.php`
  (`sardegnasentieri:compare-source {--only=}`, sola lettura)
- Test: `tests/Feature/Import/CompareSardegnaSentieriSourceCommandTest.php`

**Interfaces — Consumes:** le due implementazioni di `SardegnaSentieriSource`, istanziate per nome
(non dal binding).

Confronta: liste (chiavi e valori), `get*Detail` serializzati con `get_object_vars` ricorsivo, le
quattro tassonomie, le avvertenze, `getNodeDetail` per ogni ente referenziato, `getTaxonomyTerm`
per i tipi ente non riconosciuti. Salta i nid con `changed` diverso fra le due liste (riportandone
il numero). Campi esclusi, in una costante con il motivo: `url` (non salvato), `lunghezza`,
`dislivello_totale`, `durata`, `ele_min`, `ele_max` (letti ma non usati, oc:8641). Output: una riga
per differenza `tipo nid campo: api=… copia=…`, exit 1 se ce n'è almeno una.

- [ ] **Step 1: test** con due fonti finte (`Mockery`) che differiscono in un campo usato, in un
  campo escluso e in un nid con `changed` diverso:

```php
it('segnala solo le differenze sui campi usati', function () {
    // fonti finte: name.it diverso sul nid 10, url diverso sul nid 11, changed diverso sul nid 12
    $this->artisan('sardegnasentieri:compare-source --only=tracks')
        ->expectsOutputToContain('track 10 name.it')
        ->doesntExpectOutputToContain('track 11')
        ->doesntExpectOutputToContain('track 12 ')
        ->expectsOutputToContain('1 nodi saltati (changed diverso)')
        ->assertExitCode(1);
});
```

- [ ] **Step 2:** eseguire il test → FAIL.
- [ ] **Step 3:** implementare il comando.
- [ ] **Step 4:** test → PASS.
- [ ] **Step 5: confronto reale**, in locale con il tunnel verso UAT:
  `php artisan sardegnasentieri:compare-source` → correggere le query di Task 2/3 finché l'exit
  code è 0. **È la condizione per passare a Task 6.**
- [ ] **Step 6: Commit** — `feat(oc:8900): comando di confronto fra API e copia del DB Drupal`

### Task 5: Lock durante la ricarica della copia

**Files:**
- Modify: `scripts/drupal-dump-reload.sh` (crea `storage/drupal-dump/reload.lock` prima del
  `DROP DATABASE`, lo rimuove come ultimo passo; **nessun `trap`**: se la ricarica fallisce il lock
  resta, e l'import non legge una copia a metà)
- Create: `app/Services/Import/Source/DrupalDumpLock.php`
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`handle`, prima di tutto)
- Modify: `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php`, `ImportSardegnaSentieriPoiJob.php`
- Test: `tests/Feature/Import/DrupalDumpLockTest.php`

**Interfaces — Produces:** `DrupalDumpLock::isHeld(): bool`, `DrupalDumpLock::heldSince(): ?CarbonImmutable`
(file `storage_path('drupal-dump/reload.lock')`, letto via `filemtime`).

- [ ] **Step 1: test**

```php
it('il comando salta e lo scrive nel log se la copia si sta ricaricando', function () {
    // crea il file di lock in una storage temporanea
    $this->artisan('sardegnasentieri:import')->assertExitCode(0);
    // nessun job accodato (Bus::fake), riga di log con «copia in ricarica» e l'età del lock
});

it('il job si rimette in coda se la copia si sta ricaricando', function () {
    // lock presente → $job->release(600) chiamato, getTrackDetail mai chiamato
});
```

- [ ] **Step 2:** test → FAIL.
- [ ] **Step 3:** implementare: il comando, con lock presente, esce con `SUCCESS` senza toccare
  nulla e logga `[sardegnasentieri:<run>] Copia del DB Drupal in ricarica dal <ora>: import
  saltato`; i job chiamano `$this->release(600)` prima di leggere. Ogni rilascio conta come un
  tentativo (`$tries = 5`): con una ricarica di pochi minuti ne basta uno; un lock rimasto da una
  ricarica fallita fa fallire i job dopo cinque giri, cioè circa 50 minuti, come una fonte
  irraggiungibile oggi.
- [ ] **Step 4:** test → PASS; `bash -n scripts/drupal-dump-reload.sh` → nessun errore.
- [ ] **Step 5: Commit** — `feat(oc:8900): l'import non legge la copia mentre si ricarica`

### Task 6: L'import passa alla copia

Solo dopo Task 4, Step 5 con exit code 0.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (binding → `SardegnaSentieriDatabaseSource`)
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`$description` e messaggi di
  `sourceIsReachable`: «la copia del DB Drupal non risponde o è vuota»)
- Modify: `app/Services/Import/Source/SardegnaSentieriApiSource.php` (docblock: esiste solo per il
  confronto, va rimossa con il comando alla dismissione di Drupal)

- [ ] **Step 1:** cambiare il binding e i messaggi.
- [ ] **Step 2:** `vendor/bin/pest tests/Feature/Import tests/Unit/Import` → verdi.
- [ ] **Step 3:** import reale in locale con il tunnel:
  `php artisan sardegnasentieri:import --only=tracks` → nessun job fallito in Horizon; un secondo
  giro non accoda job (l'incrementale riconosce i `changed`); nessun media cancellato
  (`select count(*) from media` uguale prima e dopo).
- [ ] **Step 4: Commit** — `feat(oc:8900): l'import Sardegna Sentieri legge dalla copia del DB Drupal`

### Task 7: Copia nel compose dello shard

**Files:**
- Modify: `compose.yml` (`include: - sardegnasentieri-dump.compose.yml`, accanto a `wp-forestas`)

- [ ] **Step 1:** aggiungere l'include.
- [ ] **Step 2:** `docker compose -f compose.yml config --services` contiene
  `db-sardegnasentieri`; `docker compose -f develop.compose.yml config --services` e
  `-f local.compose.yml` **non** lo contengono.
- [ ] **Step 3:** istruzione per l'utente su UAT (non eseguirla in autonomia):
  `docker compose -f compose.yml up -d db-sardegnasentieri`, poi
  `docker volume ls | grep sardegnasentieri-pgdata` → stesso volume `forestas_…` di prima, e la
  copia risponde con i dati.
- [ ] **Step 4: Commit** — `feat(oc:8900): copia del DB Drupal nel compose dello shard`

### Task 8: Documentazione

**Files:**
- Modify: `docs/knowledge/copia-database-drupal.md` («Come funziona oggi»: l'import legge dalla
  copia; il passo 1 con `status = 1` vale per WordPress, l'import non filtra e perché; lock della
  ricarica)
- Modify: `docs/howto/copia-database-drupal.md` (Laravel in locale verso il tunnel:
  `DB_SARDEGNASENTIERI_HOST=host.docker.internal`, `DB_SARDEGNASENTIERI_PORT=3307`; il lock e cosa
  fare se resta dopo una ricarica fallita; il comando di confronto)
- Modify: `.claude/rules/import-sardegna-sentieri.md` (trappole: niente `status = 1`, date in PHP e
  non in SQL, URL con `rawurlencode` per segmento)
- Modify: `.env-example` (le due variabili commentate, per il locale)

- [ ] **Step 1:** scrivere le modifiche.
- [ ] **Step 2: Commit** — `docs(oc:8900): import dalla copia del DB Drupal`
