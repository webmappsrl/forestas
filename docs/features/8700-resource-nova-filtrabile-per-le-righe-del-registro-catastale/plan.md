> Ticket: oc:8700

# Righe del registro catastale in Nova — piano di implementazione (forestas)

> **Per chi esegue con agenti:** SUB-SKILL RICHIESTA: superpowers:subagent-driven-development (consigliata) o superpowers:executing-plans, task per task. Gli step usano le checkbox (`- [ ]`).

**Obiettivo:** una Resource Nova in sola lettura, «Righe del registro», con tutte le righe del registro catastale, filtrabile per Tab, Area, Settore e aggancio a un codice, ricercabile per numero, visibile solo ad Administrator ed Editor.

**Architettura:** l'import salva in colonne vere i valori che il parser già calcola (area, settore, numero, variante). La Resource legge `registro_catastale_rows`, riusa il renderer della Tab «Registro» per il detail e una Policy che richiama `TrailRegistryPolicy::allows()` del package.

**Stack:** Laravel 12, Nova 5.7.6, PostgreSQL, Pest.

**Spec:** `docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/overview.md`. Prerequisito: il piano del package, `wm-package/docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/plan.md`, eseguito per primo. Questo piano usa `Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy::allows()`.

## Vincoli globali

- **Nessun commit, `git add`, branch o push eseguito da Claude**: gli step «Commit» sono istruzioni per il dev, che committa dopo aver letto il diff.
- **Nessuna migration aggiuntiva**: si modifica la create `database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php`.
- **Prima di lanciare `pest` verifica l'isolamento** (`.claude/rules/test.md`). Se una delle tre verifiche fallisce, non lanciare i test e chiedi al dev:

  ```bash
  grep -n 'DB_DATABASE' phpunit.xml                       # atteso: forestas_testing
  grep -n '^DB_DATABASE' .env.testing                     # atteso: forestas_testing
  docker exec postgres-forestas psql -U forestas -lqt | cut -d'|' -f1 | grep -w forestas_testing
  ```

- I test si lanciano nel container: `docker exec php-forestas vendor/bin/pest <percorso o --filter>`.
- Commenti, docblock e documentazione in italiano; termini tecnici in inglese.
- Ogni stringa nuova ha la chiave in `lang/it.json` (lingua di default, `APP_LOCALE=it`) e in `lang/en.json`. Le chiavi seguono lo stile esistente: chiave inglese, valore tradotto (es. `"Registry row": "Riga del registro"`).
- Non eseguire `composer format` sull'intero progetto: solo sui file toccati.

## Review Focus

1. **Riga con l'area scritta solo sulla prima riga del gruppo** (33 righe sul DB locale): deve avere comunque l'area, ereditata dalla riga sopra → test nel Task 1.
2. **Numero scritto in forme diverse** (`101A`, `401 A`, `302/A`, `100 (S.I.)`): la colonna Numero e la ricerca devono dare la stessa forma ricomposta → test nei Task 1 e 3.
3. **Riga con solo il link e nessun numero leggibile**: nessun errore, Numero «—» → test nel Task 3.
4. **Variante assente salvata come `'0'`**: non deve comparire `100 0` → test nel Task 1.
5. **Validator che apre per URL il detail di una riga**: 403, non la pagina → test nel Task 3.

---

### Task 1: colonne normalizzate nel mirror

