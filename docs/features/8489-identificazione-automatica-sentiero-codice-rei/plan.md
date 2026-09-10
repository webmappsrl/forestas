> Ticket: oc:8489

# Menu Catasto e Resource Sentiero — Implementation Plan (forestas)

> **For agentic workers:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata) o `superpowers:executing-plans` per eseguire questo piano task per task. Gli step usano checkbox (`- [ ]`).

**Goal:** dare al Catasto una voce di menu propria in Nova, con l'elenco dei soli sentieri, e far comparire accanto le due Resource che il package porta a dominio acceso.

**Architecture:** una sola Resource nuova, `Sentiero`, che estende `App\Nova\EcTrack` cambiando etichetta, `uriKey()` e `indexQuery()`. `ec_tracks` resta una tabella unica e `EcTrack` un modello unico: cambia solo come Nova lo presenta. Il menu `EC` non viene toccato.

**Tech Stack:** Laravel 12, PHP 8.4, Nova 5.7.6, Pest.

**Spec:** `docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md` (in questo repo) e `wm-package/docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md` (il registro, l'istanza e il service). **Il piano del package va eseguito prima di questo:** i Task 2 e 3 qui sotto dipendono dalle sue Resource.

## Global Constraints

- **Nova pinnata a `5.7.6`**: la licenza è scaduta, dalla 5.8.0 le release rispondono HTTP 402.
- **Il menu `EC` non si tocca.** `POI`, `Tracce`, `Enti`, `Layers`, `Feature Collections` restano come sono, e `Tracce` continua a mostrare tutti i tracciati. Ne dipende anche il fatto che `app/Nova/EcTrack.php` conservi una voce viva, quindi non venga cancellata per errore.
- **Nessuna Resource `Itinerario`**: nel Catasto sta solo ciò che si accatasta. Gli itinerari si vedono in `EC → Tracce`.
- **Nessuna Resource per i tracciati senza tipo**: rischio accettato dal dev, un tracciato senza tipo non compare nel Catasto e si trova solo con una query.
- **Non si tocca `SardegnaSentieriImportService`**: la mancata validazione del valore `type` è segnalata nei rischi e non corretta in questo ciclo (decisione esplicita del dev).
- **Test**: la suite di forestas gira sul database `forestas_testing`. **Prima di lanciarla, verificare che `phpunit.xml` e `.env.testing` puntino a `forestas_testing` e che quel database esista**, come da `CLAUDE.md`. Il database reale contiene dati importati da processi lunghi.
- **Nessun commit automatico**: gli step «Commit» sono istruzioni testuali per il developer. Non eseguire `git add`, `git commit`, `git push`, non creare branch.
- **Nessun `composer format` sull'intero progetto**: inquinerebbe il diff della feature.

---

### Task 1: Le 6 tracce senza tipo diventano sentieri — NON SI FA

> **Decisione del dev, 10/09/2026: se hanno un `ref`, che manchi il tipo non interessa.**
>
> Il task serviva a far comparire quelle tracce nel Catasto, che allora si pensava filtrasse per
> tipo. Non è più così: nel registro entra chi ha un codice, e il tipo non c'entra.
>
> Verificato sui dati reali: tutte e sei hanno un `ref`, cinque hanno già il codice assegnato
> (`ZNUB487`, `ZNUB482`, `ZSUD108`, `ZNUT509`, `ZSUD102`) e la sesta (`#366`) è già in lista per
> un altro motivo, la geometria duplicata. Non manca nulla da recuperare.
>
> Non finiscono nemmeno fra le anomalie: la lista raccoglie chi resta **senza numero** e dovrebbe
> averlo, e queste il numero ce l'hanno. Segnalarle riaprirebbe la lista ai difetti che non
> impediscono nulla — la stessa ragione per cui è caduto `codice_nel_nome`.

### Task 1 (originale, non eseguito): Le 6 tracce senza tipo diventano sentieri

**Files:**
- Create: `app/Console/Commands/BackfillTrackTypeCommand.php`
- Test: `tests/Feature/BackfillTrackTypeCommandTest.php`

**Interfaces:**
- Consumes: nulla.
- Produces: comando `forestas:backfill-track-type` con `--dry-run`.

**Perché un comando e non un `UPDATE` a mano:** un `attach` su pivot senza traccia di cosa c'era prima non è annullabile — l'informazione su quali id sono stati toccati esisterebbe solo nello scrollback del terminale di chi l'ha eseguito. Il comando è idempotente, ha la prova a vuoto, e resta versionato.

**Il criterio è «ha un `ref`»**, e vale perché nessun itinerario ne ha (zero su 147). **Non è un criterio generale**: 40 tracce di tipo `sentiero` non hanno `ref`. Serve solo a classificare queste 6.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use App\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;

beforeEach(function () {
    $this->sentiero = TaxonomyActivity::factory()->create([
        'identifier' => 'sardegnasentieri:type:sentiero',
    ]);
    $this->itinerario = TaxonomyActivity::factory()->create([
        'identifier' => 'sardegnasentieri:type:itinerario',
    ]);
});

it('attacca il tipo sentiero a una traccia senza tipo che ha un ref', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => 'Z-NU-B-535']]);

    $this->artisan('forestas:backfill-track-type')->assertExitCode(0);

    expect($track->fresh()->taxonomyActivities->pluck('identifier'))
        ->toContain('sardegnasentieri:type:sentiero');
});

