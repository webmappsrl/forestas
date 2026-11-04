> Ticket: oc:8910

# Classificazioni e inclusività su Forestas — piano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** portare su Forestas le undici vocabolari decise nella call del 29/10, far scrivere
all'import le relazioni che oggi si perdono, e aggiungere al sentiero i valori di inclusività del
Catasto sardo.

**Architecture:** i vocabolari e i termini nascono da un comando rilanciabile, che l'import
chiama da sé; l'import legge la gerarchia del vocabolario Drupal `categorie_fruibilita_sentieri`
e scrive le relazioni, collassando le tre scale di accessibilità in una più le categorie di
disabilità. L'inclusività è di Forestas: tre colonne con il prefisso dello shard, due testi in
`properties->forestas`, punteggio e classe derivati e depositati solo nell'indice.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5.7.6, Elasticsearch 8, Redis,
Horizon, MySQL 8 per la copia Drupal, Pest.

**Spec:** [overview.md](overview.md) e [notes.md](notes.md) — le note contengono le decisioni
prese e ciò che è stato escluso con il suo perché.

**Dipendenza:** questo piano parte **dopo** che
[il piano del package](../../../wm-package/docs/features/8910-modello-dati-unico-classificazioni-accessibilita/plan.md)
è stato merged e il puntatore del submodule è stato bumpato. Il Task 1 è proprio quello.

## Global Constraints

- Documentazione, commenti e messaggi di commit **in italiano**; identificatori in inglese.
- **Ogni colonna che Forestas aggiunge a una tabella del package porta `forestas_` come
  prefisso**, altrimenti il giorno che il package aggiunge una colonna omonima il suo
  `ALTER TABLE` fallisce al `migrate` del deploy.
- Le Resource Nova del progetto **estendono** quelle del package, non le duplicano.
- Le righe di tassonomia si creano con `insertOrIgnore` più rilettura (oc:8607).
- Dopo aver modificato un job, **Horizon va riavviato**: `docker restart horizon-forestas`.
- L'import è asincrono: il comando accoda e ritorna, e quando termina il database è ancora vuoto.
  Ciò che dipende dai dati va agganciato alla fine dei job, non alla fine del comando.
- Prima di lanciare la suite va verificato l'isolamento del database: `.claude/rules/test.md`.
- La copia Drupal non c'è per forza: va verificato che `mysql-sardegnasentieri-dump` sia acceso,
  oppure che sia aperto il tunnel sulla 3307 verso UAT.
- **Nessun commit, push o branch viene eseguito da chi implementa.**

## Review Focus

- **Un sentiero reimportato due volte**: le relazioni di un vocabolario devono sostituirsi, non
  accumularsi. Test nel Task 4.
- **Un sentiero con due gradi nella stessa scala Drupal** (ce ne sono 2 su 755): deve produrre
  un'anomalia e non far morire il job. Test nel Task 4.
- **Una relazione che punta a una radice invece che a un figlio** (67 righe in Drupal): è l'attività
  senza grado, non una difficoltà. Test nel Task 4.
- **Un punteggio incompleto**, con uno o due interi su tre compilati: la somma parziale non deve
  produrre una classe come se il dato fosse completo. Test nel Task 6.
- **Il reset notturno**: le relazioni di tracce e POI devono sparire, i termini no. Test nel Task 3.

---

### Task 1: bump del submodule e migration pubblicate

**Files:**
- Modify: `wm-package` (puntatore del submodule)
- Create: `database/migrations/<data>_create_taxonomy_terms_table.php` (pubblicata)
- Create: `database/migrations/<data>_create_taxonomy_termables_table.php` (pubblicata)

- [ ] **Step 1: Bumpare il submodule al commit merged del package**

L'hash si prende con `git -C wm-package rev-parse HEAD` **dopo** il merge: mai scriverlo a
memoria — un hash inventato ha già rotto develop (oc:8567).

- [ ] **Step 2: Pubblicare e lanciare le migration**

Run: `docker exec -it php-forestas php artisan wm-package:publish-missing-migrations --dry-run`,
poi senza `--dry-run`, poi `php artisan migrate`.
Esito atteso: `taxonomy_terms` e `taxonomy_termables` esistono.

