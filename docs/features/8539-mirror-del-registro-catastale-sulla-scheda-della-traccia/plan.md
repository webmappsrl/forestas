> Ticket: oc:8539

# Mirror del registro catastale — piano di implementazione (forestas)

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` (consigliata) o
> `superpowers:executing-plans`. Gli step usano le checkbox (`- [ ]`).
>
> **Nessun commit, `git add`, push o branch automatico.** Le righe «Commit» sono istruzioni per il
> dev, che committa da sé.
>
> **Prerequisito:** il piano del package
> (`wm-package/docs/features/8539-mirror-del-registro-catastale-sulla-scheda-della-traccia/plan.md`)
> è implementato sul branch `feature/oc-8539-…` del submodule. Questo piano lo usa.

**Goal:** ogni notte, finito l'import da Sardegna Sentieri, forestas scarica il registro catastale
(Google Sheet), aggancia ogni riga al codice del Catasto e la mostra nella Tab «Registro» del
codice, oppure la registra come anomalia del registro.

**Architecture:** un reader scarica i fogli (gid ricavati da `htmlview`, CSV pubblico) e riconosce
le colonne chiave per intestazione; un parser pulisce area e numero; un matcher aggancia la riga al
codice (link → traccia → codice, ripiego sul numero senza provincia) e classifica la riga come
consistente o anomalia; un importer scrive mirror e anomalie in una transazione. Un job accodato
alla fine del batch dell'import Drupal lo esegue; le sottoclassi Nova del Catasto mostrano il
risultato.

**Tech Stack:** Laravel 12, PHP 8.4 nel container, Nova 5.7.6, PostgreSQL + PostGIS, Horizon, Pest.

**Spec:** [overview.md](overview.md) di questa cartella e l'overview del package.

## Global Constraints

- Test solo dopo aver verificato l'isolamento: `phpunit.xml` con `DB_DATABASE=forestas_testing`,
  `.env.testing` presente, DB `forestas_testing` esistente (`.claude/rules/test.md`). Comando:
  `docker exec php-forestas php artisan test --filter=<nome>` oppure
  `docker exec php-forestas vendor/bin/pest --filter=<nome>`.
- `Http::preventStrayRequests()` in ogni test che passa dal reader: nessun test esce verso Google.
- Configurazione letta da `config()`, mai `env()` nel codice (il deploy fa `optimize`).
- Traduzioni in `lang/en.json` e `lang/it.json`, chiave in inglese e valore italiano in `it.json`.
- `vendor/bin/pint` solo sui file toccati.
- Documentazione, commenti e commit in italiano.
- Fonte di verità: Drupal. Il foglio non corregge nulla.

## Review Focus

1. **Area vuota nelle righe successive di un foglio** (foglio `Z-SU-D`): la riga eredita l'area
   della riga sopra; senza, il ripiego fallisce su decine di righe — test nel Task 3.
2. **Link con prefissi `/en/` o `/index.php/`, barra finale, `http`, caratteri codificati:** stessa
   traccia del link pulito — test nel Task 5.
3. **Un foglio non scaricabile a metà giro** (HTML di login al posto del CSV): mirror e anomalie del
   registro restano quelli del giro prima — test nel Task 6.
4. **Riga di un codice finita in anomalia:** la Tab «Registro» del codice mostra l'avviso con il
   link all'anomalia invece di restare vuota — test nel Task 8.
5. **Normalize saltato** (soglia dei job falliti o `--only`): il job del registro non parte — test
   nel Task 7.

---

### Task 1: Bump del package e sottoclassi Nova del Catasto

**Files:**
- Modify: puntatore del submodule `wm-package` (branch del package di questo ticket)
- Create: `app/Nova/TrailRegistryCode.php`, `app/Nova/TrailApplication.php`,
  `app/Nova/TrailRegistryAnomaly.php`
- Test: `tests/Feature/TrailRegistry/TrailRegistryResourcesTest.php`

**Interfaces:**
- Produces: `App\Nova\TrailRegistryCode`, `App\Nova\TrailApplication`,
  `App\Nova\TrailRegistryAnomaly`, sottoclassi di quelle del package, registrate da
  `Nova::resourcesIn(app_path('Nova'))`.

- [ ] **Step 1: test che fallisce**

```php
<?php

use Laravel\Nova\Nova;

it('Nova usa le Resource del Catasto di forestas', function () {
    Nova::resourcesIn(app_path('Nova'));

    expect(Nova::resourceForKey('trail-registry-codes'))->toBe(\App\Nova\TrailRegistryCode::class);
    expect(Nova::resourceForKey('trail-registry-anomalies'))->toBe(\App\Nova\TrailRegistryAnomaly::class);
    expect(Nova::resourceForKey('trail-applications'))->toBe(\App\Nova\TrailApplication::class);
});
```

- [ ] **Step 2: eseguirlo e vederlo fallire.**

- [ ] **Step 3: implementazione** — tre classi, per ora vuote:

```php
<?php

namespace App\Nova;

use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as WmTrailRegistryCode;