**File:**
- Modifica: `database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php`
- Modifica: `app/Models/RegistroCatastaleRow.php`
- Modifica: `app/Services/RegistroCatastale/RegistroCatastaleImporter.php:132-140`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleImporterTest.php`, `tests/Feature/RegistroCatastale/RegistroCatastaleRowModelTest.php`

**Interfacce:**
- Consuma: `RegistroCatastaleParsedRow` con le proprietà `area: ?string`, `sector: ?string`, `number: ?int`, `variant: string` (`app/Services/RegistroCatastale/RegistroCatastaleRowParser.php:64-73`).
- Produce: colonne `area`, `sector`, `number`, `variant` su `registro_catastale_rows`; attributo `RegistroCatastaleRow::$sheet_number` (`?string`), il numero ricomposto (`100`, `100A`, `162`), usato dal Task 3.

- [ ] **Step 1: scrivi i test che falliscono**

In `RegistroCatastaleImporterTest.php`, con gli helper già presenti nel file (`registroCsv()`, `fakeSingleSheet()`):

```php
it('scrive nel mirror area, settore, numero e variante normalizzati (oc:8700)', function () {
    fakeSingleSheet(registroCsv([
        ['Z-NU-B', '1', '105', ''],
        // Area vuota: sul foglio e' scritta solo sulla prima riga del gruppo.
        ['', '1', '163 A', ''],
        ['Z-NU-B', '1', '302/A', ''],
        // Solo link, nessun numero leggibile: la riga si tiene.
        ['Z-NU-B', '', 'vedi mappa', 'https://x/node/999'],
    ]));

    app(RegistroCatastaleImporter::class)->run();

    $rows = RegistroCatastaleRow::orderBy('row_number')->get(['area', 'sector', 'number', 'variant']);

    expect($rows->map->only(['area', 'sector', 'number', 'variant'])->all())->toBe([
        ['area' => 'B', 'sector' => '1', 'number' => 5, 'variant' => '0'],
        ['area' => 'B', 'sector' => '1', 'number' => 63, 'variant' => 'A'],
        ['area' => 'B', 'sector' => '3', 'number' => 2, 'variant' => 'A'],
        ['area' => 'B', 'sector' => null, 'number' => null, 'variant' => '0'],
    ]);
});
```

In `RegistroCatastaleRowModelTest.php`:

```php
it('ricompone il numero come sul foglio (oc:8700)', function (?string $sector, ?int $number, string $variant, ?string $expected) {
    $row = new RegistroCatastaleRow(['sector' => $sector, 'number' => $number, 'variant' => $variant]);

    expect($row->sheet_number)->toBe($expected);
})->with([
    'senza variante' => ['1', 0, '0', '100'],
    'con variante' => ['1', 0, 'A', '100A'],
    'numero a due cifre' => ['1', 62, '0', '162'],
    'numero a una cifra' => ['5', 6, 'C', '506C'],
    'nessun numero' => [null, null, '0', null],
]);
```

- [ ] **Step 2: verifica l'isolamento (vincoli globali), poi lancia i test e verifica che falliscano**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleImporterTest.php tests/Feature/RegistroCatastale/RegistroCatastaleRowModelTest.php`
Expected: FAIL, `column "area" does not exist` / `sheet_number` null.

- [ ] **Step 3: aggiungi le colonne alla create**, dopo `$table->text('link');`:

```php
            // Valori calcolati dal parser per l'aggancio al codice, salvati per
            // filtrare l'elenco delle righe in Nova (oc:8700). Il numero e' quello
            // a due cifre del catasto: `162` e' settore 1, numero 62. Nullable:
            // una riga con solo il link non ha numero, e un'area ambigua resta
            // senza area.
            $table->char('area', 1)->nullable();
            $table->char('sector', 1)->nullable();
            $table->unsignedSmallInteger('number')->nullable();
            $table->char('variant', 1)->default('0');
```

e accanto agli indici esistenti:

```php
            $table->index(['area', 'sector', 'number']);
            $table->index('sheet_name');
```

- [ ] **Step 4: aggiorna il model**

In `app/Models/RegistroCatastaleRow.php`: aggiungi `'area', 'sector', 'number', 'variant'` a `$fillable`, le `@property` (`?string $area`, `?string $sector`, `?int $number`, `string $variant`, `-read ?string $sheet_number`), `'number' => 'integer'` nei `$casts`, e l'accessor:

```php
    /**
     * Il numero come si legge sul foglio (oc:8700): settore + numero a due
     * cifre + variante, senza la variante `'0'` che vuol dire «nessuna».
     * `null` per una riga con solo il link.
     */
    protected function sheetNumber(): Attribute
    {
        return Attribute::get(fn () => $this->sector === null || $this->number === null
            ? null
            : $this->sector.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT).($this->variant !== '0' ? $this->variant : ''));
    }
```

