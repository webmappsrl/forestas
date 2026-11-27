> Ticket: oc:8999
> Stato: seguito, con 3 divergenze annotate in notes.md; i Task 6 e 7 si fanno dopo il merge

# Bozze di Drupal fuori dall'import — piano

> **Per chi esegue:** sub-skill `superpowers:executing-plans`. I passi usano le caselle (`- [ ]`).
> **Nessun commit durante l'esecuzione:** le righe «Commit» sono il messaggio da usare dopo, il primo
> commit lo fa il dev.

**Obiettivo:** l'import da Sardegna Sentieri salta i nodi e le traduzioni in bozza, l'indice delle
tracce perde le voci che non corrispondono più a una traccia, e su dev e UAT i pubblicati prendono
l'indirizzo pulito.

**Architettura:** il filtro dei nodi sta nella query di `changedList()`; quello delle traduzioni in
un passo che pulisce il record appena letto, prima di ogni altro uso. La pulizia dell'indice è un job
di forestas accodato accanto al reindex di fine import (`CalculateNearbyTracksJob::regenerateOutputs()`),
con le chiamate a Elasticsearch in una classe propria, fingibile nei test come `EnteSearch`.

**Stack:** Laravel 12, Pest, Postgres (test su `forestas_testing`), SQLite in memoria per la copia
Drupal finta, Elasticsearch 8 (`Elastic\Elasticsearch\Client`), WP-CLI di `wp-package` 0.3.0.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Identificatori in inglese; commenti, docblock, nomi dei test Pest e documenti in italiano.
- Niente modifiche a `wm-package/` né allo schedule di `routes/console.php` (solo il suo commento).
- Prima di lanciare test: `phpunit.xml` imposta `DB_DATABASE=forestas_testing`, il container
  `php-forestas` non ha `DB_DATABASE` nell'ambiente, il DB `forestas_testing` esiste (verificato il
  26/11). Se uno dei tre non vale più, non lanciare e chiedi. `.env.testing` manca: tocca solo i test
  che firmano token JWT.
- I test girano con `docker exec php-forestas vendor/bin/pest --filter=<nome>`.

## Punti da guardare in review

1. Un record senza la chiave `status` nelle traduzioni (i record scritti a mano dei test esistenti)
   vale come pubblicato: test in Task 2.
2. Un nodo nato in inglese (`langcode` originale `en`) con la traduzione italiana in bozza: resta
   l'inglese originale, sparisce l'italiano. Test in Task 2.
3. La pulizia legge prima gli id dell'indice e poi quelli del DB: una traccia creata in mezzo non
   viene cancellata. Ordine fissato in Task 3, passo 3.
4. Indice e DB già coincidenti: nessuna chiamata di cancellazione. Test in Task 3.
5. La pulizia che fallisce finisce nel log `import` e non ferma il reindex: job separato, test in
   Task 3.

---

### Task 1: nodi in bozza fuori dagli elenchi — [R1](overview.md#r1)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-nodi-in-bozza-fuori-dagli-elenchi)

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (`changedList()`, docblock
  della classe)
- Test: `tests/Feature/Import/DraftNodesSourceTest.php` (nuovo)

**Interfacce:**
- Produce: `getTrackList()`, `getPoiList()`, `getEnteList()` con le stesse firme, senza i nodi con
  `status = 0` sulla riga `default_langcode = 1`.

- [x] **Passo 1: test che fallisce.** In `beforeEach` la connessione `sardegnasentieri` diventa SQLite
  in memoria (`config([...])`, `DB::purge('sardegnasentieri')`) e si crea `node_field_data` con
  `nid`, `type`, `langcode`, `default_langcode`, `status`, `changed`. Righe:

  | nid | type | righe |
  |---|---|---|
  | 10 | sentiero | it originale pubblicato |
  | 11 | sentiero | it originale in bozza |
  | 12 | itinerario | it originale in bozza |
  | 13 | poi | it originale pubblicato |
  | 14 | poi | it originale in bozza |
  | 15 | ente_istituzione_societa | it originale pubblicato |
  | 16 | sentiero | it originale in bozza + en traduzione pubblicata |
  | 17 | sentiero | it originale pubblicato + en traduzione in bozza |

  Test `it('lascia fuori dagli elenchi i nodi in bozza nella lingua originale')`:
  `array_keys(getTrackList())` è `['10', '17']`, `getPoiList()` ha solo `'13'`, `getEnteList()` solo
  `'15'`. La sorgente si costruisce con un `DrupalRecordReader` finto (`Mockery::mock`), che qui non
  serve.