- [ ] **Step 3: Commit** *(istruzione per il dev)*

```bash
git add wm-package database/migrations
git commit -m "feat(oc:8910): bump wm-package e migration del modello dei termini"
```

---

### Task 2: il comando che crea vocabolari e termini

**Files:**
- Create: `app/Console/Commands/ConfigureTaxonomyTermsCommand.php`
- Create: `database/seeders/TaxonomyTermSeeder.php`
- Test: `tests/Feature/Taxonomy/ConfigureTaxonomyTermsCommandTest.php`

**Interfaces:**
- Produces: `sardegnasentieri:configure-taxonomy-terms [--fresh] [--dry-run]`.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('crea le undici vocabolari e i loro termini', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();

    expect(TaxonomyTerm::vocabularies()->count())->toBe(11)
        ->and(TaxonomyTerm::where('identifier', 'accessibility-difficulty')->first()->single_choice)->toBeTrue()
        ->and(TaxonomyTerm::where('identifier', 'accepted-disabilities')->first()->single_choice)->toBeFalse()
        ->and(TaxonomyTerm::where('identifier', 'mtb-difficulty')->first()->children)->toHaveCount(5);
});

it('è rilanciabile e non duplica niente', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    expect(TaxonomyTerm::count())->toBe(TaxonomyTerm::distinct('identifier')->count('identifier'));
});

it('non tocca un nome cambiato da Nova né un vocabolario creata a mano', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    TaxonomyTerm::where('identifier', 'hiking-difficulty')->update(['name' => ['it' => 'Rinominata']]);
    $mia = TaxonomyTerm::create(['identifier' => 'custom-family', 'name' => ['it' => 'Mia']]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    expect(TaxonomyTerm::where('identifier', 'hiking-difficulty')->first()->getTranslations('name')['it'])->toBe('Rinominata')
        ->and(TaxonomyTerm::find($mia->id))->not->toBeNull();
});

it('con --fresh riparte da vuoto', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $mia = TaxonomyTerm::create(['identifier' => 'custom-family', 'name' => ['it' => 'Mia']]);

    $this->artisan('sardegnasentieri:configure-taxonomy-terms --fresh');

    expect(TaxonomyTerm::find($mia->id))->toBeNull()
        ->and(TaxonomyTerm::vocabularies()->count())->toBe(11);
});