class TrailRegistryCode extends WmTrailRegistryCode {}
```

(analoghe per `TrailApplication` e `TrailRegistryAnomaly`).

- [ ] **Step 4: eseguire il test e aprire Nova in locale** — la sezione «Catasto» mostra
  Istanze, Registro dei codici, Anomalie e la documentazione API SUS.

- [ ] **Step 5: commit (a cura del dev)** — bump del submodule e sottoclassi nello **stesso**
  commit: `feat(oc:8539): Resource del Catasto estese in forestas`

---

### Task 2: Reader del foglio Google

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-reader-del-foglio-google)

**Files:**
- Modify: `config/forestas.php`, `.env-example`, `.env-deploy`
- Create: `app/Services/RegistroCatastale/RegistroCatastaleSheetReader.php`
- Create: `app/Services/RegistroCatastale/RegistroCatastaleSheet.php` (valore: gid, nome, righe)
- Create: `app/Services/RegistroCatastale/RegistroCatastaleReadException.php`
- Create: `tests/Fixtures/registro/htmlview.html`, `tests/Fixtures/registro/sheet_registro.csv`,
  `tests/Fixtures/registro/sheet_legenda.csv` — estratti ridotti del file reale (poche righe)
- Test: `tests/Unit/RegistroCatastale/RegistroCatastaleSheetReaderTest.php` (usa `Http::fake`,
  quindi va in `tests/Feature` se serve il container Laravel: in quel caso
  `tests/Feature/RegistroCatastale/…`)

**Interfaces:**
- Produces:
  - `RegistroCatastaleSheetReader::readAll(): list<RegistroCatastaleSheet>` — tutti i fogli del file
    (registro e legende); lancia `RegistroCatastaleReadException` se la pagina non elenca fogli o se
    un foglio non restituisce un CSV.
  - `RegistroCatastaleSheet { public string $gid; public string $name; public array $rows; }` —
    `$rows` è `list<list<string>>`, prima riga = intestazioni.

- [ ] **Step 1: test che falliscono**

```php
<?php

use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use App\Services\RegistroCatastale\RegistroCatastaleSheetReader;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['forestas.registro_catastale.url' => 'https://docs.google.com/spreadsheets/d/FILE/edit']);
});

it('ricava i gid dalla pagina htmlview e scarica ogni foglio', function () {
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/htmlview.html'))),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/sheet_registro.csv')), 200, ['Content-Type' => 'text/csv']),
        'docs.google.com/spreadsheets/d/FILE/export*gid=1001546814*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/sheet_legenda.csv')), 200, ['Content-Type' => 'text/csv']),
    ]);

    $sheets = app(RegistroCatastaleSheetReader::class)->readAll();

    expect(collect($sheets)->pluck('gid')->all())->toBe(['0', '1001546814']);
    expect($sheets[0]->rows[0][19])->toBe('Link SardegnaSENTIERI');
});

