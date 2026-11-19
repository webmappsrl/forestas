> Ticket: oc:8981

# Avvisi dei sentieri — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** data automatica dell'avviso su `EcTrack` (wm-package), campi dell'avviso spostati e rinominati nel tab `Status` della Resource di forestas, API pubblica `GET /api/v1/avvisi` per il sito.

**Architecture:** la regola della data è un metodo del modello `EcTrack` del package, chiamato da `static::saving()` e dall'upload Excel prima del `saveQuietly()`; due helper statici normalizzano flag e messaggio e li riusa anche l'API di forestas. In forestas la Resource filtra i due campi dal tab Info del package e li rimette nel tab `Status`; il controller dell'API legge le tracce con l'avviso e le filtra e ordina in PHP (gli avvisi attivi sono pochi).

**Tech Stack:** Laravel 12, Nova 5, Pest, PostgreSQL jsonb, `kongulov/nova-tab-translatable`.

**Spec:** [overview.md](overview.md) (forestas) e
[wm-package/docs/features/8981-avvisi-dei-sentieri/overview.md](../../../wm-package/docs/features/8981-avvisi-dei-sentieri/overview.md).

## Global Constraints

- Identificatori in inglese; commenti, docblock e documentazione in italiano.
- Nessun commit, `git add` o push durante l'esecuzione: i commit sotto sono istruzioni per il dev.
- Branch `feature/oc-8981-avvisi-dei-sentieri` in forestas **e** in `wm-package/`, prima di toccare file.
- Test: prima di lanciare la suite di forestas verificare l'isolamento (`.env.testing` → `forestas_testing`, DB esistente) come da `.claude/rules/test.md`; la suite del package usa il DB `wm_package`. Se l'isolamento non è verificabile, non lanciare e chiedere.
- Chiave della data: `properties->not_accessible_updated_at`, stringa ISO 8601 **in UTC** (`now('UTC')->toIso8601String()`).
- Lingua del confronto sul testo: `it`.
- Testi base delle label in inglese, traduzione in `it` (forestas: `lang/it.json`; package: `resources/lang/{de,en,es,fr,it}.json`).
- Rotta `GET /api/v1/avvisi`: pubblica, nessun middleware di autenticazione né throttle (come `api/ec/track`).
- Dopo aver modificato il modello del package: `docker restart horizon-forestas` prima di provare a mano.

## Review Focus

1. Messaggio salvato come stringa piatta dall'upload Excel e poi risalvato da Nova come `{"it": …}` con lo stesso testo → la data **non** cambia (Task 1, test `ignores shape change`).
2. `not_accessible` scritto come `"1"`, `1`, `"true"`, `"false"`, `"0"` → acceso/spento come ci si aspetta (Task 1, dataset su `isNotAccessibleOn`).
3. Sottoclasse `App\Models\EcTrack` → l'evento `saving` scatta anche lì (Task 1, test in forestas).
4. Avviso acceso con messaggio vuoto o solo inglese → assente dall'API (Task 5).
5. Traccia `deleted_from_source` con avviso acceso → assente dall'API (Task 5).

---

## Repo wm-package

### Task 1: regola della data sul modello

**Files:**
- Modify: `wm-package/src/Models/EcTrack.php` (`booted()`, nuovi metodi)
- Test: `wm-package/tests/Unit/Models/EcTrackNotAccessibleUpdatedAtTest.php`
- Test: `tests/Feature/EcTrack/NotAccessibleUpdatedAtOnForestasModelTest.php` (forestas)