- [x] **Passo 2:** il test fallisce (escono anche 11, 12, 14, 16).
- [x] **Passo 3:** `changedList()` aggiunge `->where('status', 1)`. Nel docblock della classe la
  regola «nessun filtro su `status`» diventa: i nodi in bozza nella lingua originale e le traduzioni
  in bozza non si importano (oc:8999).
- [x] **Passo 4:** il test passa.
- [x] **Commit:** `feat(oc:8999): l'import salta i nodi in bozza di Drupal`

### Task 2: traduzioni in bozza fuori dal record — [R2](overview.md#r2)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-traduzioni-in-bozza-fuori-dal-record)

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (`record()`, `preload()`)
- Test: `tests/Feature/Import/DraftTranslationsSourceTest.php` (nuovo)

**Interfacce:**
- Produce: `private function withoutDraftTranslations(array $record): array`.

- [x] **Passo 1: test che falliscono**, con il lettore finto come in
  `tests/Feature/Import/RoadbookStagesSourceTest.php:17-41`. Record del sentiero 75: `it` originale
  `status` 1, `en` `status` 0, titoli «Sentiero» e «Trail», `field_roadbook` in `it` ed `en`,
  `field_galleria_media` solo in `en` (forma dell'elemento da `images()`/`mediaItems()`).
  - `it('non porta i testi di una traduzione in bozza')`: `name` senza `en`, `roadbook_stages` senza
    `en`.
  - `it('non prende immagini dall\'inglese in bozza')`: `galleria_immagini` vuota.
  - `it('porta la traduzione pubblicata')`: stesso record con `en` `status` 1 → `name['en']` è
    «Trail» e `galleria_immagini` ha un elemento.
  - `it('tiene l\'originale inglese e toglie l\'italiano in bozza')`: `langcode` `en` originale
    `status` 1, `it` `status` 0 → `name['it']` è il titolo inglese (la chiave `it` porta l'originale,
    vedi `languages()`) e nessun testo italiano.
- [x] **Passo 2:** falliscono i primi due e l'ultimo.
- [x] **Passo 3:** `withoutDraftTranslations()` toglie da `translations` e da ogni
  `fields[<campo>]` le lingue diverse da `$record['langcode']` con `(int) ($translation['status'] ?? 1) === 0`.
  Si applica in `record()` prima di metterlo in `$this->records`, e in `preload()` a ogni record
  letto: dopo, tutti i metodi vedono il record già pulito.
- [x] **Passo 4:** passano i quattro test e `RoadbookStagesSourceTest`, i cui record non hanno
  `status`.
- [x] **Commit:** `feat(oc:8999): l'import salta le traduzioni in bozza`

### Task 3: voci in più tolte dall'indice delle tracce — [R3](overview.md#r3)

**File:**
- Crea: `app/Search/TrackSearchIndex.php`
- Crea: `app/Jobs/Import/PruneStaleTrackSearchDocumentsJob.php`
- Modifica: `app/Jobs/Import/CalculateNearbyTracksJob.php` (`regenerateOutputs()` e docblock)
- Test: `tests/Feature/Import/PruneStaleTrackSearchDocumentsJobTest.php` (nuovo)

**Interfacce:**
- Produce: `TrackSearchIndex::documentIds(): list<int>` (tutti gli id dell'indice delle tracce),
  `TrackSearchIndex::delete(list<int> $ids): void`.
- Produce: `PruneStaleTrackSearchDocumentsJob` (`ShouldQueue`, coda
  `ImportSardegnaSentieriTrackJob::QUEUE`), `handle(TrackSearchIndex $index): void`,
  `failed(Throwable $e): void`.

- [x] **Passo 1: test che falliscono.** `TrackSearchIndex` finto con
  `app()->instance(TrackSearchIndex::class, new class(...) extends TrackSearchIndex {...})`, come
  `tests/Feature/Entes/EnteSearchTest.php:26-34`: restituisce id dati e registra quelli cancellati.
  Le tracce con `EcTrack::factory()->createQuietly(['osmid' => null])` (`Wm\WmPackage\Models\EcTrack`).
  - `it('cancella le voci che non corrispondono a nessuna traccia')`: due tracce (id `a`, `b`),
    indice `[a, b, 9998, 9999]` → cancellati `[9998, 9999]`.
  - `it('non cancella nulla se indice e tracce coincidono')`: indice `[a, b]` → `delete()` mai
    chiamato.
  - `it('accoda la pulizia insieme al reindex di fine import')`: `Queue::fake()`,
    `CalculateNearbyTracksJob::regenerateOutputs()` → `PruneStaleTrackSearchDocumentsJob` accodato su
    `ImportSardegnaSentieriTrackJob::QUEUE`.
  - `it('scrive nel log dell\'import se la pulizia fallisce')`: `failed(new RuntimeException('x'))`
    scrive un errore sul canale `import` (`Log::shouldReceive('channel')->with('import')`).
- [x] **Passo 2:** falliscono (classi inesistenti).
- [x] **Passo 3:** `PruneStaleTrackSearchDocumentsJob::handle()` legge **prima**
  `$index->documentIds()`, **poi** gli id delle tracce di `config('wm-package.ec_track_model')`;
  cancella la differenza se non è vuota e scrive sul canale `import` quante voci ha tolto.
  `failed()` scrive l'errore sullo stesso canale. `regenerateOutputs()` accoda il job dopo
  `RegenerateTaxonomyWhereOutputsJob`, sulla stessa coda: l'ordine fra i due non conta, perché il
  reindex scrive solo id che esistono.
- [x] **Passo 4:** `TrackSearchIndex` usa `app(Client::class)` e come indice l'alias
  `searchableAs()` del modello di `config('wm-package.ec_track_model')`. `documentIds()`: API scroll,
  `_source` false, 1000 per pagina, `clearScroll` alla fine. `delete()`: `deleteByQuery` con la query
  `ids` e `refresh` true.
- [x] **Passo 5:** passano i quattro test.
- [x] **Commit:** `feat(oc:8999): dopo l'import si tolgono dall'indice le tracce che non esistono più`

### Task 4: i testi dicono come funziona ora — [R5](overview.md#r5)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-i-testi-dicono-come-funziona-ora)

**File:**
- Modifica: `.claude/rules/import-sardegna-sentieri.md` (riga della regola di oc:8900, 105-107)
- Modifica: `docs/knowledge/copia-database-drupal.md` (righe 29, 93-95, 266, 551-553, 601)
- Modifica: `routes/console.php` (solo il commento dello schedule, righe 22-31)

- [x] **Passo 1:** la regola di oc:8900 si sostituisce con: le query dell'import filtrano
  `status = 1` sulla lingua originale e il record perde le traduzioni in bozza; togliere il filtro
  riporta le bozze nello shard (oc:8999).
- [x] **Passo 2:** nella pagina sulla copia, i tre punti che dicono «l'import non filtra»
  (93-95, 266, 551-553) dicono che salta nodi e traduzioni in bozza; le righe 29 e 601 dicono che in
  produzione non è previsto import (oc:8999). La pulizia dell'indice dopo l'import va dove la pagina
  descrive la fine dell'import, o se non c'è in `docs/knowledge/import-asincrono-e-anomalie.md`.
- [x] **Passo 3:** nel commento di `routes/console.php` resta la descrizione di oc:8489; si aggiunge
  che in produzione non è previsto nessun import (oc:8999) e che al passaggio lo schedule orario va
  spento.
- [x] **Passo 4:** `grep -rn "nessun filtro su \`status\`\|Non filtrare \`status = 1\`\|l'import non filtra\|oggi non lo fa" app .claude docs/knowledge`
  non trova nulla.
- [x] **Commit:** `docs(oc:8999): l'import salta le bozze e pulisce l'indice; nessun import in produzione`

### Task 5: documenti rimasti indietro — [R6](overview.md#r6)

**File:**
- Modifica: `docs/howto/messa-in-opera-wordpress-dev.md` (riga 129 e seguenti, rilascio di oc:9018)
- Modifica: `docs/features/8947-…/notes.md` («Esito», «Follow-up»), `docs/features/8947-…/plan.md`
  (Task 8, passi 2 e 3; «Architettura»)

- [x] **Passo 1:** la procedura non dice più che restano 170 righe con chiave per id: il 25/11 erano
  già 0 (`wmwp_detail_history_forget_ids( "track" )` ne ha tolte 0), il passo non serve più.
- [x] **Passo 2:** le note di oc:8947 dicono che l'host di dev è aggiornato dal 06/11/2026; nel piano
  i passi 2 e 3 del Task 8 sono spuntati, e l'«Architettura» dice che su dev i container nascono da
  `compose.yml`.
- [x] **Commit:** `docs(oc:8999): procedura di WordPress su dev e documenti di oc:8947 allineati`

### Task 6: messa in opera su dev — [R1](overview.md#r1), [R3](overview.md#r3), [R4](overview.md#r4)

La procedura completa, con i comandi provati su UAT, è in
[messa-in-opera-wordpress-uat.md](../../howto/messa-in-opera-wordpress-uat.md#bozze-fuori-dallimport-oc8999),
anche per il dev. L'import si può lanciare a mano subito dopo il deploy, senza aspettare le 06:00
(Rubens, 26/11).

Dopo il merge in `develop` (deploy automatico) e l'import delle 06:00 del giorno dopo. Solo letture,
tranne i `forget` e la ricostruzione dell'indice dei dettagli. L'esito va nel ticket, non nel repo:
un secondo merge per oc:8999 non ci deve essere.

- [ ] **Passo 1:** il log `import` del dev ha la riga della pulizia dell'indice.
- [ ] **Passo 2:** nessuna traccia né POI con un nid in bozza nella copia (`status = 0`,
  `default_langcode = 1`); documenti in `ec_tracks` = tracce nel DB; nessuna bozza in `pois.geojson`.
- [ ] **Passo 3:** quando `/sentiero/monte-cresia-t-513a/`, `/sentiero/nolau-t-513/` e
  `/punti-di-interesse/bivacco-fisso-lungo-il-sentiero-d-105c/` rispondono 404 (bozze sparite
  dall'indice dei dettagli; per il POI anche `pois.geojson` rigenerato), sul WordPress del dev:
  `wp wmwp detail-paths forget` su `sentiero/monte-cresia-t-513a`, `trail/monte-cresia-t-513a`,
  `sentiero/nolau-t-513`, `trail/nolau-t-513`,
  `punti-di-interesse/bivacco-fisso-lungo-il-sentiero-d-105c`; poi `wp eval 'wmwp_detail_index( true );'`.
- [ ] **Passo 4:** i tre indirizzi puliti, in italiano e in inglese, mostrano il pubblicato (nid
  699, 694, 3111); i `-2` rispondono 301 verso di loro; `wp wmwp detail-paths list` lo conferma.

### Task 7: messa in opera su UAT — [R1](overview.md#r1), [R3](overview.md#r3), [R4](overview.md#r4)

Procedura in
[messa-in-opera-wordpress-uat.md](../../howto/messa-in-opera-wordpress-uat.md#bozze-fuori-dallimport-oc8999).

Dopo il merge di `develop` in `main`, quando Rubens lo segnala, e l'import delle 06:00 del giorno
dopo. Passi del Task 6, con due differenze: Monte Cresia su UAT ha già l'indirizzo pulito, quindi i
`forget` sono solo `sentiero/nolau-t-513`, `trail/nolau-t-513` e
`punti-di-interesse/bivacco-fisso-lungo-il-sentiero-d-105c`; sul Catasto si guarda l'esito delle 2
anomalie `codice_gia_assegnato` e di un eventuale `registro_link_orfano` per ZNUT511.