it('non tocca una traccia senza ref', function () {
    $track = EcTrack::factory()->create(['properties' => []]);

    $this->artisan('forestas:backfill-track-type')->assertExitCode(0);

    expect($track->fresh()->taxonomyActivities)->toHaveCount(0);
});

it('non tocca una traccia che ha gia un tipo', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => '206']]);
    $track->taxonomyActivities()->attach($this->itinerario->id);

    $this->artisan('forestas:backfill-track-type')->assertExitCode(0);

    expect($track->fresh()->taxonomyActivities->pluck('identifier'))
        ->toContain('sardegnasentieri:type:itinerario')
        ->not->toContain('sardegnasentieri:type:sentiero');
});

it('non scrive nulla in prova a vuoto', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => '206']]);

    $this->artisan('forestas:backfill-track-type --dry-run')
        ->expectsOutputToContain('1')
        ->assertExitCode(0);

    expect($track->fresh()->taxonomyActivities)->toHaveCount(0);
});

it('e idempotente: due esecuzioni non creano due righe in pivot', function () {
    $track = EcTrack::factory()->create(['properties' => ['ref' => '206']]);

    $this->artisan('forestas:backfill-track-type');
    $this->artisan('forestas:backfill-track-type');

    expect($track->fresh()->taxonomyActivities)->toHaveCount(1);
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/BackfillTrackTypeCommandTest.php`
Expected: FAIL, comando non trovato.

- [ ] **Step 3: implementa il comando**

```php
<?php

namespace App\Console\Commands;

use App\Models\EcTrack;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\TaxonomyActivity;

/**
 * Attacca il tipo `sentiero` alle tracce che non hanno alcun tipo ma hanno un
 * `ref`.
 *
 * Il criterio vale perche' nessun itinerario ha un `ref` (zero su 147 misurati
 * il 09/09/2026). NON e' un criterio generale: 40 tracce di tipo `sentiero`
 * non hanno `ref` e non vanno toccate da qui — il loro codice sta scritto nel
 * nome, e cosa significhi la sua assenza in `ref` e' una domanda aperta con
 * Forestas.
 *
 * Idempotente: usa syncWithoutDetaching, quindi due esecuzioni non creano due
 * righe in pivot.
 */
class BackfillTrackTypeCommand extends Command
{
    protected $signature = 'forestas:backfill-track-type {--dry-run}';

    protected $description = 'Attacca il tipo sentiero alle tracce senza tipo che hanno un ref';

    public const TRAIL_IDENTIFIER = 'sardegnasentieri:type:sentiero';

    public const ROUTE_IDENTIFIER = 'sardegnasentieri:type:itinerario';

    public function handle(): int
    {
        $trail = TaxonomyActivity::where('identifier', self::TRAIL_IDENTIFIER)->first();

        if ($trail === null) {
            $this->error('Tassonomia '.self::TRAIL_IDENTIFIER.' non trovata: eseguire prima l\'import.');

            return self::FAILURE;
        }

        $typeIds = TaxonomyActivity::whereIn('identifier', [
            self::TRAIL_IDENTIFIER,
            self::ROUTE_IDENTIFIER,
        ])->pluck('id');

        $candidates = EcTrack::query()
            ->whereRaw("COALESCE(properties->>'ref', '') <> ''")
            ->whereDoesntHave('taxonomyActivities', fn ($q) => $q->whereIn('taxonomy_activities.id', $typeIds))
            ->get(['id', 'name']);

        $this->info('Tracce senza tipo con un ref: '.$candidates->count());

        foreach ($candidates as $track) {
            $this->line("  #{$track->id}");
        }

        if ($this->option('dry-run')) {
            $this->comment('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        foreach ($candidates as $track) {
            $track->taxonomyActivities()->syncWithoutDetaching([$trail->id]);
        }

        $this->info('Tipo attaccato a '.$candidates->count().' tracce.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/BackfillTrackTypeCommandTest.php`
Expected: PASS, 5 test.

- [ ] **Step 5: esegui la prova a vuoto sul database reale e leggi il numero**

Run: `docker exec -it php-forestas php artisan forestas:backfill-track-type --dry-run`
Expected: «Tracce senza tipo con un ref: 6». **Se il numero è diverso da 6, fermati e riferiscilo**: il dato è stato misurato il 09/09/2026 e una divergenza significa che l'import ha cambiato qualcosa da allora.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add app/Console/Commands/BackfillTrackTypeCommand.php tests/Feature/BackfillTrackTypeCommandTest.php
git commit -m "feat(oc:8489): comando per attaccare il tipo sentiero alle tracce che hanno un ref"
```

---

### Task 2: Resource `Sentiero` — NON SI FA

> **Decisione del dev, 10/09/2026.** Sarebbe un doppione del Registro dei codici, che mostra già
> il codice, la denominazione e il collegamento al sentiero: chi vuole più dettaglio clicca quel
> collegamento.
>
> Nel discuterne era emersa una versione migliore — una Resource astratta, che invece di filtrare
> sulla tassonomia sarda `sentiero`/`itinerario` selezionasse i tracciati **con un codice
> assegnato**, e che per questo sarebbe potuta vivere nel package. È stata scritta e poi rimossa
> per la stessa ragione: anche così restava una seconda vista sugli stessi dati del registro.
>
> Con essa è caduta anche la relazione `EcTrack::trailRegistryCode()`, che sarebbe stata l'unico
> punto in cui il modello condiviso conosce il dominio opzionale. Meglio non averla finché
> nessuno la usa: a dominio spento quella tabella non esiste.

### Task 2 (originale, non eseguito): Resource `Sentiero`

**Files:**
- Create: `app/Nova/Sentiero.php`
- Test: `tests/Feature/Nova/SentieroResourceTest.php`

**Interfaces:**
- Consumes: `App\Nova\EcTrack`, e la Resource del registro dal package (per la colonna del codice).
- Produces: Resource `Sentiero` con `uriKey()` `sentieri`.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use App\Models\EcTrack;
use App\Nova\Sentiero;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyActivity;

beforeEach(function () {
    $this->sentiero = TaxonomyActivity::factory()->create([
        'identifier' => 'sardegnasentieri:type:sentiero',
    ]);
    $this->itinerario = TaxonomyActivity::factory()->create([
        'identifier' => 'sardegnasentieri:type:itinerario',
    ]);
});

it('mostra solo i tracciati di tipo sentiero', function () {
    $trail = EcTrack::factory()->create();
    $trail->taxonomyActivities()->attach($this->sentiero->id);

    $route = EcTrack::factory()->create();
    $route->taxonomyActivities()->attach($this->itinerario->id);

    $untyped = EcTrack::factory()->create();

    $ids = Sentiero::indexQuery(NovaRequest::create('/'), EcTrack::query())
        ->pluck('id');

    expect($ids)->toContain($trail->id)
        ->not->toContain($route->id)
        ->not->toContain($untyped->id);
});

it('ha una uriKey propria, distinta da quella di EcTrack', function () {
    expect(Sentiero::uriKey())->toBe('sentieri')
        ->not->toBe(\App\Nova\EcTrack::uriKey());
});

it('gira sullo stesso modello di EcTrack', function () {
    expect(Sentiero::$model)->toBe(\App\Nova\EcTrack::$model);
});

it('espone la colonna del codice del registro', function () {
    $track = EcTrack::factory()->create();

    $fields = collect((new Sentiero($track))->fields(NovaRequest::create('/')))
        ->map(fn ($f) => $f->name)
        ->all();

    expect($fields)->toContain('Codice');
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/Nova/SentieroResourceTest.php`
Expected: FAIL, `App\Nova\Sentiero` non trovata.

- [ ] **Step 3: implementa la Resource**

```php
<?php

namespace App\Nova;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * I sentieri: i tracciati accatastabili, quelli che hanno un codice REI.
 *
 * Gira sullo stesso modello di EcTrack — `ec_tracks` resta una tabella unica —
 * e ne estende la Resource cambiando solo etichetta, uriKey e indexQuery.
 *
 * Sta in forestas e non nel package perche' il filtro e' su un identificatore
 * sardo: nel package sarebbe la Sardegna scritta dentro codice condiviso, che
 * servira' anche Lombardia e Toscana.
 *
 * ATTENZIONE: con due Resource sullo stesso modello, la Resource canonica —
 * quella che Nova usa per disegnare i campi relazione, cinque MorphToMany in
 * Ente, uno in EcPoi, uno in TaxonomyWarning — e' decisa da un `first()`
 * memoizzato in Nova::resourceForModel(), quindi dall'ordine di scoperta dei
 * file. Va verificato in ambiente che vinca `EcTrack`; se non lo fa, va
 * ancorata esplicitamente. Per questo `app/Nova/EcTrack.php` NON va
 * cancellata: resta nel menu EC, dove ha la sua voce viva.
 */
class Sentiero extends EcTrack
{
    public static function uriKey(): string
    {
        return 'sentieri';
    }

    public static function label(): string
    {
        return __('Sentieri');
    }

    public static function singularLabel(): string
    {
        return __('Sentiero');
    }

    /**
     * Solo i tracciati di tipo `sentiero`.
     *
     * Chi non ha alcun tipo NON compare qui: rischio accettato: resta visibile
     * in EC -> Tracce, che non filtra nulla.
     */
    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        return $query->whereHas(
            'taxonomyActivities',
            fn ($q) => $q->where(
                'identifier',
                \App\Console\Commands\BackfillTrackTypeCommand::TRAIL_IDENTIFIER,
            ),
        );
    }

    public function fields(NovaRequest $request): array
    {
        return [
            // Il codice del registro, letto dalla relazione e non da
            // `ec_tracks`: il registro e' la fonte unica e il prefisso non va
            // duplicato. E' il dato che sostituisce `ref`, deprecato.
            Text::make(__('Codice'), fn () => $this->trailRegistryCode?->code)
                ->exceptOnForms(),

            ...parent::fields($request),
        ];
    }
}
```

- [ ] **Step 4: aggiungi al modello la relazione verso il codice attivo**

In `app/Models/EcTrack.php`:

```php
    /**
     * Il codice del registro assegnato a questo sentiero.
     *
     * Il registro (dominio `trail_registry` del package) e' la fonte unica del
     * numero: `properties['ref']` resta come dato storico e non va piu' letto.
     */
    public function trailRegistryCode(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::class, 'ec_track_id')
            ->where('status', \Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus::Assigned->value);
    }
```

- [ ] **Step 5: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/Nova/SentieroResourceTest.php`
Expected: PASS, 4 test.

- [ ] **Step 6: verifica in ambiente quale Resource vince per il modello**

```bash
docker exec -it php-forestas php artisan tinker --execute="
echo \Laravel\Nova\Nova::resourceForModel(\App\Models\EcTrack::class);
"
```

Expected: `App\Nova\EcTrack`. **Se stampa `App\Nova\Sentiero`, fermati e riferiscilo**: i campi relazione in `Ente`, `EcPoi` e `TaxonomyWarning` mostrerebbero ogni tracciato come «Sentiero», itinerari inclusi.

- [ ] **Step 7: commit (istruzione per il developer, non eseguire)**

```bash
git add app/Nova/Sentiero.php app/Models/EcTrack.php tests/Feature/Nova/SentieroResourceTest.php
git commit -m "feat(oc:8489): resource Sentiero con la colonna del codice del registro"
```

---

### Task 3: Sezione di menu `Catasto` — FATTO, senza la voce «Sentieri»

> La sezione esiste e porta tre voci — Istanze, Registro dei codici, Anomalie — iniettate dal
> package solo a dominio acceso (`WmPackageServiceProvider::trailRegistryMenuItems()`). La quarta
> voce prevista qui, «Sentieri», cade con il Task 2.

### Task 3 (originale): Sezione di menu `Catasto`

**Files:**
- Modify: `app/Providers/NovaServiceProvider.php:46-110`
- Test: `tests/Feature/Nova/CatastoMenuTest.php`

**Interfaces:**
- Consumes: `Sentiero` del Task 2. Le voci `Istanze` e `Registro dei codici` **non si scrivono qui**: il package le accoda da sé a dominio acceso, con lo stesso meccanismo già in uso per `Tools` (`WmPackageServiceProvider`, righe 557-660).
- Produces: sezione `Catasto` nel menu principale.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;

function mainMenuSections(): array
{
    $user = User::factory()->create();
    $user->assignRole('Administrator');

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return collect((Nova::$mainMenuCallback)($request))
        ->filter(fn ($item) => $item instanceof MenuSection)
        ->all();
}

it('aggiunge la sezione Catasto', function () {
    $names = collect(mainMenuSections())->map(fn ($s) => (string) $s->name)->all();

    expect($names)->toContain('Catasto');
});

it('non tocca la sezione EC', function () {
    $ec = collect(mainMenuSections())->first(fn ($s) => (string) $s->name === 'EC');

    expect($ec)->not->toBeNull();
    expect(collect($ec->items)->count())->toBe(5);
});

it('mette Sentieri nel Catasto e non in EC', function () {
    $catasto = collect(mainMenuSections())->first(fn ($s) => (string) $s->name === 'Catasto');

    $paths = collect($catasto->items)->map(fn ($i) => $i->path)->implode(' ');

    expect($paths)->toContain('sentieri');
});
```

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/Nova/CatastoMenuTest.php`
Expected: FAIL, nessuna sezione `Catasto`.

- [ ] **Step 3: aggiungi la sezione al menu**

In `app/Providers/NovaServiceProvider.php`, dopo la sezione `Media` e prima di `Tools`:

```php
                // Il Catasto Sentieri. Solo cio' che si accatasta: gli
                // itinerari restano in EC -> Tracce, perche' non hanno un
                // codice REI.
                //
                // Le voci `Istanze` e `Registro dei codici` NON si dichiarano
                // qui: le accoda il package a dominio `trail_registry` acceso,
                // con lo stesso meccanismo usato per `Tools`. Se non
                // compaiono, controllare WM_TRAIL_REGISTRY_ENABLED nel .env.
                MenuSection::make(__('Catasto'), [
                    MenuItem::resource(Sentiero::class),
                ])->icon('clipboard-list'),
```

Aggiungi `use App\Nova\Sentiero;` in cima al file. **Non rimuovere** `MenuItem::resource(EcTrack::class)` dalla sezione `EC`.

- [ ] **Step 4: esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/Nova/CatastoMenuTest.php`
Expected: PASS, 3 test.

- [ ] **Step 5: verifica il menu dal browser**

Apri Nova e controlla: la sezione `Catasto` compare con `Sentieri`, `Istanze` e `Registro dei codici`; la sezione `EC` ha ancora tutte e cinque le voci; cliccando `Sentieri` l'elenco mostra 614 tracciati (620 dopo il Task 1) e non 767.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add app/Providers/NovaServiceProvider.php tests/Feature/Nova/CatastoMenuTest.php
git commit -m "feat(oc:8489): sezione di menu Catasto con la voce Sentieri"
```

---

### Task 4: Pubblicazione degli stub e verifica del gate CI

**Files:**
- Create: `database/migrations/` — le migration pubblicate dagli stub del package
- Modify: `.github/workflows/run-tests.yml` (solo se lo step non copre già il dominio)

- [ ] **Step 1: pubblica gli stub del dominio**

Gli stub di un dominio opzionale **non** si pubblicano con `vendor:publish` (la scoperta delle migration di Spatie non è ricorsiva):

```bash
docker exec -it php-forestas php artisan wm-package:publish-migration trail_registry/zz_2026_09_09_000001_create_trail_applications_table
docker exec -it php-forestas php artisan wm-package:publish-migration trail_registry/zz_2026_09_09_000002_create_trail_registry_codes_table
docker exec -it php-forestas php artisan wm-package:publish-migration trail_registry/zz_2026_09_09_000003_create_trail_registry_code_events_table
docker exec -it php-forestas php artisan wm-package:publish-migration trail_registry/zz_2026_09_09_000004_add_gist_index_to_taxonomy_wheres
```

- [ ] **Step 2: esegui le migration**

```bash
docker exec -it php-forestas php artisan migrate
```

- [ ] **Step 3: verifica il gate**

Run: `docker exec -it php-forestas php artisan wm-package:publish-missing-migrations --with=trail_registry --dry-run`
Expected: exit 0, nessuno stub mancante. Lo step di CI in `.github/workflows/run-tests.yml` esegue già questo comando con `--with=trail_registry` (oc:8492): se passa in locale, passa in CI.

- [ ] **Step 4: verifica che l'indice GiST esista**

```bash
docker exec -i postgres-forestas psql -U forestas -d forestas -c "\di taxonomy_wheres_geometry_gist"
```

Expected: l'indice è elencato. Senza, `resolveSector()` in prevalidazione va in timeout: la stessa query durante la pianificazione ha richiesto oltre due minuti.

- [ ] **Step 5: commit (istruzione per il developer, non eseguire)**

```bash
git add database/migrations
git commit -m "feat(oc:8489): pubblica le migration del dominio trail_registry"
```

---

### Task 5: Aggiornare il contesto del progetto

**Files:**
- Modify: `CLAUDE.md` (sezioni «Feature disponibili» e «Decisioni architetturali»)

- [ ] **Step 1: aggiungi la riga in «Feature disponibili»**

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Menu Catasto e Resource Sentiero | oc:8489 | `app/Nova/Sentiero.php`, `app/Models/EcTrack.php`, `app/Providers/NovaServiceProvider.php`, `app/Console/Commands/BackfillTrackTypeCommand.php`, `database/migrations/` | Nuova sezione `Catasto` con `Sentieri`; `Istanze` e `Registro dei codici` arrivano dal package a dominio acceso. Menu `EC` invariato |

- [ ] **Step 2: aggiungi il blocco in «Decisioni architetturali»**

Deve contenere, in cima:

- **Perché `app/Nova/EcTrack.php` non va cancellata** anche se sembra ridondante: è la Resource canonica del modello, quella da cui Nova disegna i sette campi relazione in `Ente`, `EcPoi` e `TaxonomyWarning`. Con due Resource sullo stesso modello la vincente è decisa da un `first()` memoizzato in `Nova::resourceForModel()`.
- **Perché non esiste una Resource `Itinerario`**: nel Catasto sta solo ciò che si accatasta, e nessun itinerario ha un `ref` (zero su 147). Gli itinerari si vedono in `EC → Tracce`.
- **Perché un tracciato senza tipo non compare nel Catasto** e come trovarlo: rischio accettato, il tipo arriva da Drupal e può tornare a mancare.
- **`SardegnaSentieriImportService::syncTrackType()` non valida il valore `type`**: interpola un campo Drupal, quindi un valore diverso a monte crea in silenzio una nuova `TaxonomyActivity`. **È il primo posto da guardare se la voce `Sentieri` risulta vuota o dimezzata.** Non corretto in questo ciclo, per decisione esplicita.
- **`syncTrackTaxonomies()` fa un `sync()` pieno delle activity e `syncTrackType()` ricompone subito dopo**: chi riordina quelle tre righe fa perdere il tipo a tutti i tracciati.

- [ ] **Step 3: commit (istruzione per il developer, non eseguire)**

```bash
git add CLAUDE.md
git commit -m "docs(oc:8489): aggiorna il contesto di progetto con il menu Catasto"
```

---

## Cosa questo piano NON fa

- **Nessuna Resource `Itinerario`** e nessuna Resource per i tracciati senza tipo.
- **Nessuna modifica al menu `EC`**.
- **Nessuna normalizzazione dei 40 sentieri con il codice nel nome**: si segnalano e non si toccano, in attesa di capire lato Drupal cosa significhi l'assenza del `ref`.
- **Nessuna disabilitazione della creazione da `Sentieri`**: se ogni sentiero debba nascere da un'istanza è una domanda aperta con Forestas. Finché non c'è risposta, un sentiero creato lì resta fuori dal registro e il suo codice risulta libero per un'altra domanda.
- **Nessun controllo di autorizzazione oltre a quello ereditato**: `indexQuery()` non è una policy, quindi l'URL di una Resource con l'id di un tracciato di tipo diverso apre e salva. Un test che verifica solo l'elenco non copre questo.

---

### Task 6: Registrare il codice durante l'import (aggiunto il 10/09/2026)

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`
- Test: `tests/Feature/Import/SardegnaSentieriTrailRegistryTest.php`

**Cosa deve fare.** **Esattamente quello che fa il comando di normalizzazione, ma all'import**: quando una traccia entra o viene aggiornata da Sardegna Sentieri, il suo codice finisce nel registro senza che nessuno lanci nulla a mano. Stessa regola, stessi esiti, stesse eccezioni.

Non è una regola nuova: il Task 14 del piano del package la estrae dal comando e la mette in `TrailRegistryService::registerExistingCode()`. Qui si chiama quella. **Non riscrivere la logica**: se la stessa regola vive in due posti, prima o poi divergono — e decide quale numero un sentiero porta.

**Dove agganciarsi:** in fondo a `importTrackFromResponse()`, **dopo `syncTrackType()`**. Quello è l'ultimo passo del metodo ed è ciò che stabilisce se il tracciato è un sentiero o un itinerario: prima di lì l'informazione non c'è.

**Interfaces:**
- Consumes: `Wm\WmPackage\TrailRegistry\TrailRegistryService::registerExistingCode(int $ecTrackId, string $ref, string $geometryWkt)`.
- Produces: nulla verso altri task — è l'ultimo anello.

**Le decisioni da rispettare:**

1. **Solo chi ha un `ref`.** Nessun `ref`, nessuna registrazione: è la stessa condizione del comando, e taglia fuori da sé gli itinerari — sui dati reali nessuno dei 147 ha un `ref`. Non serve quindi un controllo sul tipo, e aggiungerlo sarebbe una seconda regola da tenere allineata alla prima.

2. **L'import non deve mai fallire per colpa del registro.** Se la registrazione solleva, l'eccezione va catturata e scritta nel log, e l'import prosegue: un problema sul codice non deve far perdere l'aggiornamento di una traccia. È lo stesso criterio già usato altrove nel package per i side-effect (vedi `AppObserver` e il registro well-known).

3. **Silenzio quando non c'è niente da dire.** `alreadyRegistered` è il caso normale di ogni re-import — le tracce sono 767 e passano tutte da qui a ogni giro — quindi non va loggato. Vanno loggati: `conflict`, `unparsableRef`, `noSector` e il settore discordante, che sono le anomalie da vedere.

4. **Il dominio può essere spento.** `trail_registry` è un dominio opzionale: se l'interruttore è a `false` il service non è registrato. Prima di chiamarlo, verifica con `FeaturesService::isEnabled('trail_registry')` — altrimenti un consumer che non usa il catasto vedrebbe l'import cadere. Su forestas è acceso, ma l'import è codice di forestas e questa guardia costa una riga.

5. **La geometria si legge dal database, non dalla variabile locale.** Nel metodo la geometria è un WKT che può essere `null` quando la traccia esiste già e il GPX non è disponibile: in quel caso la traccia conserva la geometria che aveva, e il registro deve usare **quella**. Rileggila con `ST_AsText` dopo il salvataggio.

- [ ] **Step 1: scrivi il test che fallisce**

```php
<?php

use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    Bus::fake();
});

it('registra nel registro il codice di un sentiero appena importato', function () {
    // Il settore che contiene la traccia, e una risposta dell'API con un ref.
    // Costruisci la risposta con il DTO reale (ApiTrackResponse), non con un
    // array a mano: il punto del test e' che il flusso vero arrivi in fondo.
    // ... arrange ...

    $track = app(SardegnaSentieriImportService::class)->importTrackFromResponse($externalId, $response);

    $code = TrailRegistryCode::where('ec_track_id', $track->id)->firstOrFail();

    expect($code->code)->toBe('ZNUB535')
        ->and($code->status)->toBe(TrailCodeStatus::Assigned);
});

it('non crea un secondo codice quando la stessa traccia viene reimportata', function () {
    // ... arrange come sopra ...

    $service = app(SardegnaSentieriImportService::class);
    $service->importTrackFromResponse($externalId, $response);
    $service->importTrackFromResponse($externalId, $response);

    expect(TrailRegistryCode::count())->toBe(1);
});

it('importa comunque la traccia se la registrazione del codice fallisce', function () {
    // Nessun settore nel database: la registrazione non puo' riuscire.
    // La traccia deve comunque essere salvata — un problema sul codice non
    // deve far perdere l'aggiornamento di un sentiero.

    $track = app(SardegnaSentieriImportService::class)->importTrackFromResponse($externalId, $response);

    expect(EcTrack::find($track->id))->not->toBeNull();
    expect(TrailRegistryCode::count())->toBe(0);
});

it('non registra nulla per un tracciato senza ref', function () {
    // E' la condizione che taglia fuori gli itinerari: nessuno dei 147 sui
    // dati reali ha un ref.

    app(SardegnaSentieriImportService::class)->importTrackFromResponse($externalId, $response);

    expect(TrailRegistryCode::count())->toBe(0);
});

it('non tocca il registro quando il dominio e spento', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    app(SardegnaSentieriImportService::class)->importTrackFromResponse($externalId, $response);

    expect(DB::table('trail_registry_codes')->count())->toBe(0);
});
```

⚠️ Gli `arrange` sono lasciati da completare: guarda i test di import già presenti in `tests/` per come si costruisce una `ApiTrackResponse` e come si prepara il GPX. **Non inventare un doppione dell'API**: se esiste già un helper o una factory, riusala.

- [ ] **Step 2: esegui il test e verifica che fallisca**

Run: `docker exec -it php-forestas php artisan test --filter=SardegnaSentieriTrailRegistry`
Expected: FAIL, nessun codice registrato.

⚠️ Prima di eseguire, verifica l'isolamento del database di test come prescrive il `CLAUDE.md`: `phpunit.xml` e `.env.testing` devono puntare a `forestas_testing`, e quel database deve esistere. **Il database reale contiene dati importati da processi lunghi.**

- [ ] **Step 3: aggancia la registrazione in fondo all'import**

In `importTrackFromResponse()`, dopo `syncTrackType($ecTrack, $response)`:

```php
        $this->registerTrailRegistryCode($ecTrack);

        return $ecTrack;
    }

    /**
     * Registra nel catasto il codice del sentiero appena importato.
     *
     * E' la stessa regola del comando di normalizzazione — la stessa
     * identica, perche' chiama lo stesso metodo del service: leggere il
     * `ref`, ricavare il settore dalla geometria, scrivere come assegnato o
     * come conflitto se la posizione e' presa.
     *
     * Non solleva mai: un problema sul codice non deve far perdere
     * l'aggiornamento di una traccia.
     */
    private function registerTrailRegistryCode(EcTrack $ecTrack): void
    {
        if (! FeaturesService::isEnabled('trail_registry')) {
            return;
        }

        $ref = $ecTrack->properties['ref'] ?? '';

        // Nessun ref, nessun codice. E' anche cio' che taglia fuori gli
        // itinerari: nessuno dei 147 sui dati reali ne ha uno.
        if (trim((string) $ref) === '') {
            return;
        }

        try {
            // La geometria si rilegge dal database: la variabile locale del
            // metodo puo' essere null quando la traccia esisteva gia' e il GPX
            // non era disponibile, mentre la riga conserva quella di prima.
            $row = DB::selectOne(
                'SELECT ST_AsText(geometry) AS wkt FROM ec_tracks WHERE id = ? AND geometry IS NOT NULL',
                [$ecTrack->id],
            );

            if ($row === null) {
                return;
            }

            $outcome = app(TrailRegistryService::class)
                ->registerExistingCode($ecTrack->id, (string) $ref, $row->wkt);

            $this->logTrailRegistryOutcome($ecTrack, (string) $ref, $outcome);
        } catch (\Throwable $e) {
            Log::error('Catasto: registrazione del codice fallita', [
                'ec_track_id' => $ecTrack->id,
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

Il metodo che scrive nel log riporta **solo le anomalie**: `conflict`, `unparsableRef`, `noSector` e il settore discordante. `alreadyRegistered` è il caso normale di ogni re-import — 767 tracce a ogni giro — e riempirebbe il log di righe che nessuno legge.

- [ ] **Step 4: esegui i test e verifica che passino**

Run: `docker exec -it php-forestas php artisan test --filter=SardegnaSentieriTrailRegistry`
Expected: PASS, 5 test.

Poi l'intera suite di import, che non deve essersi rotta.

- [ ] **Step 5: provalo su una traccia vera**

Con il registro già popolato, reimporta una singola traccia e verifica che **non** compaia un secondo codice:

```bash
DB_HOST=127.0.0.1 DB_PORT=5500 /opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="
\$before = \Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::count();
app(\App\Services\Import\SardegnaSentieriImportService::class)->importTrack(<id sardegnasentieri>);
echo \$before.' -> '.\Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode::count();
"
```

Expected: il conteggio non cambia. Se cambia, la guardia di idempotenza non sta funzionando dall'import.

- [ ] **Step 6: commit (istruzione per il developer, non eseguire)**

```bash
git add app/Services/Import/SardegnaSentieriImportService.php tests/Feature/Import/SardegnaSentieriTrailRegistryTest.php
git commit -m "feat(oc:8489): registra il codice del sentiero durante l'import da Sardegna Sentieri"
```

---

### Task 7: La proprietà del codice storico non è più cablata (aggiunto il 10/09/2026) — CHIUSO SENZA TOCCARE L'IMPORT

> **Decisione del dev, 10/09/2026.** La parte che serviva davvero è nel package, ed è fatta:
> `legacy_code_property` rende parametrica la lettura per il comando di normalizzazione, che è
> codice condiviso e gira su qualunque shard.
>
> L'import di forestas **non** va parametrizzato: `SardegnaSentieriImportService` è custom di
> questo progetto e in un altro shard non esiste — lì l'import sarà un altro service, con
> un'altra fonte. Ed è proprio l'import a **decidere** come si chiama quella chiave, scrivendo
> `properties['ref']` dalla risposta dell'API: far dipendere da una configurazione un valore che
> quel codice conosce per definizione aggiungerebbe indirezione senza riuso da proteggere.
>
> Resta un'accortezza, non un cambio di codice: se un domani la chiave scritta in `properties`
> cambiasse nome, va aggiornata di conseguenza `WM_TRAIL_LEGACY_CODE_PROPERTY` nel `.env`,
> altrimenti il comando cercherebbe la chiave vecchia.

**Files:**
- Modify: `app/Services/Import/SardegnaSentieriImportService.php`
- Modify: `tests/Feature/Import/SardegnaSentieriTrailRegistryTest.php`

**Perché.** L'aggancio all'import legge `$ecTrack->properties['ref']`, con il nome della proprietà scritto nel codice. Su forestas è `ref`, ereditato da Sardegna Sentieri, ma su un altro catasto si chiamerà altrimenti — e il Task 15 del piano del package introduce la chiave di configurazione che lo dice.

Questo è il terzo e ultimo punto in cui `ref` era cablato: gli altri due sono nel comando di normalizzazione e li sistema quel task.

- [ ] **Step 1: leggi la proprietà dalla configurazione**

In `registerTrailRegistryCode()`, sostituisci il nome scritto a mano con quello dichiarato:

```php
        $property = config('wm-package.features.trail_registry.legacy_code_property', 'ref');
        $ref = $ecTrack->properties[$property] ?? '';
```

Il valore predefinito resta `ref`, quindi su forestas non cambia niente.

- [ ] **Step 2: aggiungi il test che lo presidia**

```php
it('legge il codice dalla proprieta indicata in configurazione', function () {
    config(['wm-package.features.trail_registry.legacy_code_property' => 'codice_storico']);

    // ... arrange: una risposta dell'API il cui tracciato porta il codice in
    // `properties['codice_storico']` invece che in `ref` ...

    app(SardegnaSentieriImportService::class)->importTrackFromResponse($externalId, $response);

    expect(TrailRegistryCode::count())->toBe(1);
});
```

Senza questo test la chiave di configurazione esiste ma nessuno verifica che l'import la legga davvero: sarebbe una promessa non mantenuta, e se ne accorgerebbe solo il primo consumer che prova a cambiarla.

- [ ] **Step 3: esegui i test**

Run: `DB_HOST=127.0.0.1 DB_PORT=5500 /opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SardegnaSentieriTrailRegistry`
Expected: i 5 test esistenti più il nuovo, tutti verdi.

⚠️ Verifica prima l'isolamento del database, come prescrive il `CLAUDE.md`.

- [ ] **Step 4: commit (istruzione per il developer, non eseguire)**

```bash
git add app/Services/Import/SardegnaSentieriImportService.php tests/Feature/Import/SardegnaSentieriTrailRegistryTest.php
git commit -m "refactor(oc:8489): la proprieta' del codice storico si legge dalla configurazione"
```

---

### Task 8: La voce «Anomalie» nel menu Catasto (aggiunto il 10/09/2026)

**Files:**
- Verify only: `app/Providers/NovaServiceProvider.php`

**Perché è un task e non una riga.** La Resource delle anomalie vive nel package e si registra da sé a dominio acceso, accodandosi alla sezione `Catasto` insieme a `Istanze` e `Registro dei codici`. **In forestas non c'è niente da scrivere** — ma c'è da verificare che compaia davvero, perché il meccanismo di iniezione del menu è lo stesso che al Task 3 di questo piano crea la sezione con la sola voce `Sentieri`, e un errore là si vedrebbe solo qui.

- [ ] **Step 1: verifica dal browser**

Apri Nova e controlla che la sezione `Catasto` mostri quattro voci: `Sentieri`, `Istanze`, `Registro dei codici`, `Anomalie`.

- [ ] **Step 2: verifica che la lista sia popolata**

Apri `Anomalie` e controlla che le righe corrispondano a quanto misurato sui dati reali dopo l'esecuzione del comando di normalizzazione — al 10/09/2026: 18 codici contesi, 13 settori discordanti, 40 codici nel nome, 6 coppie con geometria duplicata.

Se i numeri non tornano, il difetto è nel rilevamento del Task 15 del package, non qui.