**Interfaces — Produces:**
- `public const NOT_ACCESSIBLE_UPDATED_AT = 'not_accessible_updated_at';`
- `public static function isNotAccessibleOn(mixed $value): bool` — `true` per `true`, `1`, `"1"`, `"true"` (case-insensitive); `false` per tutto il resto (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`).
- `public static function notAccessibleMessageText(mixed $message, string $locale = 'it'): ?string` — stringa piatta → la stringa stessa (vale come `it`); array → `$message[$locale]`; testo trimmato, `''` → `null`.
- `public function refreshNotAccessibleUpdatedAt(): void` — confronta `getOriginal('properties')` (decodificata se stringa JSON) con `$this->properties`; scrive la data se `isNotAccessibleOn` passa da `false` a `true`, oppure se `notAccessibleMessageText(…, 'it')` cambia; altrimenti non tocca nulla. Su un modello nuovo l'originale è vuoto.
- In `booted()`: `static::saving(fn (self $model) => $model->refreshNotAccessibleUpdatedAt());` (non nell'observer: `EcTrack::observe` è legato alla classe del package e non scatta per `App\Models\EcTrack`).

- [ ] **Step 1: test che falliscono** (package, `Carbon::setTestNow('2026-11-17 10:00:00', 'UTC')`):
  - `writes the date when not_accessible is switched on` → `properties['not_accessible_updated_at'] === '2026-11-17T10:00:00+00:00'`
  - `writes the date when the italian message changes` (flag acceso da prima, nuova data dopo `setTestNow` avanzato)
  - `keeps the date when switched off` / `keeps the date when only english changes` / `keeps the date on unrelated changes` (es. `name`)
  - `ignores shape change`: originale `'Frana'` stringa, nuovo `['it' => 'Frana', 'en' => 'Landslide']` → data invariata
  - dataset su `isNotAccessibleOn`: `[true, 1, '1', 'true', 'TRUE'] → true`, `[false, 0, '0', 'false', null, ''] → false`
- [ ] **Step 2:** `cd wm-package && vendor/bin/pest tests/Unit/Models/EcTrackNotAccessibleUpdatedAtTest.php` → FAIL (metodi assenti).
- [ ] **Step 3:** implementare i metodi e la registrazione in `booted()`, con docblock che dice che `saveQuietly()` e gli update di massa non scrivono la data.
- [ ] **Step 4:** stesso comando → PASS.
- [ ] **Step 5:** test in forestas `it writes the date on App\Models\EcTrack` (crea con `App\Models\EcTrack`, accende, salva con `save()`, verifica la chiave). Run: `docker exec php-forestas vendor/bin/pest tests/Feature/EcTrack/NotAccessibleUpdatedAtOnForestasModelTest.php` → PASS.

### Task 2: upload Excel

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-upload-excel)

**Files:**
- Modify: `wm-package/src/Imports/EcTrackFromSpreadsheet.php:44-50`
- Test: `wm-package/tests/Unit/Imports/EcTrackFromSpreadsheetNoticeDateTest.php`

**Interfaces — Consumes:** `EcTrack::refreshNotAccessibleUpdatedAt()` (Task 1).

- [ ] **Step 1: test** `writes the date when a spreadsheet row switches the notice on`: traccia esistente senza avviso, riga `['id' => $id, 'not_accessible' => '1', 'not_accessible_message' => 'Frana']` passata all'importer → data scritta; seconda riga identica → data invariata.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** dopo `(new EcTrackRowProcessor)->apply($model, $data);`, se `$model` ha `refreshNotAccessibleUpdatedAt`, chiamarlo prima del ramo `saveQuietly()` / `save()`.
- [ ] **Step 4:** run → PASS.

### Task 3: campo in sola lettura nella Resource del package

**Files:**
- Modify: `wm-package/src/Nova/EcTrack.php:268-271` (dopo il `NovaTabTranslatable` del messaggio)
- Modify: `wm-package/resources/lang/{de,en,es,fr,it}.json` — chiave `"Not Accessible Updated At"`: it «Data dell'avviso», en «Notice date», de «Datum des Hinweises», es «Fecha del aviso», fr «Date de l'avis»

**Interfaces — Produces:** `Text::make(__('Not Accessible Updated At'), 'properties->not_accessible_updated_at')->readonly()->onlyOnDetail()` — attributo usato da forestas nel Task 4.

- [ ] **Step 1:** aggiungere il campo e le cinque traduzioni.
- [ ] **Step 2:** `cd wm-package && vendor/bin/pest` (suite intera) → PASS.
- [ ] **Step 3: commit nel package** (istruzione per il dev):
  ```bash
  cd wm-package
  git add src/Models/EcTrack.php src/Imports/EcTrackFromSpreadsheet.php src/Nova/EcTrack.php resources/lang tests docs/features/8981-avvisi-dei-sentieri
  git commit -m "feat(oc:8981): data dell'avviso su EcTrack"
  ```

---

## Repo forestas

### Task 4: campi dell'avviso nel tab Status

**Files:**
- Modify: `app/Nova/EcTrack.php` — nuovo override `getInfoTabFields()`, `taxonomyTabExtraFields()` (righe 204-217), nuovo metodo privato `noticeFields()`
- Modify: `lang/it.json`
- Test: `tests/Feature/Nova/NoticeFieldsTest.php`

**Interfaces — Consumes:** attributi `properties->not_accessible`, `properties->not_accessible_message`, `properties->not_accessible_updated_at` (Task 3).

- [ ] **Step 1: test** (helper come `statusTabFields()` di `WalkabilityTabTest.php`; per i campi dentro `NovaTabTranslatable` leggere `originalFields`):
  - `moves the notice fields out of the Info tab`: nessuno dei tre attributi ha `meta()['tab']['name'] === 'Info'`
  - `puts the notice after walkability and before history in the Status tab`: indice di `properties->not_accessible` > indice di `walkability_note` e < indice del primo campo dello storico
  - `keeps the notice fields with the trail registry disabled`: `config(['wm-package.features.trail_registry.enabled' => false])` → i tre attributi sono nel tab `Status`
  - `labels the fields as notice`: nomi `__('Notice')`, `__('Notice message')`
- [ ] **Step 2:** run `docker exec php-forestas vendor/bin/pest tests/Feature/Nova/NoticeFieldsTest.php` → FAIL.
- [ ] **Step 3:** implementare:
  - `getInfoTabFields()`: `parent::getInfoTabFields()` senza i campi il cui `attribute` (o, per `NovaTabTranslatable`, uno degli `originalFields`) è fra i tre attributi; commento: si filtra per non copiare il tab del package.
  - `noticeFields(): array`: `Heading` «Notice», `Boolean::make(__('Notice'), 'properties->not_accessible')`, `NovaTabTranslatable::make([Textarea::make(__('Notice message'), 'properties->not_accessible_message')->help(__('Without a message the notice is not shown on the website.'))])`, `Text::make(__('Notice date'), 'properties->not_accessible_updated_at')->readonly()->onlyOnDetail()`.
  - `STATUS_TAB => [...($fromPackage[self::STATUS_TAB] ?? []), ...$this->noticeFields(), ...$this->historyBlock()]`.
  - `lang/it.json`: «Notice» → «Avviso», «Notice message» → «Messaggio Avviso», «Notice date» → «Data avviso», help → «Senza messaggio l'avviso non compare sul sito.».
- [ ] **Step 4:** run → PASS; rilanciare anche `tests/Feature/Nova/WalkabilityTabTest.php` → PASS.

### Task 5: API pubblica degli avvisi

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-api-pubblica-degli-avvisi)

**Files:**
- Create: `app/Http/Controllers/Api/NoticeController.php`
- Modify: `routes/api.php` (dopo il gruppo `v1/entes`)
- Test: `tests/Feature/Notices/NoticeIndexTest.php`

**Interfaces — Consumes:** `EcTrack::isNotAccessibleOn()`, `EcTrack::notAccessibleMessageText()`, `EcTrack::NOT_ACCESSIBLE_UPDATED_AT` (Task 1).
**Produces:** `Route::get('v1/avvisi', [NoticeController::class, 'index'])->name('notices.index');` → `NoticeController::index(): JsonResponse`.

- [ ] **Step 1: test**:
  - `is public`: `getJson('/api/v1/avvisi')` senza header → 200
  - `returns the agreed shape`: una traccia `ref` «T 521», `name` it/en, messaggio it/en, data → `data.0` ha esattamente `track_id`, `ref`, `name`, `message`, `published_at`; c'è `generated_at`
  - `orders by date desc with undated last`: tre tracce (10/11, 15/11, senza data) → ordine 15/11, 10/11, senza data
  - `excludes switched off, empty message, english only, deleted from source`: quattro tracce, `data` vuoto
  - `wraps a plain string message as italian`: messaggio `'Frana'` → `message === ['it' => 'Frana']`
  (crea le tracce con `createQuietly` e la data già in `properties`, così i test non dipendono dall'evento.)
- [ ] **Step 2:** run → FAIL (404).
- [ ] **Step 3:** controller: `App\Models\EcTrack::query()->select('id', 'name')->selectRaw("properties->'ref' AS ref, properties->'not_accessible' AS flag, properties->'not_accessible_message' AS message, properties->>'not_accessible_updated_at' AS published_at")->whereRaw("properties ? 'not_accessible'")->whereRaw(<stesso filtro di EnteController::notDeleted>)`, poi in PHP: tieni le righe con `isNotAccessibleOn(flag)` e `notAccessibleMessageText(message, 'it') !== null`, messaggio stringa → `['it' => …]`, `name` con `getTranslations('name')`, ordina per `published_at` desc con `null` in fondo. Risposta `['data' => …, 'generated_at' => now()->toIso8601String()]`. Docblock: pubblica come `api/ec/track`, dati già esposti nel JSON del dettaglio.
- [ ] **Step 4:** run → PASS.

### Task 6: puntatore, documentazione, commit

**Files:**
- Create: `docs/knowledge/8981-avvisi-dei-sentieri.md`
- Modify: `CLAUDE.md` (una riga sotto `## Conoscenza`)
- Create: `docs/features/8981-avvisi-dei-sentieri/notes.md`

- [ ] **Step 1:** pagina di conoscenza con i punti dell'overview (perdita notturna su UAT, `published_at` = ultimo aggiornamento del testo italiano, `track_id` che cambia ogni notte su UAT, errore dell'API ≠ elenco vuoto, per l'app l'avviso resta `not_accessible`, contratto JSON, limite dell'import GeoHub).
- [ ] **Step 2:** `docker exec php-forestas vendor/bin/pest` (suite intera, dopo la verifica d'isolamento) e `docker exec php-forestas vendor/bin/phpstan analyse` → nessun errore nuovo.
- [ ] **Step 3: commit** (istruzioni per il dev, dopo l'approvazione del review-gate):
  ```bash
  git add wm-package
  git commit -m "chore(oc:8981): puntatore di wm-package con la data dell'avviso"
  git add app routes lang tests docs CLAUDE.md
  git commit -m "feat(oc:8981): avvisi dei sentieri nel tab Status e API pubblica /api/v1/avvisi"
  ```