it('si ferma se la pagina non elenca nessun foglio', function () {
    Http::fake(['*htmlview*' => Http::response('<html></html>')]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'nessun foglio');

it('si ferma se un foglio risponde con HTML invece del CSV', function () {
    Http::fake([
        '*htmlview*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/htmlview.html'))),
        '*export*' => Http::response('<html>Accedi</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'non è un CSV');
```

La fixture `htmlview.html` contiene solo i due elementi che il reader cerca, ricopiati dalla pagina
reale: `id="sheet-button-0"` con il nome del foglio e `id="sheet-button-1001546814"`. Prima di
scriverla, scaricare la pagina vera (`curl -sL "<url>/htmlview"`) e verificare il formato degli
elementi dei fogli: il reader si appoggia a quello.

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

`config/forestas.php`:

```php
/*
| Registro catastale dei sentieri (oc:8539): il file Google che Forestas
| mantiene con il CAI, condiviso con chi ha il link. Solo l'URL: i fogli si
| ricavano a ogni giro.
*/
'registro_catastale' => [
    'url' => env('REGISTRO_CATASTALE_URL'),
],
```

`.env-example` e `.env-deploy`: `REGISTRO_CATASTALE_URL=https://docs.google.com/spreadsheets/d/12WYaRr_gtwxdiWTBvmzeUQQcGQjqJ_UsGwI0DylPOtk/edit`
(l'URL non è un segreto: è già nella description del ticket).

Reader:

```php
<?php

namespace App\Services\RegistroCatastale;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RegistroCatastaleSheetReader
{
    /** @return list<RegistroCatastaleSheet> */
    public function readAll(): array
    {
        $base = $this->baseUrl();
        $sheets = [];

        foreach ($this->sheetIndex($base) as $gid => $name) {
            $response = Http::timeout(60)->retry(2, 2000)
                ->get("{$base}/export", ['format' => 'csv', 'gid' => $gid]);

            if (! $response->successful() || ! Str::contains((string) $response->header('Content-Type'), 'text/csv')) {
                throw new RegistroCatastaleReadException("Il foglio «{$name}» (gid {$gid}) non è un CSV: il file è ancora condiviso con chi ha il link?");
            }

            $sheets[] = new RegistroCatastaleSheet((string) $gid, $name, $this->parseCsv($response->body()));
        }

        return $sheets;
    }

    /** @return array<string, string> gid => nome del foglio */
    private function sheetIndex(string $base): array
    {
        $html = Http::timeout(60)->retry(2, 2000)->get("{$base}/htmlview")->body();

        // Non e' un'interfaccia documentata da Google: se cambia formato,
        // qui non si trova nulla e l'import si ferma senza toccare il mirror.
        preg_match_all('/id="sheet-button-(\d+)"[^>]*>(?:<a[^>]*>)?([^<]+)/', $html, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            throw new RegistroCatastaleReadException('La pagina htmlview non elenca nessun foglio.');
        }

        return collect($matches)->mapWithKeys(fn (array $m) => [$m[1] => html_entity_decode(trim($m[2]))])->all();
    }

    private function baseUrl(): string
    {
        $url = (string) config('forestas.registro_catastale.url');

        if (! preg_match('#^(https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+)#', $url, $m)) {
            throw new RegistroCatastaleReadException('forestas.registro_catastale.url non è l\'URL di un foglio Google.');
        }

        return $m[1];
    }

    /** @return list<list<string>> */
    private function parseCsv(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }
}
```

La regex degli elementi dei fogli va allineata al formato verificato nello Step 1; se il formato
reale è diverso, si corregge la regex e la fixture insieme.

- [ ] **Step 4: eseguire i test.**

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): lettura del registro catastale da Google Sheet`

---

### Task 3: Parser delle righe (colonne chiave, area e numero)

**Files:**
- Create: `app/Services/RegistroCatastale/RegistroCatastaleRowParser.php`
- Create: `app/Services/RegistroCatastale/RegistroCatastaleRow.php` (valore)
- Test: `tests/Unit/RegistroCatastale/RegistroCatastaleRowParserTest.php`

**Interfaces:**
- Consumes: `RegistroCatastaleSheet` (Task 2).
- Produces:
  - `RegistroCatastaleRowParser::isRegistro(RegistroCatastaleSheet $sheet): bool`
  - `RegistroCatastaleRowParser::parse(RegistroCatastaleSheet $sheet): list<RegistroCatastaleRow>` —
    solo righe con un numero leggibile o un link; lancia `RegistroCatastaleReadException` se una
    colonna chiave compare due volte.
  - `RegistroCatastaleRow { string $gid; string $sheetName; int $rowNumber; array $cells; string $link; ?string $area; ?string $sector; ?int $number; string $variant; }` —
    `$cells` è `list<array{header: string, value: string}>`; `$area` è la lettera d'area (`B`),
    `$sector` la cifra del settore, `$number` 0–99, `$variant` `'0'` se assente.

- [ ] **Step 1: test che falliscono**

```php
<?php

use App\Services\RegistroCatastale\RegistroCatastaleRowParser;
use App\Services\RegistroCatastale\RegistroCatastaleSheet;

function sheetWith(array $dataRows, array $headers = null): RegistroCatastaleSheet
{
    $headers ??= ['Cod. Provincia e Area', 'SETTORE', 'Numero', 'Origine (da)', ...array_fill(0, 15, 'x'), 'Link Sardegna SENTIERI'];

    return new RegistroCatastaleSheet('0', 'Z-SU-D', [$headers, ...$dataRows]);
}

function row(string $area, string $sector, string $numero, string $link = ''): array
{
    return [$area, $sector, $numero, 'Origine', ...array_fill(0, 15, ''), $link];
}

it('riconosce il registro dalle intestazioni chiave, con grafie diverse', function () {
    expect((new RegistroCatastaleRowParser)->isRegistro(sheetWith([])))->toBeTrue();
    expect((new RegistroCatastaleRowParser)->isRegistro(new RegistroCatastaleSheet('1', 'Legenda', [['Campo Shape', 'Tipo']])))->toBeFalse();
});

it('pulisce numero e variante nei formati del foglio reale', function (string $numero, int $number, string $variant) {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-NU-B', '1', $numero)]));

    expect($rows[0]->number)->toBe($number)->and($rows[0]->variant)->toBe($variant);
})->with([
    ['162', 62, '0'],
    ['163A', 63, 'A'],
    ['182 A', 82, 'A'],
    ['302/A', 2, 'A'],
    ['100 (tappa Sentiero Italia)', 0, '0'],
    ['210S.I.', 10, '0'],
    ['800.', 0, '0'],
]);

it('eredita l area dalla riga sopra quando e vuota', function () {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-SU-D', '2', '200'), row('', '2', '201')]));

    expect($rows[1]->area)->toBe('D');
});

it('con due aree nella stessa cella tiene la lettera d area comune', function () {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-SU-D Z-CA-D', '2', '207')]));

    expect($rows[0]->area)->toBe('D');
});

it('ignora le righe di servizio', function () {
    expect((new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-NU-B', '', '34 numeri disponibili')])))->toBe([]);
});

it('conserva intestazioni ripetute come colonne distinte', function () {
    $headers = ['Cod. Provincia e Area', 'SETTORE', 'Numero', 'Comuni di appartenenza', 'Comuni di appartenenza', ...array_fill(0, 14, 'x'), 'Link SardegnaSENTIERI'];
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([['Z-TT-G', '1', '100', 'Luras', 'Calangianus', ...array_fill(0, 14, ''), '']], $headers));

    expect(collect($rows[0]->cells)->where('header', 'Comuni di appartenenza')->pluck('value')->all())
        ->toBe(['Luras', 'Calangianus']);
});
```

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

```php
<?php

namespace App\Services\RegistroCatastale;

class RegistroCatastaleRowParser
{
    private const KEY_HEADERS = [
        'area' => 'codprovinciaearea',
        'sector' => 'settore',
        'number' => 'numero',
        'link' => 'linksardegnasentieri',
    ];

    public function isRegistro(RegistroCatastaleSheet $sheet): bool
    {
        $found = $this->keyColumns($sheet->rows[0] ?? []);

        return count(array_filter($found, fn (array $positions) => $positions !== [])) === count(self::KEY_HEADERS);
    }

    /** @return list<RegistroCatastaleRow> */
    public function parse(RegistroCatastaleSheet $sheet): array
    {
        $headers = $sheet->rows[0] ?? [];
        $columns = $this->keyColumns($headers);

        foreach ($columns as $key => $positions) {
            if (count($positions) > 1) {
                throw new RegistroCatastaleReadException("Il foglio «{$sheet->name}» ha due colonne «{$key}»: non si sceglie a caso quale leggere.");
            }
        }

        $result = [];
        $lastArea = null;

        foreach (array_slice($sheet->rows, 1) as $index => $values) {
            $cell = fn (string $key) => trim($values[$columns[$key][0]] ?? '');

            $area = $this->areaLetter($cell('area')) ?? $lastArea;
            $lastArea = $area;
            [$sector, $number, $variant] = $this->number($cell('number'));
            $link = $cell('link');

            if ($number === null && $link === '') {
                continue;
            }

            $result[] = new RegistroCatastaleRow(
                gid: $sheet->gid,
                sheetName: $sheet->name,
                rowNumber: $index + 2,
                cells: array_map(fn ($header, $i) => ['header' => (string) $header, 'value' => (string) ($values[$i] ?? '')], $headers, array_keys($headers)),
                link: $link,
                area: $area,
                sector: $sector ?? ($cell('sector') !== '' ? $cell('sector') : null),
                number: $number,
                variant: $variant,
            );
        }

        return $result;
    }

    /** @return array<string, list<int>> */
    private function keyColumns(array $headers): array
    {
        $normalized = array_map(fn ($h) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $h)), $headers);

        return array_map(
            fn (string $wanted) => array_keys(array_filter($normalized, fn ($h) => $h === $wanted)),
            self::KEY_HEADERS,
        );
    }

    /** `Z-NU-B` → `B`; `NU-G` → `G`; `Z-SU-D Z-CA-D` → `D` (la provincia non conta). */
    private function areaLetter(string $raw): ?string
    {
        preg_match_all('/[A-Z]{2}-([A-Z])\b/', strtoupper($raw), $m);
        $letters = array_unique($m[1] ?? []);

        return count($letters) === 1 ? $letters[0] : null;
    }

    /**
     * `162` → settore 1, numero 62; `163A`, `182 A`, `302/A` → variante A.
     * Note e punteggiatura dopo il numero si scartano.
     *
     * @return array{0: ?string, 1: ?int, 2: string}
     */
    private function number(string $raw): array
    {
        if (! preg_match('/^\s*(?:[A-Z]-)?(\d)(\d{2})\s*[\/-]?\s*([A-Za-z])?(?![A-Za-z.])/', $raw, $m)) {
            return [null, null, '0'];
        }

        return [$m[1], (int) $m[2], isset($m[3]) && $m[3] !== '' ? strtoupper($m[3]) : '0'];
    }
}
```

`210S.I.` e `800.`: la lookahead `(?![A-Za-z.])` impedisce che `S` diventi variante; se la regex
non basta sui casi del test, si corregge la regex, non il test.

- [ ] **Step 4: eseguire i test.**

- [ ] **Step 5: rieseguire il parser sui 7 fogli reali** (script in tinker con il reader del Task 2)
  e confrontare il numero di righe con numero leggibile con quanto emerso nel controllo a campione:
  nessuna riga con link e numero deve restare senza numero, salvo le 2 righe senza numero trovate
  nel controllo.

- [ ] **Step 6: commit (a cura del dev)** — `feat(oc:8539): lettura dinamica delle righe del registro`

---

### Task 4: Tabella del mirror

**Files:**
- Create: `database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php`
- Create: `app/Models/RegistroCatastaleRow.php`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleRowModelTest.php`

**Interfaces:**
- Produces: tabella `registro_catastale_rows` e modello `App\Models\RegistroCatastaleRow` con
  `trailRegistryCode(): BelongsTo`; colonne:
  `id`, `sheet_gid`, `sheet_name`, `row_number`, `cells` (jsonb, elenco ordinato), `link`,
  `trail_registry_code_id` (FK `cascadeOnDelete`, nullable), `imported_at`.

- [ ] **Step 1: test che fallisce**

```php
<?php

use App\Models\RegistroCatastaleRow;

it('conserva le celle nell ordine del foglio', function () {
    $row = RegistroCatastaleRow::create([
        'sheet_gid' => '0', 'sheet_name' => 'Z-SU-D', 'row_number' => 34,
        'cells' => [['header' => 'Numero', 'value' => '106'], ['header' => 'Origine (da)', 'value' => 'X']],
        'link' => '', 'imported_at' => now(),
    ]);

    expect($row->fresh()->cells[1]['header'])->toBe('Origine (da)');
});
```

- [ ] **Step 2: eseguirlo e vederlo fallire.**

- [ ] **Step 3: implementazione**

```php
Schema::create('registro_catastale_rows', function (Blueprint $table) {
    $table->id();
    $table->string('sheet_gid', 32);
    $table->string('sheet_name');
    $table->unsignedInteger('row_number');
    // Elenco ordinato di {header, value}: un foglio puo' ripetere
    // un'intestazione (Gallura, «Comuni di appartenenza»), e una mappa
    // perderebbe un valore.
    $table->jsonb('cells');
    $table->text('link');
    // Solo le righe consistenti hanno il codice. La FK porta la riga via con
    // il reset notturno, insieme ai codici: si ricostruiscono nella stessa
    // catena (oc:8539).
    $table->foreignId('trail_registry_code_id')->nullable()->constrained('trail_registry_codes')->cascadeOnDelete();
    $table->timestamp('imported_at');

    $table->unique(['sheet_gid', 'row_number']);
    $table->index('trail_registry_code_id');
});
```

Modello: `$fillable` delle colonne, `$casts = ['cells' => 'array', 'imported_at' => 'datetime']`,
`$timestamps = false`, relazione verso `TrailRegistryClasses::code()`.

- [ ] **Step 4: eseguire il test.**

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): tabella del mirror del registro`

---

### Task 5: Matcher riga → codice

**Files:**
- Create: `app/Services/RegistroCatastale/RegistroCatastaleMatcher.php`
- Create: `app/Services/RegistroCatastale/RegistroCatastaleMatch.php` (valore)
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleMatcherTest.php`

**Interfaces:**
- Consumes: `RegistroCatastaleRow` (Task 3); tracce (`App\Models\EcTrack`) e codici
  (`TrailRegistryClasses::code()`), anomalie del catasto (`TrailRegistryClasses::anomaly()`).
- Produces:
  - `RegistroCatastaleMatcher::prepare(): void` — carica una volta le mappe `source_id → traccia`,
    `url normalizzato → traccia`, `ec_track_id → codice attivo`,
    `area|settore|numero|variante → list<codice>`, `ec_track_id` con anomalie del catasto.
  - `RegistroCatastaleMatcher::match(RegistroCatastaleRow $row): RegistroCatastaleMatch`
  - `RegistroCatastaleMatch { ?int $codeId; ?string $anomalyType; ?int $ecTrackId; array $context; }` —
    `codeId` valorizzato solo se la riga è consistente; `anomalyType` fra le costanti del Task 6, o
    `null` se la riga non è né l'una né l'altra (numero prenotato senza link).
  - `RegistroCatastaleMatcher::normalizeUrl(string $url): string` (statico, per i test).

- [ ] **Step 1: test che falliscono** — con tracce e codici creati dalle factory del package
  (`TrailRegistryCode`, stato `assigned`), un caso per ciascuna regola:

```php
it('aggancia /node/<id> al codice della traccia', function () {
    [$track, $code] = trackWithCode(sourceId: '2576', area: 'G', sector: '5', number: 6, variant: 'C');

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/2576/', area: 'G', sector: '5', number: 6, variant: 'C'));

    expect($match->codeId)->toBe($code->id)->and($match->anomalyType)->toBeNull();
});

it('normalizza gli URL parlanti', function (string $url) {
    expect(RegistroCatastaleMatcher::normalizeUrl($url))
        ->toBe('sardegnasentieri.it/sentiero/sedilo-iloi-g-610');
})->with([
    'https://www.sardegnasentieri.it/sentiero/sedilo-iloi-g-610',
    'http://sardegnasentieri.it/sentiero/sedilo-iloi-g-610/',
    'https://www.sardegnasentieri.it/en/sentiero/sedilo-iloi-g-610',
    'https://www.sardegnasentieri.it/index.php/sentiero/sedilo-iloi-g-610',
]);

it('numero diverso dal codice agganciato → anomalia', function () {
    trackWithCode(sourceId: '2576', area: 'G', sector: '5', number: 6, variant: 'C');

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/2576', area: 'G', sector: '6', number: 2, variant: 'C'));

    expect($match->anomalyType)->toBe(RegistroAnomalyTypes::NUMERO_DIVERSO)->and($match->codeId)->toBeNull();
});

it('la provincia non entra nel confronto', function () { /* codice ZORT510 e riga Z-NU-T 510: consistente */ });
it('link che non porta a nessuna traccia → ripiego sul numero, e se fallisce anomalia link orfano', function () { /* … */ });
it('ripiego con due codici candidati → anomalia ripiego ambiguo', function () { /* … */ });
it('traccia senza codice e senza anomalia del catasto → anomalia', function () { /* … */ });
it('traccia senza codice ma con anomalia del catasto → nessuna anomalia del registro', function () { /* … */ });
it('link vuoto e nessun codice corrispondente → ne consistente ne anomalia', function () { /* … */ });
```

Gli helper `trackWithCode()`, `registroRow()` e `matcher()` stanno in cima al file del test; ogni
caso segnato `/* … */` va scritto per intero con la stessa forma del primo, usando i valori reali
indicati nel titolo (dati del controllo a campione del 28/09/2026).

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione** — regole, in quest'ordine:
  1. link con `/node/(\d+)` → traccia per `properties->forestas->source_id`; altrimenti, se contiene
     `sardegnasentieri`, per `normalizeUrl()` confrontato con `properties->forestas->url`
     normalizzato (host senza `www`, path minuscolo e decodificato, senza `/en`, `/index.php`,
     barra finale);
  2. traccia trovata e con codice attivo: se area, settore, numero e variante del codice coincidono
     con la riga (numero `null` nella riga = non confrontabile, conta come coincidente) →
     consistente; altrimenti anomalia `NUMERO_DIVERSO`, con `code` e `sheet_code` nel contesto;
  3. traccia trovata senza codice: anomalia `TRACCIA_SENZA_CODICE` solo se la traccia non ha
     anomalie con `source = 'catasto'`; altrimenti nessuna anomalia;
  4. traccia non trovata (o link vuoto) e numero leggibile: ripiego per `area|settore|numero|variante`
     sui codici attivi → un codice: consistente; più di uno: `RIPIEGO_AMBIGUO`;
     nessuno: se il link non era vuoto `LINK_ORFANO`, altrimenti nessuna anomalia (numero prenotato);
  5. traccia non trovata e numero illeggibile con link non vuoto: `LINK_ORFANO`.

  Le mappe si caricano una volta con query SQL sulle colonne JSON
  (`properties->'forestas'->>'source_id'`), senza caricare le geometrie.

- [ ] **Step 4: eseguire i test.**

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): aggancio delle righe del registro ai codici`

---

### Task 6: Tipi di anomalia del registro e importer

**Files:**
- Create: `app/Services/RegistroCatastale/RegistroAnomalyTypes.php` (costanti dei tipi, sulla classe)
- Create: `app/Services/RegistroCatastale/AnomalyTypes/LinkOrfano.php`, `NumeroDiverso.php`,
  `TracciaSenzaCodice.php`, `RipiegoAmbiguo.php` (implementano `AnomalyTypeDefinition`)
- Modify: `app/Providers/AppServiceProvider.php` (`register()`: dichiarazione dei tipi al package)
- Create: `app/Services/RegistroCatastale/RegistroCatastaleImporter.php`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleImporterTest.php`

**Interfaces:**
- Consumes: reader (Task 2), parser (Task 3), modello (Task 4), matcher (Task 5).
- Produces:
  - `RegistroAnomalyTypes::SOURCE = 'registro'`, `::LINK_ORFANO = 'registro_link_orfano'`,
    `::NUMERO_DIVERSO = 'registro_numero_diverso'`, `::TRACCIA_SENZA_CODICE = 'registro_traccia_senza_codice'`,
    `::RIPIEGO_AMBIGUO = 'registro_ripiego_ambiguo'` (tutti ≤ 32 caratteri).
  - `RegistroCatastaleImporter::run(): RegistroCatastaleImportResult` con conteggi per foglio,
    righe consistenti, anomalie per tipo.

- [ ] **Step 1: test che falliscono**

```php
it('scrive righe e anomalie del registro e lascia quelle del catasto', function () { /* fogli finti via Http::fake, un'anomalia catasto preesistente: dopo run() c'è ancora */ });
it('se un foglio non si scarica non tocca mirror e anomalie del giro prima', function () {
    // primo run() riuscito, secondo run() con un foglio che risponde HTML:
    // RegistroCatastaleReadException, e conteggi di righe e anomalie invariati.
});
it('una riga sparita dal foglio sparisce dal mirror', function () { /* … */ });
it('il contesto di ogni anomalia porta foglio, riga e link', function () { /* … */ });
```

Ogni caso va scritto per intero, con le fixture del Task 2 e le tracce del Task 5.

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

`AppServiceProvider::register()`:

```php
// Tipi di anomalia del registro catastale (oc:8539): il package li unisce ai
// suoi nella Resource Anomalie. Il nome porta il prefisso della provenienza.
config(['wm-package.features.trail_registry.anomaly_types' => [
    RegistroAnomalyTypes::LINK_ORFANO => LinkOrfano::class,
    RegistroAnomalyTypes::NUMERO_DIVERSO => NumeroDiverso::class,
    RegistroAnomalyTypes::TRACCIA_SENZA_CODICE => TracciaSenzaCodice::class,
    RegistroAnomalyTypes::RIPIEGO_AMBIGUO => RipiegoAmbiguo::class,
]]);
```

Importer:

```php
public function run(): RegistroCatastaleImportResult
{
    // Tutto si scarica e si valida PRIMA di cancellare: un foglio che non
    // risponde lascia intatto il giro precedente.
    $sheets = array_values(array_filter($this->reader->readAll(), fn ($s) => $this->parser->isRegistro($s)));
    $rows = array_merge(...array_map(fn ($s) => $this->parser->parse($s), $sheets));

    $this->matcher->prepare();
    $now = now();
    $mirror = [];
    $anomalies = [];

    foreach ($rows as $row) {
        $match = $this->matcher->match($row);

        $mirror[] = [
            'sheet_gid' => $row->gid, 'sheet_name' => $row->sheetName, 'row_number' => $row->rowNumber,
            'cells' => json_encode($row->cells), 'link' => $row->link,
            'trail_registry_code_id' => $match->codeId, 'imported_at' => $now,
        ];

        if ($match->anomalyType !== null) {
            $anomalies[] = [
                'ec_track_id' => $match->ecTrackId, 'type' => $match->anomalyType,
                'source' => RegistroAnomalyTypes::SOURCE, 'created_at' => $now,
                'context' => json_encode([
                    'sheet' => $row->sheetName, 'gid' => $row->gid, 'row' => $row->rowNumber,
                    'link' => $row->link, ...$match->context,
                ]),
            ];
        }
    }

    DB::transaction(function () use ($mirror, $anomalies) {
        RegistroCatastaleRow::query()->delete();
        TrailRegistryClasses::anomaly()::query()->fromSource(RegistroAnomalyTypes::SOURCE)->delete();

        foreach (array_chunk($mirror, 500) as $chunk) {
            DB::table('registro_catastale_rows')->insert($chunk);
        }
        foreach (array_chunk($anomalies, 500) as $chunk) {
            DB::table('trail_registry_anomalies')->insert($chunk);
        }
    });

    return new RegistroCatastaleImportResult(/* conteggi */);
}
```

Ogni `AnomalyTypeDefinition` restituisce etichetta italiana tramite `__()` e righe di dettaglio
con `e()` su ogni valore del foglio: foglio e riga, valore del link (come link se è un URL
`sardegnasentieri.it`), e per `NUMERO_DIVERSO` il codice del catasto e quello del foglio.

- [ ] **Step 4: eseguire i test.**

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): import del registro catastale con anomalie`

---

### Task 7: Job, comando e aggancio all'import di Sardegna Sentieri

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-job-comando-e-aggancio-allimport-di-sardegna-sentieri)

**Files:**
- Create: `app/Jobs/Import/ImportRegistroCatastaleJob.php`
- Create: `app/Console/Commands/ImportRegistroCatastaleCommand.php` (`forestas:registro-import`)
- Modify: `app/Console/Commands/ImportSardegnaSentieriCommand.php:124-140` (batch anche
  nell'import orario) e `:155-205` (`dispatchImportBatch`: accodare il job)
- Test: `tests/Feature/Import/ImportRegistroCatastaleChainTest.php`

**Interfaces:**
- Consumes: `RegistroCatastaleImporter::run()` (Task 6).
- Produces: `ImportRegistroCatastaleJob` sulla coda `sardegnasentieri-import`, `$timeout = 600`,
  `$tries = 2`; log sul canale `import` con prefisso `[registro-catastale]`.

- [ ] **Step 1: test che falliscono**

```php
it('dopo un normalize riuscito accoda il job del registro', function () { /* Bus::fake, esecuzione della callback finally con 0 job falliti: ImportRegistroCatastaleJob accodato */ });
it('se il normalize viene saltato il job non parte', function () { /* callback con fallimenti oltre soglia: nessun job, log di errore */ });
it('nell import orario i job sono raggruppati in un batch e il registro parte alla fine', function () { /* senza --reset: Bus::assertBatched, nessun normalize, job del registro accodato */ });
it('il comando forestas:registro-import esegue l importer', function () { /* … */ });
```

Guardare `tests/Feature/Import/ImportResetNormalizeTest.php` per come oggi si esercita la callback
del batch, e usare la stessa tecnica. Ogni caso va scritto per intero.

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**
  - `dispatchImportBatch()` riceve `bool $withNormalize` e `bool $reset`: con reset, come oggi, il
    normalize, e solo se riuscito `ImportRegistroCatastaleJob::dispatch()`; senza reset nessun
    normalize (nulla è stato troncato) e il job del registro accodato a fine batch.
  - Nel ramo incrementale (`! $this->option('reset')`) al posto del `foreach ... dispatch($job)` si
    chiama `dispatchImportBatch($jobs, $runId, withNormalize: false, reset: false)`; il messaggio di
    log «I job partono sciolti» va tolto.
  - Con `--only` il job del registro non parte (catasto parziale), e il log lo dice.
  - Il job chiama `RegistroCatastaleImporter::run()` e registra i conteggi; in caso di
    `RegistroCatastaleReadException` registra l'errore e non rilancia (nessun retry utile).

- [ ] **Step 4: eseguire i test**, compresi `ImportResetNormalizeTest` e `ImportResetGuardsTest`.

- [ ] **Step 5: prova reale in locale** — `docker exec php-forestas php artisan forestas:registro-import`
  sul DB locale, poi `horizon:terminate` se Horizon gira con codice vecchio; controllare nel log i 7
  fogli letti e i conteggi, e confrontarli con il controllo a campione.

- [ ] **Step 6: commit (a cura del dev)** — `feat(oc:8539): import del registro dopo quello di Sardegna Sentieri`

---

### Task 8: Tab «Registro» sul codice e Anomalie estese

**Files:**
- Modify: `app/Nova/TrailRegistryCode.php`
- Modify: `app/Nova/TrailRegistryAnomaly.php`
- Create: `app/Nova/Filters/TrailAnomalySourceFilter.php`
- Modify: `lang/en.json`, `lang/it.json`
- Test: `tests/Feature/TrailRegistry/RegistroTabTest.php`

**Interfaces:**
- Consumes: `RegistroCatastaleRow` (Task 4), `RegistroAnomalyTypes` (Task 6), metodi
  sovrascrivibili del package `noticeBody()`, `titleFor()`, `subjectField()`.

- [ ] **Step 1: test che falliscono**

```php
it('la Tab Registro elenca le celle della riga del codice', function () { /* riga agganciata: il campo della Tab contiene intestazioni e valori con escape */ });
it('se la riga del codice e in anomalia la Tab mostra il link all anomalia', function () { /* anomalia NUMERO_DIVERSO con context code = codice: link a /nova/resources/trail-registry-anomalies/<id> */ });
it('senza riga ne anomalia la Tab dice che il foglio non ha questo codice', function () { /* … */ });
it('il titolo di un anomalia del registro e foglio e riga', function () { /* 'Z-SU-D · riga 34' */ });
```

Ogni caso va scritto per intero.

- [ ] **Step 2: eseguirli e vederli fallire.**

- [ ] **Step 3: implementazione**

```php
public function fields(NovaRequest $request): array
{
    return [
        Tab::group(__('Code'), [
            Tab::make(__('Code'), parent::fields($request)),
            Tab::make(__('Registry'), [
                Text::make(__('Registry'), fn () => RegistroTabRenderer::render($this->resource))
                    ->asHtml()->onlyOnDetail(),
            ]),
        ]),
    ];
}
```

`RegistroTabRenderer` (in `app/Nova/`): cerca la riga con `trail_registry_code_id` del codice; se
c'è, tabella a due colonne con `e()` su intestazioni e valori, foglio, riga e data dell'import; se
non c'è, cerca un'anomalia `source = 'registro'` il cui `context->code` è il codice (o la cui
`ec_track_id` è la traccia del codice) e mostra l'avviso con il link; altrimenti la frase «Il
registro non ha una riga per questo codice».

`App\Nova\TrailRegistryAnomaly`: `noticeBody()` spiega le due provenienze; `titleFor()` per
`source = 'registro'` restituisce `"{$context['sheet']} · riga {$context['row']}"`;
`subjectField()` per le anomalie senza traccia mostra `context['link']`; `filters()` aggiunge
`TrailAnomalySourceFilter` (catasto / registro).

Traduzioni: chiavi `Code`, `Registry`, `Source`, `Registry row`, e le frasi di Tab e avvisi, in
`lang/en.json` (valore inglese) e `lang/it.json` (valore italiano).

- [ ] **Step 4: eseguire i test e aprire Nova in locale** su `ZSSG506C` (avviso con link
  all'anomalia) e su un codice consistente (tabella).

- [ ] **Step 5: commit (a cura del dev)** — `feat(oc:8539): Tab Registro e anomalie del registro in Nova`

---

### Task 9: Conoscenza e indice

**Files:**
- Create: `docs/knowledge/registro-catastale.md`
- Modify: `CLAUDE.md` (riga in «Conoscenza»)
- Modify: `docs/features/8539-mirror-del-registro-catastale-sulla-scheda-della-traccia/notes.md`

- [ ] **Step 1:** pagina `docs/knowledge/registro-catastale.md` con «Come funziona oggi» (catena
  import → normalize → registro, lettura dinamica, regole di aggancio, tipi di anomalia) e «Perché
  così» (Drupal fonte di verità, mirror sul codice, lettura dinamica per le colonne diverse, foreign
  key e reset notturno), ogni voce con `(oc:8539)`.
- [ ] **Step 2:** riga in `CLAUDE.md`, sezione «Conoscenza»:
  `| Registro catastale | come si legge il foglio Google, come le righe si agganciano ai codici, quali anomalie produce | [docs/knowledge/registro-catastale.md](docs/knowledge/registro-catastale.md) |`
- [ ] **Step 3:** aggiornare `notes.md` con deviazioni e decisioni emerse in esecuzione.
- [ ] **Step 4: commit (a cura del dev)** — `docs(oc:8539): conoscenza sul registro catastale`

---

## Verifica finale

- [ ] Isolamento del DB di test verificato, poi `docker exec php-forestas vendor/bin/pest` verde.
- [ ] `docker exec php-forestas vendor/bin/phpstan analyse` senza errori nuovi.
- [ ] Prova completa in locale: `sardegnasentieri:import --reset` → normalize → registro; Tab e
  Anomalie controllate in Nova.
- [ ] PR di forestas verso `develop` **dopo** il merge della PR del package.