con `use Illuminate\Database\Eloquent\Casts\Attribute;`.

- [ ] **Step 5: scrivi le colonne nell'importer**, nell'array `$mirror[]` di `RegistroCatastaleImporter.php:134-140`:

```php
                'area' => $row->area,
                'sector' => $row->sector,
                'number' => $row->number,
                'variant' => $row->variant,
```

- [ ] **Step 6: lancia i test e verifica che passino**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale tests/Unit/RegistroCatastale`
Expected: PASS. Se un test esistente crea righe con `RegistroCatastaleRow::create()` senza le colonne nuove, passa comunque: sono nullable.

- [ ] **Step 7: ricrea la tabella nel DB locale** (il DB locale è sacrificabile) e rilancia l'import:

```bash
docker exec php-forestas php artisan tinker --execute="Schema::dropIfExists('registro_catastale_rows'); DB::table('migrations')->where('migration', '2026_09_29_000001_create_registro_catastale_rows_table')->delete();"
docker exec php-forestas php artisan migrate
docker exec php-forestas php artisan forestas:registro-import
docker exec postgres-forestas psql -U forestas -d forestas -tAc "select count(*), count(area), count(number) from registro_catastale_rows"
```

Expected: circa 822 righe, la grande maggioranza con area e numero.

- [ ] **Step 8: commit (istruzione per il dev)**

```bash
git add database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php app/Models/RegistroCatastaleRow.php app/Services/RegistroCatastale/RegistroCatastaleImporter.php tests/Feature/RegistroCatastale/
git commit -m "feat(oc:8700): il mirror del registro salva area, settore, numero e variante"
```

---

### Task 2: Policy delle righe e detail riusabile

**File:**
- Crea: `app/Policies/RegistroCatastaleRowPolicy.php`
- Modifica: `app/Providers/AppServiceProvider.php:67-69`
- Modifica: `app/Nova/RegistroTabRenderer.php`
- Modifica: `lang/it.json`, `lang/en.json`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleRowPolicyTest.php`, `tests/Feature/TrailRegistry/RegistroTabTest.php`

**Interfacce:**
- Consuma: `TrailRegistryPolicy::allows(?Authenticatable $user): bool` (package).
- Produce: `RegistroTabRenderer::renderRowDetail(RegistroCatastaleRow $row): string`, usato dal Task 3.

- [ ] **Step 1: scrivi i test che falliscono**

`tests/Feature/RegistroCatastale/RegistroCatastaleRowPolicyTest.php`:

```php
<?php

use App\Models\RegistroCatastaleRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Le righe del registro seguono la regola del Catasto (oc:8700), letta dal
 * package: se la regola cambia li', cambia anche qui.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => RolesAndPermissionsService::seedDatabase());

it('vedono le righe solo Administrator ed Editor, e nessuno le modifica', function (string $role, bool $sees) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $row = new RegistroCatastaleRow;

    expect($user->can('viewAny', RegistroCatastaleRow::class))->toBe($sees)
        ->and($user->can('view', $row))->toBe($sees)
        ->and($user->can('create', RegistroCatastaleRow::class))->toBeFalse()
        ->and($user->can('update', $row))->toBeFalse()
        ->and($user->can('delete', $row))->toBeFalse();
})->with([
    ['Administrator', true],
    ['Editor', true],
    ['Validator', false],
    ['Contributor', false],
]);
```

In `RegistroTabTest.php`, con l'helper `creaCodiceRegistro()` già presente:

```php
it('il detail di una riga agganciata e uguale alla Tab del codice (oc:8700)', function () {
    $code = creaCodiceRegistro();
    $row = RegistroCatastaleRow::create([
        'sheet_gid' => '0', 'sheet_name' => 'AREA G', 'row_number' => 5,
        'cells' => [['header' => 'Numero', 'value' => '506C']],
        'link' => '', 'trail_registry_code_id' => $code->id, 'imported_at' => '2026-10-05 10:00:00',
    ]);

    expect(RegistroTabRenderer::renderRowDetail($row))->toBe(RegistroTabRenderer::render($code));
});

it('il detail di una riga non agganciata rimanda alle anomalie (oc:8700)', function () {
    $row = RegistroCatastaleRow::create([
        'sheet_gid' => '0', 'sheet_name' => 'AREA G', 'row_number' => 7,
        'cells' => [['header' => 'Numero', 'value' => '102']],
        'link' => '', 'trail_registry_code_id' => null, 'imported_at' => '2026-10-05 10:00:00',
    ]);

    expect(RegistroTabRenderer::renderRowDetail($row))
        ->toContain('102')
        ->toContain(e(__('Questa riga non è agganciata a nessun codice.')));
});
```

- [ ] **Step 2: verifica l'isolamento, lancia i test e verifica che falliscano**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleRowPolicyTest.php tests/Feature/TrailRegistry/RegistroTabTest.php`
Expected: FAIL (`viewAny` vero per tutti senza policy; `renderRowDetail` non esiste).

- [ ] **Step 3: scrivi la Policy**

```php
<?php

namespace App\Policies;

use App\Models\RegistroCatastaleRow;
use Illuminate\Contracts\Auth\Authenticatable;
use Wm\WmPackage\TrailRegistry\Policies\TrailRegistryPolicy;

/**
 * Le righe del registro catastale (oc:8700) fanno parte del Catasto: le vede
 * chi vede il Catasto, con la regola del package (TrailRegistryPolicy::allows),
 * mai riscritta qui. Senza una policy Nova non protegge il detail aperto per
 * URL. Il mirror lo scrive solo l'import: nessuna scrittura da Nova.
 */
class RegistroCatastaleRowPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return TrailRegistryPolicy::allows($user);
    }

    public function view(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return TrailRegistryPolicy::allows($user);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function restore(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }

    public function replicate(Authenticatable $user, RegistroCatastaleRow $row): bool
    {
        return false;
    }
}
```

In `AppServiceProvider::boot()`, accanto alle altre: `Gate::policy(RegistroCatastaleRow::class, RegistroCatastaleRowPolicy::class);`.

- [ ] **Step 4: rendi riusabile il renderer**

In `app/Nova/RegistroTabRenderer.php` aggiungi, dopo `render()`:

```php
    /**
     * Il detail di una riga nell'elenco «Righe del registro» (oc:8700): la
     * stessa tabella della Tab, dallo stesso metodo, cosi' le due viste non
     * divergono. Per una riga senza codice aggiunge dove cercarne il motivo:
     * numero prenotato, anomalia o righe multiple li distingue solo la lista
     * delle anomalie, filtro provenienza Registro.
     */
    public static function renderRowDetail(RegistroCatastaleRow $row): string
    {
        $html = static::renderRow($row);

        if ($row->trail_registry_code_id !== null) {
            return $html;
        }

        return '<p style="margin-bottom:12px">'
            .e(__('Questa riga non è agganciata a nessun codice.')).' '
            .e(__('Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro.'))
            .'</p>'.$html;
    }
```

Il test «uguale alla Tab» confronta con `render($code)`, che per una riga agganciata restituisce `renderRow($row)`: il risultato è identico.

- [ ] **Step 5: aggiungi le traduzioni** in `lang/it.json` e `lang/en.json`:

```json
"Questa riga non è agganciata a nessun codice.": "Questa riga non è agganciata a nessun codice.",
"Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro.": "Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro."
```

in `it.json`, e in `en.json`:

```json
"Questa riga non è agganciata a nessun codice.": "This row is not linked to any code.",
"Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro.": "If it ended up as an anomaly, you will find it in Catasto › Anomalies, source filter Registry."
```