it('con --dry-run non scrive niente', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms --dry-run')->assertSuccessful();
    expect(TaxonomyTerm::count())->toBe(0);
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Run: `docker exec -it php-forestas vendor/bin/pest tests/Feature/Taxonomy/ConfigureTaxonomyTermsCommandTest.php`
Esito atteso: FAIL, comando inesistente.

- [ ] **Step 3: Scrivere l'elenco dei vocabolari**

Nel comando, come array: le undici vocabolari della tabella in `overview.md`, con `identifier`,
nome italiano, `icon`, `single_choice`, e i termini con il loro `sort_order` crescente secondo la
scala. I termini che vengono da Drupal portano `identifier` nella forma
`sardegnasentieri:tid:<tid>`; i tre delle disabilità accolte portano le icone
`txn-mobility-disability` e `txn-cognitive-disability`, e «Sensoriale» nasce senza icona.

I tid si leggono dalla copia Drupal, vocabolario `categorie_fruibilita_sentieri`, livello figlio.

- [ ] **Step 4: Implementare il comando**

Default: allinea per `identifier` con `insertOrIgnore` più rilettura, aggiorna nome e descrizione
delle righe di sistema, non cambia gli id, non tocca ciò che non è nel suo elenco. `--fresh`:
svuota `taxonomy_terms` e ricrea. `--dry-run`: stampa e non scrive.

- [ ] **Step 5: Il seeder che invoca il comando**

`TaxonomyTermSeeder` chiama il comando e basta: l'elenco vive in un posto solo.

- [ ] **Step 6: Lanciare i test e verificare che passino**

Esito atteso: PASS, 5 test.

- [ ] **Step 7: Commit** *(istruzione per il dev)*

```bash
git add app/Console/Commands/ConfigureTaxonomyTermsCommand.php database/seeders tests/Feature/Taxonomy
git commit -m "feat(oc:8910): comando che crea i vocabolari e i termini del Catasto"
```

---

### Task 3: il reset svuota le relazioni e non il vocabolario

**Files:**
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (`resetData()`, righe 798-841)
- Test: `tests/Feature/Import/ImportResetGuardsTest.php` (esistente, si aggiunge un caso)

- [ ] **Step 1: Scrivere il test che fallisce**

```php
it('il reset cancella le relazioni dei termini di tracce e POI ma non i termini', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    // lega un termine a un sentiero e uno a un layer
    $this->artisan('sardegnasentieri:import --reset --no-interaction');

    expect(DB::table('taxonomy_termables')->where('termable_type', EcTrack::class)->count())->toBe(0)
        ->and(TaxonomyTerm::vocabularies()->count())->toBe(11);
});
```

- [ ] **Step 2: Lanciare il test e verificare che fallisca**

Esito atteso: FAIL, le relazioni sopravvivono.

- [ ] **Step 3: Aggiungere la cancellazione in `resetData()`**

Accanto a quelle di `taxonomy_activityables` e `layerables`, con lo stesso filtro
`self::TRACK_AND_POI_MORPH_TYPES`. Il pivot polimorfo non ha una chiave esterna verso `ec_tracks`,
quindi il `CASCADE` non lo raggiunge, e dopo `RESTART IDENTITY` le righe vecchie si
attaccherebbero ai sentieri nuovi con lo stesso id — è già successo tre volte (oc:8827, oc:8513).
Il commento accanto alla riga lo dice.

- [ ] **Step 4: Agganciare il comando dei termini all'import**

Con `--reset` lo chiama con `--fresh`, altrimenti senza. Sul ramo orario azzerare i termini
romperebbe le relazioni dei sentieri non reimportati, che sono la maggior parte.

- [ ] **Step 5: Lanciare i test e verificare che passino**

Esito atteso: PASS. Poi `docker restart horizon-forestas`.

- [ ] **Step 6: Commit** *(istruzione per il dev)*

```bash
git add app/Console/Commands/ImportSardegnaSentieriCommand.php tests/Feature/Import/ImportResetGuardsTest.php
git commit -m "fix(oc:8910): il reset svuota le relazioni dei termini e non il vocabolario"
```

---

### Task 4: l'import scrive le relazioni

**Files:**
- Modify: `app/Services/Import/Source/DrupalRecordReader.php`
- Modify: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`
- Test: `tests/Feature/Import/SardegnaSentieriTaxonomyTermsTest.php`

**Interfaces:**
- Consumes: `TaxonomyTerm`, `sardegnasentieri:configure-taxonomy-terms`.
- Produces: sul DTO del sentiero, la lista dei tid di `field_frubilita` con il loro padre.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('scrive la difficoltà nel vocabolario giusta', function () {
    // nodo Drupal con tid 4909 (MTB_MC_trail_Grado2)
    expect($track->term('mtb-difficulty')->identifier)->toBe('sardegnasentieri:tid:4909');
});

it('collassa le tre scale di accessibilità in grado più categoria', function () {
    // nodo 3901: «Accessibile dis. Sensoriali → AE»
    expect($track->term('accessibility-difficulty')->identifier)->toBe('accessibility-difficulty-ae')
        ->and($track->terms('accepted-disabilities')->pluck('identifier')->all())->toBe(['disability-sensory']);
});

it('tratta una relazione a una radice come attività senza grado', function () {
    // nodo che punta a tid 4064 «Mountain Bike», radice
    expect($track->terms('mtb-difficulty'))->toBeEmpty()
        ->and($track->taxonomyActivities->pluck('identifier'))->toContain('mtb');
});

it('non accumula doppioni a un reimport', function () {
    importa($nodo); importa($nodo);
    expect($track->terms('mtb-difficulty'))->toHaveCount(1);
});

it('segnala come anomalia due gradi nella stesso vocabolario e non muore', function () {
    // i 2 nodi Drupal in questa condizione
    expect($anomalie)->toHaveCount(1)
        ->and($track->terms('mtb-difficulty'))->toHaveCount(1);
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Leggere la gerarchia da Drupal**

In `DrupalRecordReader`, la query su `node__field_frubilita` unita a `taxonomy_term__parent` per
sapere se il tid puntato è una radice o un figlio, e di quale radice. **Non** filtrare
`status = 1`. Le date si convertono in PHP, mai con `FROM_UNIXTIME`. Un dato che l'import non usa
ancora si prende dal record completo di `SardegnaSentieriDatabaseSource::record()`, non con una
query nuova.

- [ ] **Step 4: Scrivere le relazioni nel service**

Per ogni tid figlio, risolvere il termine per `identifier` `sardegnasentieri:tid:<tid>` e legarlo
al sentiero. Per le tre scale `Accessibili dis. *`, due relazioni: il grado nel vocabolario
`accessibility-difficulty` e la categoria in `accepted-disabilities`, con la mappa esplicita
delle tre scale verso le tre categorie — «sensoriale» accorpa udito e vista. Per un tid radice,
l'attività e nessun grado. Le relazioni di un vocabolario si **sostituiscono**. Se dopo il collasso
restano due gradi in un vocabolario `single_choice`, ne entra uno e l'altro diventa un'anomalia.

- [ ] **Step 5: Aggiungere «mobilità dolce» alle attività**

Non c'è fra le nove righe di `taxonomy_activities`. Dopo averla aggiunta va rilanciato
`sardegnasentieri:configure-layers`: un layer di tipologia senza attività viene saltato dal
ricalcolo e il filtro della lista EC Tracce nasce vuoto (oc:8827).

- [ ] **Step 6: Lanciare i test e verificare che passino**

Esito atteso: PASS, 5 test. Poi `docker restart horizon-forestas`.

- [ ] **Step 7: Verifica sui dati veri**

Run: `php artisan sardegnasentieri:import --reset`, attendere la fine dei job, poi contare i
relazioni per vocabolario.
Esito atteso: ordine di grandezza coerente con la sorgente — Trekking ~696, MTB ~103, mobilità
dolce ~55, ippovia ~15.

- [ ] **Step 8: Commit** *(istruzione per il dev)*

```bash
git add app/Services/Import tests/Feature/Import/SardegnaSentieriTaxonomyTermsTest.php
git commit -m "feat(oc:8910): l'import scrive le relazioni dei termini dalla gerarchia Drupal"
```

---

### Task 5: le colonne di Forestas su `ec_tracks`

**Files:**
- Create: `database/migrations/<data>_add_forestas_columns_to_ec_tracks.php`
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`
- Test: `tests/Feature/Import/ForestasColumnsTest.php`

- [ ] **Step 1: Scrivere il test che fallisce**

```php
it('scrive codice, data di rilievo e zona geografica in colonna', function () {
    expect($track->forestas_registry_code)->toBe('SE-001')
        ->and($track->forestas_survey_date)->not->toBeNull()
        ->and($track->forestas_geographic_area)->not->toBeNull();
});
```

- [ ] **Step 2: Lanciare il test e verificare che fallisca**

Esito atteso: FAIL, colonne inesistenti.

- [ ] **Step 3: Scrivere la migration**

Sei colonne nullable su `ec_tracks`: `forestas_registry_code` string indicizzata,
`forestas_survey_date` date, `forestas_geographic_area`, più le tre dell'inclusività
`forestas_inclusivity_physical`, `forestas_inclusivity_aids`, `forestas_inclusivity_digital`
smallInteger. Tutte con il prefisso dello shard.

**Nota su `forestas_geographic_area`:** in Drupal `field_zona_geografica` è una **lista** di
termini, non un valore singolo (`SardegnaSentieriDatabaseSource` lo legge con `references()` e in
Nova è un `KeyValue`). Se i sentieri con più di una zona esistono, la colonna scalare non li
rappresenta: **contare prima** sulla copia Drupal, e se il caso esiste farne un vocabolario di
termini invece che una colonna. Annotare l'esito in `notes.md`.

- [ ] **Step 4: Far scrivere le colonne all'import e aggiornare chi legge le chiavi JSON**

I punti che oggi leggono `properties->forestas.codice` e le altre due vanno aggiornati, filtri e
ordinamenti compresi.

- [ ] **Step 5: Ri-esporre le colonne nuove nel GeoJSON**

Il GeoJSON di una traccia espone `properties` piatto: le chiavi spostate in colonna sparirebbero
dall'API. `GeoJsonService::getModelAsGeojson()` del package ha già il punto previsto — il
commento «Merge accessibility columns (if present) into GeoJSON properties» seguito da uno
spread vuoto. Test: il GeoJSON di una traccia contiene `forestas_registry_code` e le tre
dell'inclusività con i valori delle colonne.

- [ ] **Step 6: Lanciare i test e verificare che passino**

Esito atteso: PASS.

- [ ] **Step 7: Commit** *(istruzione per il dev)*

```bash
git add database/migrations app/Services/Import tests/Feature/Import/ForestasColumnsTest.php
git commit -m "feat(oc:8910): colonne di Forestas su ec_tracks al posto delle chiavi JSON"
```

---

### Task 6: inclusività, punteggio e classi

**Files:**
- Modify: `config/forestas.php`
- Create: `app/Support/InclusivityScore.php`
- Modify: `app/Models/EcTrack.php` o l'override dello shard (`toSearchableArray()`)
- Test: `tests/Feature/Inclusivity/InclusivityScoreTest.php`

**Interfaces:**
- Produces: `InclusivityScore::for(EcTrack $track): ?array` → `['score' => int, 'class' => string]`
  oppure `null` se manca anche uno solo dei tre valori.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('somma i tre interi e assegna la classe', function () {
    expect(InclusivityScore::for($track(55, 23, 22)))->toBe(['score' => 100, 'class' => 'A1'])
        ->and(InclusivityScore::for($track(40, 20, 20))['score'])->toBe(80)
        ->and(InclusivityScore::for($track(40, 20, 20))['class'])->toBe('A1')   // 80 compreso
        ->and(InclusivityScore::for($track(40, 20, 19))['class'])->toBe('A2');  // 79
});

it('non produce una classe se il dato è incompleto', function () {
    expect(InclusivityScore::for($track(11, null, null)))->toBeNull();
});

it('deposita punteggio e classe nell indice e non in una colonna', function () {
    expect($track->toSearchableArray()['inclusivity_score'])->toBe(100)
        ->and(Schema::hasColumn('ec_tracks', 'inclusivity_score'))->toBeFalse();
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Le soglie in `config/forestas.php`**

Le sei classi A1-A6 come intervalli, con **A1 da 80 compreso in su e A2 da 57 a 79**: la call
scioglie così la sovrapposizione sull'80. In configurazione e non nel codice, perché il Tavolo
Tecnico Regionale può cambiarle ogni anno.

- [ ] **Step 4: Implementare `InclusivityScore`**

`null` se manca uno dei tre: un sentiero con il solo `AF` compilato non è un sentiero in classe
A6, è un sentiero non misurato, e la differenza va mantenuta.

- [ ] **Step 5: Depositare nell'indice**

Punteggio e classe entrano in `toSearchableArray()` e **non** in una colonna. Quando le soglie
cambiano, le classi già indicizzate restano quelle vecchie finché non si reindicizza: scriverlo
nella documentazione del Task 8.

- [ ] **Step 6: Lanciare i test e verificare che passino**

Esito atteso: PASS, 3 test.

- [ ] **Step 7: Commit** *(istruzione per il dev)*

```bash
git add config/forestas.php app/Support/InclusivityScore.php app/Models tests/Feature/Inclusivity
git commit -m "feat(oc:8910): punteggio di inclusività e classi A1-A6 nell'indice"
```

---

### Task 7: Nova

**Files:**
- Modify: `app/Nova/EcTrack.php`
- Modify: `app/Nova/EcPoi.php`
- Modify: `app/Providers/NovaServiceProvider.php`
- Test: `tests/Feature/Nova/EcTrackInclusivityFieldsTest.php`

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('mostra il blocco Inclusività sulle tracce e non sui POI', function () {
    expect(tabNames(new EcTrackResource))->toContain('Inclusività')->not->toContain('Accessibility')
        ->and(tabNames(new EcPoiResource))->not->toContain('Accessibility');
});

it('limita i due testi a 30 caratteri', function () {
    expect(ruleOf(new EcTrackResource, 'properties->forestas.inclusivity_independent_use'))->toContain('max:30');
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Il blocco «Inclusività» su `EcTrack`**

Le select dei vocabolari `accessibility-difficulty` e `accepted-disabilities` generate dal
metodo del package, i tre interi con la validazione dell'intervallo, i due testi con `max:30`, e
punteggio e classe in sola lettura, calcolati con `InclusivityScore`. I vocabolari che riguardano
solo Forestas — `route-type`, `themes`, `itineraries-and-paths` — vanno nel tab che le compete.

- [ ] **Step 4: Togliere il tab «Accessibility» da entrambe le Resource**

`app/Nova/EcTrack.php:59` e `app/Nova/EcPoi.php:62`. Nel package non si tocca niente: quei campi
servono agli shard che importano da GeoHub.

- [ ] **Step 5: La Resource dei termini nel menu**

In `NovaServiceProvider.php:100`, dentro `MenuSection::make('Taxonomies', [...])`.

- [ ] **Step 6: Lanciare i test e verificare che passino**

Esito atteso: PASS, 2 test.

- [ ] **Step 7: Commit** *(istruzione per il dev)*

```bash
git add app/Nova app/Providers/NovaServiceProvider.php tests/Feature/Nova
git commit -m "feat(oc:8910): blocco Inclusività in Nova e termini nel menu Taxonomies"
```

---

### Task 8: verifica finale e documentazione

- [ ] **Step 1: PHPStan**

Run: `docker exec -it php-forestas vendor/bin/phpstan analyse`
Esito atteso: nessun errore sui file di questo lavoro.

- [ ] **Step 2: Suite completa**

Run: `docker exec -it php-forestas vendor/bin/pest`, dopo aver verificato l'isolamento del
database.
Esito atteso: verde.

- [ ] **Step 3: Pagina di conoscenza**

`docs/knowledge/<argomento>.md` con: come nasce un vocabolario, come le avvertenze ci sono
arrivate e cosa è sparito con loro, come l'import mappa i tid Drupal,
perché le tre scale collassano in una, perché il punteggio sta solo nell'indice e cosa fare
quando le soglie cambiano (`scout:import`, non il reindex normale — oc:8681).

- [ ] **Step 4: La regola del prefisso nel `CLAUDE.md`**

Una riga sotto `## Regole del repo`, da proporre al dev: ogni colonna aggiunta da Forestas a una
tabella del package porta `forestas_` come prefisso.

- [ ] **Step 5: Commit** *(istruzione per il dev)*

```bash
git add docs/knowledge CLAUDE.md
git commit -m "docs(oc:8910): pagina di conoscenza del modello dei termini su Forestas"
```

---

### Task 9: le avvertenze convergono nel modello unico

Aggiunto in corsa, dopo che il modello si è dimostrato sui dati veri. Dipende dal Task 8 del
piano del package (`AbstractTermVocabularyResource`); la verifica finale del Task 8 si rilancia dopo
questo.

**Files:**
- Delete: `app/Models/TaxonomyWarning.php`, `app/Models/TaxonomyWarningable.php`
- Delete: `database/migrations/2026_04_02_120000_create_taxonomy_warnings_table.php`
- Delete: `database/migrations/2026_04_02_120001_create_taxonomy_warningables_table.php`
- Create: `database/migrations/<data>_drop_taxonomy_warnings_tables.php`
- Modify: `app/Console/Commands/ConfigureTaxonomyTermsCommand.php`
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`
- Modify: `app/Console/Commands/ResetSardegnaSentieriCommand.php`
- Modify: `app/Models/EcTrack.php`, `app/Nova/EcTrack.php`, `app/Nova/TaxonomyWarning.php`
- Test: `tests/Feature/Import/SardegnaSentieriWarningsTest.php`

- [ ] **Step 1: Scrivere i test che falliscono**

```php
it('importa le avvertenze come termini del vocabolario warnings', function () {
    // La sorgente di prova porta due avvertenze Drupal.
    $service->importWarningTaxonomies();

    expect(TaxonomyTerm::family('warnings')->children()->pluck('identifier')->all())
        ->toBe(['sardegnasentieri:warning:4716', 'sardegnasentieri:warning:4718']);
});

it('lega le avvertenze al sentiero e non ne accumula al reimport', function () {
    $service->importTrack($response);
    $service->importTrack($response);

    expect($track->terms('warnings')->pluck('identifier')->all())
        ->toBe(['sardegnasentieri:warning:4716']);
});

it('non lascia in giro le tabelle vecchie', function () {
    expect(Schema::hasTable('taxonomy_warnings'))->toBeFalse()
        ->and(Schema::hasTable('taxonomy_warningables'))->toBeFalse();
});

it('mostra nella vista Avvertenze solo i termini di quelil vocabolario', function () {
    expect(identifiersInIndex(new TaxonomyWarningResource))
        ->toContain('sardegnasentieri:warning:4716')
        ->not->toContain('route-type-trail');
});
```

- [ ] **Step 2: Lanciare i test e verificare che falliscano**

Esito atteso: FAIL.

- [ ] **Step 3: Il vocabolario `warnings` nel comando**

Una riga nell'elenco di `ConfigureTaxonomyTermsCommand`: `identifier` `warnings`, nome
«Avvertenze», icona `txn-alert`, `single_choice` a `false`, **nessun termine** — li porta
l'import.

- [ ] **Step 4: L'import scrive termini e relazioni sul modello nuovo**

`importWarningTaxonomies()` crea i termini sotto il vocabolario `warnings` con `insertOrIgnore` e
rilettura, conservando l'`identifier` `sardegnasentieri:warning:<tid>` e i nomi `it`/`en`.
`syncTrackWarnings()` sostituisce i soli relazioni di quelil vocabolario, come già fanno le scale di
difficoltà. `resolveWarningIds()` cerca fra i termini del vocabolario.

- [ ] **Step 5: Togliere il vecchio modello**

Via `App\Models\TaxonomyWarning`, `App\Models\TaxonomyWarningable` e `EcTrack::taxonomyWarnings()`.
Via le tre righe di `ResetSardegnaSentieriCommand.php:100-103`: le relazioni le cancella già la riga
di `taxonomy_termables` in `ImportSardegnaSentieriCommand::resetData()`.

- [ ] **Step 6: La migration che toglie le due tabelle**

Si cancellano i due file che le creano e se ne scrive uno solo con `dropIfExists` su
`taxonomy_warningables` e poi `taxonomy_warnings`. Niente copia dei dati: li riscrive l'import.
`down()` non le ricrea — tornare indietro significa rimettere i due file.

- [ ] **Step 7: La vista «Avvertenze» sul modello nuovo**

`app/Nova/TaxonomyWarning.php` smette di estendere `AbstractTaxonomyResource` ed estende
`AbstractTermVocabularyResource` del package, dichiarando `vocabularyIdentifier(): 'warnings'` e
`uriKey(): 'taxonomy-warnings'`, così l'indirizzo e il segnalibro di chi la usa continuano a
funzionare. La voce di menu in `NovaServiceProvider` non si tocca. Sul dettaglio del termine le
tracce collegate ci sono già, dalla Resource del package.

Su `app/Nova/EcTrack.php` sparisce il campo `MorphToMany` «Warnings»: il vocabolario compare nel tab
«Classifications», che le legge dal database.

- [ ] **Step 8: Lanciare i test e verificare che passino**

Esito atteso: PASS, 4 test, più la suite di import già esistente che nomina le avvertenze.

- [ ] **Step 9: Verifica sui dati veri**

Dopo `sardegnasentieri:import`, controllare che le relazioni del vocabolario `warnings` siano **818**
su **13** termini, cioè i numeri di oggi:

```sql
SELECT count(*) FROM taxonomy_termables tt
JOIN taxonomy_terms t ON t.id = tt.taxonomy_term_id
JOIN taxonomy_terms f ON f.id = t.parent_id AND f.identifier = 'warnings';
```

- [ ] **Step 10: Commit** *(istruzione per il dev)*

```bash
git add app database tests
git commit -m "feat(oc:8910): le avvertenze diventano un vocabolario del modello unico"
```