- [ ] **Step 6: lancia i test e verifica che passino**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleRowPolicyTest.php tests/Feature/TrailRegistry/RegistroTabTest.php`
Expected: PASS.

- [ ] **Step 7: commit (istruzione per il dev)**

```bash
git add app/Policies/RegistroCatastaleRowPolicy.php app/Providers/AppServiceProvider.php app/Nova/RegistroTabRenderer.php lang/it.json lang/en.json tests/Feature/RegistroCatastale/RegistroCatastaleRowPolicyTest.php tests/Feature/TrailRegistry/RegistroTabTest.php
git commit -m "feat(oc:8700): policy delle righe del registro e detail riusabile"
```

---

### Task 3: Resource «Righe del registro», filtri e menu

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-resource-righe-del-registro-filtri-e-menu)

**File:**
- Crea: `app/Nova/RegistroCatastaleRow.php`
- Crea: `app/Nova/Filters/RegistroRowColumnFilter.php`, `app/Nova/Filters/RegistroRowLinkedFilter.php`
- Modifica: `app/Providers/NovaServiceProvider.php:55-66` (sezione Catasto)
- Modifica: `lang/it.json`, `lang/en.json`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleRowResourceTest.php`

**Interfacce:**
- Consuma: colonne e `sheet_number` (Task 1), `RegistroTabRenderer::renderRowDetail()` e la Policy (Task 2), `App\Nova\TrailRegistryCode` (Resource esistente, `$title = 'code'`).
- Produce: Resource con `uriKey()` `registro-catastale-rows`.

- [ ] **Step 1: scrivi i test HTTP che falliscono**

`tests/Feature/RegistroCatastale/RegistroCatastaleRowResourceTest.php`:

```php
<?php

use App\Models\RegistroCatastaleRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * L'elenco delle righe del registro in Nova (oc:8700): prende il posto del
 * foglio Google quando smettera' di essere aggiornato.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    config(['wm-package.features.trail_registry.enabled' => true]);
});

function rigaRegistro(array $overrides = []): RegistroCatastaleRow
{
    static $riga = 1;

    return RegistroCatastaleRow::create(array_merge([
        'sheet_gid' => '0', 'sheet_name' => 'AREA G (Nuoro+SS+Gallura)', 'row_number' => ++$riga,
        'cells' => [['header' => 'Numero', 'value' => 'x']], 'link' => '',
        'area' => 'G', 'sector' => '1', 'number' => 1, 'variant' => '0',
        'trail_registry_code_id' => null, 'imported_at' => '2026-10-05 10:00:00',
    ], $overrides));
}

function editor(): User
{
    $user = User::factory()->create();
    $user->assignRole('Editor');

    return $user;
}

it('un Editor vede l elenco ordinato come il file', function () {
    rigaRegistro(['sheet_name' => 'AREA T', 'row_number' => 2]);
    rigaRegistro(['sheet_name' => 'AREA B', 'row_number' => 9]);
    rigaRegistro(['sheet_name' => 'AREA B', 'row_number' => 3]);

    $ids = $this->actingAs(editor())->getJson('/nova-api/registro-catastale-rows')
        ->assertOk()->json('resources.*.id.value');

    $ordine = RegistroCatastaleRow::whereIn('id', $ids)->get()->sortBy(fn ($r) => array_search($r->id, $ids))
        ->map(fn ($r) => $r->sheet_name.'#'.$r->row_number)->values()->all();

    expect($ordine)->toBe(['AREA B#3', 'AREA B#9', 'AREA T#2']);
});

it('la ricerca 101 trova 101 e 101A in tutte le tab', function () {
    $a = rigaRegistro(['sector' => '1', 'number' => 1, 'variant' => '0']);
    $b = rigaRegistro(['sheet_name' => 'AREA B', 'sector' => '1', 'number' => 1, 'variant' => 'A']);
    rigaRegistro(['sector' => '1', 'number' => 2, 'variant' => '0']);
    // Riga con solo il link: nessun numero, nessun errore.
    rigaRegistro(['sector' => null, 'number' => null]);

    $ids = $this->actingAs(editor())->getJson('/nova-api/registro-catastale-rows?search=101')
        ->assertOk()->json('resources.*.id.value');

    expect($ids)->toEqualCanonicalizing([$a->id, $b->id]);
});

function filtroNova(string $key, string $value): string
{
    return base64_encode(json_encode([['class' => $key, 'value' => $value]]));
}

it('il filtro Agganciato a un codice no tiene solo le righe senza codice', function () {
    $track = EcTrack::factory()->createQuietly();
    $code = TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'SU', 'area' => 'G', 'sector' => '5', 'number' => 6, 'variant' => 'C',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ]);
    rigaRegistro(['trail_registry_code_id' => $code->id]);
    $senzaCodice = rigaRegistro();

    $ids = $this->actingAs(editor())
        ->getJson('/nova-api/registro-catastale-rows?filters='.filtroNova(RegistroRowLinkedFilter::class, 'no'))
        ->assertOk()->json('resources.*.id.value');

    expect($ids)->toBe([$senzaCodice->id]);
});

it('il filtro Tab tiene solo le righe di quella tab', function () {
    $g = rigaRegistro(['sheet_name' => 'AREA G']);
    rigaRegistro(['sheet_name' => 'AREA B']);

    $ids = $this->actingAs(editor())
        ->getJson('/nova-api/registro-catastale-rows?filters='.filtroNova('registro-row-sheet_name', 'AREA G'))
        ->assertOk()->json('resources.*.id.value');

    expect($ids)->toBe([$g->id]);
});

it('Validator e Contributor ricevono 403 su index e detail', function (string $role) {
    $row = rigaRegistro();
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/nova-api/registro-catastale-rows')->assertForbidden();
    $this->actingAs($user)->getJson("/nova-api/registro-catastale-rows/{$row->id}")->assertForbidden();
})->with(['Validator', 'Contributor']);
```

Import aggiuntivi in testa al file: `App\Nova\Filters\RegistroRowLinkedFilter`, `Wm\WmPackage\Models\EcTrack`, `Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus`, `Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode`. I filtri Area e Settore si provano come il filtro Tab, con chiave `registro-row-area` e `registro-row-sector`. La chiave del filtro nel parametro `filters` è quella restituita da `key()`: se Nova usa un'altra chiave, leggila dalla risposta di `/nova-api/registro-catastale-rows/filters` e adegua `filtroNova()`.

- [ ] **Step 2: verifica l'isolamento, lancia i test e verifica che falliscano**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleRowResourceTest.php`
Expected: FAIL, 404 su `/nova-api/registro-catastale-rows`.

- [ ] **Step 3: scrivi i filtri**

`app/Nova/Filters/RegistroRowColumnFilter.php` — un filtro per colonna, con le opzioni lette dal mirror come fanno i filtri del package (`TrailCodeAreaFilter`):

```php
<?php

namespace App\Nova\Filters;

use App\Models\RegistroCatastaleRow;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Filtra le righe del registro su una colonna del mirror (oc:8700): Tab
 * (`sheet_name`), Area, Settore. Le opzioni sono i valori presenti, non un
 * elenco fisso: le tab e le aree sono del foglio, non del codice.
 *
 * Tab e Area oggi danno le stesse righe (ogni tab e' un'area): l'Area resta
 * perche' una tab nuova che non corrisponde a un'area si noterebbe.
 */
class RegistroRowColumnFilter extends Filter
{
    public $component = 'select-filter';

    public function __construct(private string $column, private string $label) {}

    public function key(): string
    {
        return 'registro-row-'.$this->column;
    }

    public function name(): string
    {
        return __($this->label);
    }

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where($this->column, $value);
    }

    public function options(NovaRequest $request): array
    {
        return RegistroCatastaleRow::query()
            ->whereNotNull($this->column)
            ->distinct()
            ->orderBy($this->column)
            ->pluck($this->column)
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
```

`app/Nova/Filters/RegistroRowLinkedFilter.php`:

```php
<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Righe agganciate o no a un codice del catasto (oc:8700). Il motivo di una
 * riga senza codice — numero prenotato, anomalia, righe multiple — non sta
 * qui: lo dice la lista delle anomalie, e duplicarlo vorrebbe dire tenere due
 * elenchi allineati.
 */
class RegistroRowLinkedFilter extends Filter
{
    public $component = 'select-filter';

    public function name(): string
    {
        return __('Linked to a code');
    }

    public function apply(NovaRequest $request, $query, $value)
    {
        return $value === 'yes'
            ? $query->whereNotNull('trail_registry_code_id')
            : $query->whereNull('trail_registry_code_id');
    }

    public function options(NovaRequest $request): array
    {
        return [__('Yes') => 'yes', __('No') => 'no'];
    }
}
```

- [ ] **Step 4: scrivi la Resource**

`app/Nova/RegistroCatastaleRow.php`:

```php
<?php

namespace App\Nova;

use App\Models\RegistroCatastaleRow as RegistroCatastaleRowModel;
use App\Nova\Filters\RegistroRowColumnFilter;
use App\Nova\Filters\RegistroRowLinkedFilter;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Query\Search\Column;
use Wm\WmPackage\TrailRegistry\Nova\HidesWhenTrailRegistryDisabled;

/**
 * L'elenco delle righe del registro catastale (oc:8700): prende il posto del
 * foglio Google quando smettera' di essere aggiornato. Tutte le righe, anche
 * quelle senza codice, in sola lettura: il mirror lo scrive solo l'import, e
 * gli id cambiano a ogni giro.
 *
 * Chi la vede lo decide RegistroCatastaleRowPolicy, con la regola del Catasto
 * del package; il trait la nasconde a dominio spento.
 */
class RegistroCatastaleRow extends Resource
{
    use HidesWhenTrailRegistryDisabled;

    public static $model = RegistroCatastaleRowModel::class;

    public static $title = 'sheet_number';

    public static $globallySearchable = false;

    public static function uriKey(): string
    {
        return 'registro-catastale-rows';
    }

    public static function label(): string
    {
        return __('Registry rows');
    }

    public static function singularLabel(): string
    {
        return __('Registry row');
    }

    /**
     * Il numero non si cerca su `number`: e' il numero a due cifre del
     * catasto (`101` e' settore 1, numero 1), e su PostgreSQL Nova userebbe
     * `ilike` su una colonna intera, che va in errore. Si cerca sul numero
     * ricomposto come sul foglio.
     */
    public static function searchableColumns(): array
    {
        return [Column::raw("concat(sector, lpad(number::text, 2, '0'), nullif(variant, '0'))")];
    }

    /**
     * Come nel file: tab, poi riga dell'Excel. Un orderBy in indexQuery()
     * arriverebbe dopo il latest(id) di Nova e non conterebbe.
     */
    public static function defaultOrderings($query): Builder
    {
        return $query->orderBy('sheet_name')->orderBy('row_number');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make(__('Tab'), 'sheet_name')->onlyOnIndex(),
            Text::make(__('Area'), 'area')->onlyOnIndex(),
            Text::make(__('Sector'), 'sector')->onlyOnIndex(),
            Text::make(__('Number'), 'sheet_number')->onlyOnIndex(),
            BelongsTo::make(__('Code'), 'trailRegistryCode', TrailRegistryCode::class)->onlyOnIndex(),
            Text::make(__('Registry'), fn () => RegistroTabRenderer::renderRowDetail($this->resource))
                ->asHtml()->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new RegistroRowColumnFilter('sheet_name', 'Tab'),
            new RegistroRowColumnFilter('area', 'Area'),
            new RegistroRowColumnFilter('sector', 'Sector'),
            new RegistroRowLinkedFilter,
        ];
    }
}
```

> Nota per chi implementa: verifica la firma di `defaultOrderings()` in `vendor/laravel/nova/src/PerformsQueries.php:160` e copiala esattamente. Se il trait `HidesWhenTrailRegistryDisabled` si appoggia a metodi che `App\Nova\Resource` non ha, leggilo e adegua. Se `$title` su un accessor dà problemi, usa `public function title()` che restituisce `$this->sheet_number ?? '—'`.

- [ ] **Step 5: voce di menu**

In `app/Providers/NovaServiceProvider.php`, dentro `MenuSection::make(__('Catasto'), [ ... ])`, prima del link alla documentazione API SUS:

```php
                    // Le righe del registro catastale (oc:8700): le voci del
                    // package (Istanze, Codici, Anomalie) le mette prima il
                    // package; questa segue, e la documentazione API resta in coda.
                    MenuItem::resource(\App\Nova\RegistroCatastaleRow::class),
```

- [ ] **Step 6: traduzioni**, in `lang/it.json` e `lang/en.json`, controllando prima quali chiavi esistono già (`Code` c'è):

| Chiave | it | en |
|---|---|---|
| `Registry rows` | Righe del registro | Registry rows |
| `Tab` | Tab | Tab |
| `Area` | Area | Area |
| `Sector` | Settore | Sector |
| `Number` | Numero | Number |
| `Linked to a code` | Agganciato a un codice | Linked to a code |
| `Yes` | Sì | Yes |
| `No` | No | No |

- [ ] **Step 7: lancia i test e verifica che passino**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale tests/Feature/TrailRegistry`
Expected: PASS.

- [ ] **Step 8: verifica in Nova con il DB locale** (container attivi): accedi come Editor, apri Catasto › Righe del registro, prova il filtro Tab «AREA G», la ricerca `101`, il detail di una riga agganciata e di una non agganciata. Poi accedi come Validator: la sezione Catasto non deve comparire.

- [ ] **Step 9: commit (istruzione per il dev)**

```bash
git add app/Nova/RegistroCatastaleRow.php app/Nova/Filters/RegistroRowColumnFilter.php app/Nova/Filters/RegistroRowLinkedFilter.php app/Providers/NovaServiceProvider.php lang/it.json lang/en.json tests/Feature/RegistroCatastale/RegistroCatastaleRowResourceTest.php
git commit -m "feat(oc:8700): elenco filtrabile delle righe del registro catastale in Nova"
```

---

### Task 4: submodule, documentazione e verifica finale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-submodule-documentazione-e-verifica-finale)

**File:**
- Modifica: puntatore del submodule `wm-package`
- Modifica: `docs/knowledge/registro-catastale.md`
- Modifica: `.claude/rules/nova.md` (se serve una riga di trappola)

- [ ] **Step 1: aggiorna il submodule** al commit del package che contiene il piano del package (Task 1-4 di `wm-package/.../plan.md`). Istruzione per il dev, dopo il merge del package:

```bash
git add wm-package
git commit -m "chore(oc:8700): aggiorna wm-package con la policy del Catasto"
```

- [ ] **Step 2: aggiorna `docs/knowledge/registro-catastale.md`**: in «Come funziona oggi» le colonne normalizzate (area, settore, numero a due cifre, variante `'0'`) e la Resource «Righe del registro» (cosa mostra, filtri, ricerca sul numero ricomposto, detail condiviso con la Tab, visibilità Administrator/Editor); in «Perché così» le scelte di questo ticket con `(oc:8700)`: colonne normalizzate invece del jsonb, filtro Agganciato senza motivo, Area tenuta insieme a Tab.

- [ ] **Step 3: suite e PHPStan**

Run: `docker exec php-forestas vendor/bin/pest` (dopo la verifica dell'isolamento) e `docker exec php-forestas vendor/bin/phpstan analyse`.
Expected: PASS, nessun errore nuovo.

- [ ] **Step 4: istruzioni per UAT (le esegue il team, non Claude)**: ricreare la tabella **insieme al deploy**, non dopo, perché il codice nuovo sulla tabella vecchia fa fallire `ImportRegistroCatastaleJob` e con lui la scrittura dei valori sui sentieri (oc:8540):

```bash
docker exec php-forestasuat php artisan tinker --execute="Schema::dropIfExists('registro_catastale_rows'); DB::table('migrations')->where('migration', '2026_09_29_000001_create_registro_catastale_rows_table')->delete();"
docker exec php-forestasuat php artisan migrate --force
docker exec php-forestasuat php artisan forestas:registro-import
```

Non usare `migrate:rollback --step`: toglierebbe anche le altre migration dello stesso batch.
