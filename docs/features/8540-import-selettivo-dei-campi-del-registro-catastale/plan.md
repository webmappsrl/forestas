> Ticket: oc:8540

# Import selettivo dei campi del registro catastale — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dopo il mirror del registro catastale, scrivere sui sentieri origine, destinazione, meta intermedia, lunghezza e tempi del foglio, con un sanitizzatore dei valori e due anomalie nuove.

**Architecture:** `RegistroCatastaleImporter::run()` passa in due giri: prima aggancia tutte le righe, poi applica la regola «una sola riga per codice». Per ogni sentiero con una riga agganciata, `RegistroCatastaleTrackWriter::evaluate()` legge le celle e le fa passare da `RegistroValueSanitizer` (classe pura), producendo le scritture e le anomalie. Tutto si scrive nella transazione già esistente del mirror, con update atomici sul JSONB.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (jsonb), Pest, Nova 5.

**Spec:** `docs/features/8540-import-selettivo-dei-campi-del-registro-catastale/overview.md`. Dipende dal piano del package: `wm-package/docs/features/8540-import-selettivo-dei-campi-del-registro-catastale/plan.md` (chiave `via`), da eseguire per primo.

## Global Constraints

- **Nessun `git commit`, `git add`, `git push` né branch creati in autonomia**: i commit sotto sono istruzioni per il dev.
- **Prima di lanciare qualsiasi test verifica l'isolamento** (`.claude/rules/test.md`): `.env.testing` esiste con `DB_DATABASE=forestas_testing`, e il database `forestas_testing` esiste. Se non è verificabile, non lanciare e chiedi.
- Commenti e documentazione in italiano, termini tecnici in inglese. Ogni classe nuova porta `(oc:8540)` nel docblock.
- Le celle si cercano per **intestazione normalizzata** (minuscole, solo `[a-z0-9]`), come `RegistroCatastaleRowParser::keyColumns()`, mai per lettera.
- Unità in `manual_data`: `distance` in km, `duration_forward`/`duration_backward` in minuti interi.
- Il job non carica e non salva `EcTrack`: solo update atomici sulle chiavi `manual_data.distance`, `manual_data.duration_forward`, `manual_data.duration_backward`, `from`, `to`, `via`.
- Testi nuovi: chiave in italiano con `__()`, traduzione in `lang/it.json` e `lang/en.json`.

## Review Focus

- **Sentiero senza `manual_data`** (3 sentieri sul DB locale): un `jsonb_set` sul percorso annidato `{manual_data,distance}` non crea l'oggetto padre e non scrive nulla, senza errore. La scrittura va fatta con un merge sull'intero `manual_data` — test nel Task 3.
- **Valore invariato**: se `manual_data` contiene già i valori del registro, il giro non deve toccare `updated_at`, altrimenti gli export incrementali riscaricano 578 sentieri ogni ora — test nel Task 3.
- **`from` già valorizzato da Drupal**: non va mai sovrascritto, neanche se il registro ha un testo diverso — test nel Task 3.
- **Codice agganciato da due righe entrambe con link** (due righe che puntano allo stesso nodo): non se ne sceglie nessuna — test nel Task 2.
- **Foglio che non si legge**: nessuna scrittura sui sentieri, come per il mirror — test nel Task 3.

---

### Task 1: `RegistroValueSanitizer`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-sanitizzatore)

**Files:**
- Create: `app/Services/RegistroCatastale/RegistroSanitizedValue.php`
- Create: `app/Services/RegistroCatastale/RegistroValueSanitizer.php`
- Test: `tests/Unit/RegistroCatastale/RegistroValueSanitizerTest.php`

**Interfaces:**
- Produces:
  - `RegistroSanitizedValue`: `static empty(): self`, `static of(int|float $value): self`, `static invalid(): self`, `isEmpty(): bool`, `isInvalid(): bool`, `public readonly int|float|null $value`.
  - `RegistroValueSanitizer::lengthKm(string $raw): RegistroSanitizedValue` (valore `float` in km, arrotondato a 3 decimali).
  - `RegistroValueSanitizer::durationMinutes(string $raw, ?float $lengthKm): RegistroSanitizedValue` (valore `int`).
  - `RegistroValueSanitizer::text(string $raw): ?string`.

- [ ] **Step 1: Scrivi il test che fallisce**

I casi sono i valori fuori formato presenti oggi nel foglio (DB locale, `registro_catastale_rows`), più un campione di quelli regolari.

```php
<?php

use App\Services\RegistroCatastale\RegistroValueSanitizer;

it('legge la lunghezza in metri e la restituisce in km', function (string $raw, float $km) {
    expect((new RegistroValueSanitizer)->lengthKm($raw)->value)->toBe($km);
})->with([
    'intero = metri' => ['100', 0.1],
    'intero a 5 cifre' => ['15973', 15.973],
    'punto delle migliaia' => ['9.967', 9.967],
    'spazio in fondo' => ['1.000 ', 1.0],
    'decimale col punto = km' => ['2.6', 2.6],
    'decimale con la virgola = km' => ['2,35', 2.35],
]);

it('lunghezza vuota e illeggibile si distinguono', function () {
    $s = new RegistroValueSanitizer;

    expect($s->lengthKm('')->isEmpty())->toBeTrue()
        ->and($s->lengthKm('   ')->isEmpty())->toBeTrue()
        ->and($s->lengthKm('9,967')->isInvalid())->toBeTrue()
        ->and($s->lengthKm('circa 3 km')->isInvalid())->toBeTrue();
});

it('legge i tempi del foglio in minuti', function (string $raw, int $minutes) {
    expect((new RegistroValueSanitizer)->durationMinutes($raw, null)->value)->toBe($minutes);
})->with([
    'h:mm' => ['1:30', 90],
    'hh:mm' => ['00:05', 5],
    'spazio iniziale' => [' 0:30', 30],
    'spazi attorno ai due punti' => ['03: 00', 180],
    'lettera O al posto dello zero' => ['O1:30', 90],
    'due punti e punto' => ['01:.20', 80],
    'trattino e punto' => ['01-.30', 90],
    'punto' => ['1.10', 70],
    'testo dopo l\'orario' => ['01:40 antiorario', 100],
    'testo troncato dopo l\'orario' => ['01:40 senso orar', 100],
    'apostrofo = minuti' => ["15'", 15],
]);

it('un intero senza unità si legge con la velocità a piedi della riga', function (string $raw, float $km, int $minutes) {
    expect((new RegistroValueSanitizer)->durationMinutes($raw, $km)->value)->toBe($minutes);
})->with([
    '2 su 5,6 km = ore' => ['2', 5.6, 120],
    '2 su 4,3 km = ore' => ['2', 4.3, 120],
    '1 su 1,9 km = ore' => ['1', 1.9, 60],
    '40 su 993 m = minuti' => ['40', 0.993, 40],
    '5 su 170 m = minuti' => ['5', 0.17, 5],
]);

it('i tempi dubbi o indecidibili sono illeggibili', function (string $raw, ?float $km) {
    expect((new RegistroValueSanitizer)->durationMinutes($raw, $km)->isInvalid())->toBeTrue();
})->with([
    'punti interrogativi' => ['2:45   ???', null],
    'secondo orario nel testo, andata' => ["1:00\n(cartello genna Eidadi dice 1:30)", null],
    'secondo orario nel testo, ritorno' => ["1:00\n(cartello sul 112 dice 1:50)", null],
    'intero senza lunghezza' => ['2', null],
    'intero con nessuna lettura plausibile' => ['30', 0.01],
    'testo' => ['un\'ora circa', null],
]);

it('tempo vuoto è vuoto, non illeggibile', function () {
    expect((new RegistroValueSanitizer)->durationMinutes('', 3.0)->isEmpty())->toBeTrue();
});

it('normalizza i testi a una riga', function (string $raw, ?string $expected) {
    expect((new RegistroValueSanitizer)->text($raw))->toBe($expected);
})->with([
    'a capo' => ["Arcu Su\nMannau", 'Arcu Su Mannau'],
    'a capo e spazi' => ["P.ta Piscina Irgas -Genna de Muru Mannu -\n M.te Lisone", 'P.ta Piscina Irgas -Genna de Muru Mannu - M.te Lisone'],
    'spazi ai bordi' => [' Cantoniera 49 FMS', 'Cantoniera 49 FMS'],
    'vuoto' => ['   ', null],
]);
```

- [ ] **Step 2: Verifica che fallisca**

Run: `docker exec php-forestas vendor/bin/pest tests/Unit/RegistroCatastale/RegistroValueSanitizerTest.php`
Expected: FAIL, classe `RegistroValueSanitizer` non trovata.

- [ ] **Step 3: Implementa**

`app/Services/RegistroCatastale/RegistroSanitizedValue.php`:

```php
<?php

namespace App\Services\RegistroCatastale;

/**
 * L'esito della lettura di una cella numerica del registro (oc:8540): vuota
 * (non si scrive, vale il DEM), letta, oppure illeggibile (non si scrive e
 * diventa un'anomalia). Vuota e illeggibile vanno distinte: solo la seconda
 * e' un problema da far correggere a Forestas.
 */
final class RegistroSanitizedValue
{
    private function __construct(
        public readonly int|float|null $value,
        private readonly bool $empty,
    ) {}

    public static function empty(): self
    {
        return new self(null, true);
    }

    public static function of(int|float $value): self
    {
        return new self($value, false);
    }

    public static function invalid(): self
    {
        return new self(null, false);
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function isInvalid(): bool
    {
        return ! $this->empty && $this->value === null;
    }
}
```

`app/Services/RegistroCatastale/RegistroValueSanitizer.php`:

```php
<?php

namespace App\Services\RegistroCatastale;

/**
 * Legge lunghezza, tempi e testi del registro catastale (oc:8540). Il foglio
 * e' scritto a mano: ogni regola qui corrisponde a una variante che c'e'
 * davvero nel foglio, decisa una per una col dev. Cio' che nessuna regola
 * legge, o che chi ha compilato ha segnato come dubbio, e' illeggibile.
 *
 * Classe pura: niente DB, niente HTTP.
 */
class RegistroValueSanitizer
{
    /** Velocita' a piedi plausibile, in km/h, per decidere se un intero sono ore o minuti. */
    private const MIN_SPEED = 1.0;

    private const MAX_SPEED = 6.0;

    /**
     * «lunghezza (m)»: intero = metri; punto seguito da 3 cifre = migliaia;
     * separatore seguito da 1 o 2 cifre = decimale in km (2,6 m non e' un
     * sentiero).
     */
    public function lengthKm(string $raw): RegistroSanitizedValue
    {
        $value = trim($raw);

        if ($value === '') {
            return RegistroSanitizedValue::empty();
        }

        if (preg_match('/^\d+$/', $value)) {
            return RegistroSanitizedValue::of(round(((int) $value) / 1000, 3));
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return RegistroSanitizedValue::of(round(((int) str_replace('.', '', $value)) / 1000, 3));
        }

        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $value, $m)) {
            return RegistroSanitizedValue::of(round((float) "{$m[1]}.{$m[2]}", 3));
        }

        return RegistroSanitizedValue::invalid();
    }

    /**
     * «T. percorrenza»: `h:mm` in minuti. `$lengthKm` serve solo a decidere
     * un intero senza unita'.
     */
    public function durationMinutes(string $raw, ?float $lengthKm): RegistroSanitizedValue
    {
        $value = trim($raw);

        if ($value === '') {
            return RegistroSanitizedValue::empty();
        }

        // Chi compila segna cosi' un valore di cui non e' sicuro.
        if (str_contains($value, '?')) {
            return RegistroSanitizedValue::invalid();
        }

        // `O1:30`: lettera O al posto dello zero, solo accanto a una cifra.
        $value = preg_replace('/O(?=\d)|(?<=\d)O/', '0', $value);

        if (preg_match("/^(\d+)'$/", $value, $m)) {
            return RegistroSanitizedValue::of((int) $m[1]);
        }

        if (preg_match('/^\d+$/', $value)) {
            return $this->integerByWalkingSpeed((int) $value, $lengthKm);
        }

        if (! preg_match('/^(\d{1,2})\s*(?::\.|-\.|:|\.|-)\s*(\d{2})(.*)$/s', $value, $m)) {
            return RegistroSanitizedValue::invalid();
        }

        // Il testo dopo l'orario si scarta (`antiorario`), ma se contiene un
        // secondo orario (`cartello … dice 1:30`) la cella non e' univoca.
        if (preg_match('/\d{1,2}\s*[:.]\s*\d{2}/', $m[3])) {
            return RegistroSanitizedValue::invalid();
        }

        return RegistroSanitizedValue::of(((int) $m[1]) * 60 + (int) $m[2]);
    }

    /** D, E, F: una riga sola, spazi compressi, vuoto = null. */
    public function text(string $raw): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $value === '' ? null : $value;
    }

    /**
     * Un intero senza unita': vale la lettura (ore o minuti) che con la
     * lunghezza della riga da' una velocita' a piedi plausibile. Se lo sono
     * entrambe, nessuna, o manca la lunghezza, non si decide.
     */
    private function integerByWalkingSpeed(int $n, ?float $lengthKm): RegistroSanitizedValue
    {
        if ($n === 0 || $lengthKm === null || $lengthKm <= 0) {
            return RegistroSanitizedValue::invalid();
        }

        $plausible = fn (float $hours) => ($speed = $lengthKm / $hours) >= self::MIN_SPEED && $speed <= self::MAX_SPEED;

        $asHours = $plausible($n);
        $asMinutes = $plausible($n / 60);

        if ($asHours === $asMinutes) {
            return RegistroSanitizedValue::invalid();
        }

        return RegistroSanitizedValue::of($asHours ? $n * 60 : $n);
    }
}
```

- [ ] **Step 4: Verifica che passi**

Run: lo stesso comando dello Step 2.
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Services/RegistroCatastale/RegistroSanitizedValue.php app/Services/RegistroCatastale/RegistroValueSanitizer.php tests/Unit/RegistroCatastale/RegistroValueSanitizerTest.php
git commit -m "feat(oc:8540): sanitizzatore di lunghezza, tempi e testi del registro catastale"
```

### Task 2: una sola riga per codice e anomalia `RIGHE_MULTIPLE`

**Files:**
- Modify: `app/Services/RegistroCatastale/RegistroCatastaleMatch.php` (proprietà `byLink`)
- Modify: `app/Services/RegistroCatastale/RegistroCatastaleMatcher.php:69` (`matchByTrack`, ramo consistente)
- Modify: `app/Services/RegistroCatastale/RegistroAnomalyTypes.php` (costante `RIGHE_MULTIPLE`)
- Create: `app/Services/RegistroCatastale/AnomalyTypes/RigheMultiple.php`
- Modify: `app/Providers/AppServiceProvider.php:49-54` (registrazione)
- Modify: `app/Services/RegistroCatastale/RegistroCatastaleImporter.php` (due giri)
- Modify: `lang/it.json`, `lang/en.json`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleSingleRowTest.php`

**Interfaces:**
- Produces:
  - `RegistroCatastaleMatch::$byLink` (`bool`, default `false`), ultimo parametro del costruttore.
  - `RegistroAnomalyTypes::RIGHE_MULTIPLE = 'registro_righe_multiple'`.
  - Context dell'anomalia: `sheet`, `gid`, `row`, `link` della prima riga, più `rows` = `list<string>` nella forma `«<foglio>» riga <n>`.
  - Nell'importer, la variabile `$multiTrackIds` (`array<int, true>`) dei sentieri esclusi dalla scrittura, usata nel Task 3.

- [ ] **Step 1: Scrivi il test che fallisce**

Helper con nomi propri: quelli di `RegistroCatastaleImporterTest.php` sono funzioni globali di Pest e ridefinirli darebbe errore.

```php
<?php

use App\Models\RegistroCatastaleRow;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['forestas.registro_catastale.url' => 'https://docs.google.com/spreadsheets/d/FILE/edit']);
});

/** @param  list<list<string>>  $rows  area, settore, numero, link */
function singleRowFakeSheet(array $rows): void
{
    $csv = "Cod. Provincia e Area,SETTORE,Numero,Link SardegnaSENTIERI\n"
        .collect($rows)->map(fn (array $r) => implode(',', $r))->implode("\n");

    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response('<script>items.push({name: "AREA B", pageUrl: "https:\/\/x", gid: "0"});</script>'),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
    ]);
}

function singleRowCode(int $number, ?int $trackId): TrailRegistryCode
{
    return TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '4', 'number' => $number, 'variant' => '0',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $trackId,
    ]);
}

it('fra due righe dello stesso codice resta agganciata quella del link', function () {
    $track = EcTrack::factory()->createQuietly(['properties' => ['forestas' => ['source_id' => '4271']]]);
    $code = singleRowCode(0, $track->id);

    singleRowFakeSheet([
        ['Z-NU-B', '4', '400', 'https://www.sardegnasentieri.it/node/4271'],
        ['Z-NU-B', '4', '400', ''],
    ]);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::where('trail_registry_code_id', $code->id)->pluck('row_number')->all())->toBe([2]);

    $anomaly = TrailRegistryAnomaly::where('type', RegistroAnomalyTypes::RIGHE_MULTIPLE)->sole();
    expect($anomaly->ec_track_id)->toBe($track->id)
        ->and($anomaly->context['rows'])->toBe(['«AREA B» riga 2', '«AREA B» riga 3']);
});

it('se nessuna riga ha il link non se ne aggancia nessuna', function () {
    $track = EcTrack::factory()->createQuietly();
    singleRowCode(2, $track->id);

    singleRowFakeSheet([
        ['Z-NU-B', '4', '402', ''],
        ['Z-NU-B', '4', '402', ''],
    ]);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::whereNotNull('trail_registry_code_id')->count())->toBe(0)
        ->and(TrailRegistryAnomaly::where('type', RegistroAnomalyTypes::RIGHE_MULTIPLE)->count())->toBe(1);
});

it('se due righe hanno il link non si sceglie', function () {
    $track = EcTrack::factory()->createQuietly(['properties' => ['forestas' => ['source_id' => '9']]]);
    singleRowCode(3, $track->id);

    singleRowFakeSheet([
        ['Z-NU-B', '4', '403', 'https://www.sardegnasentieri.it/node/9'],
        ['Z-NU-B', '4', '403', 'https://www.sardegnasentieri.it/node/9'],
    ]);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::whereNotNull('trail_registry_code_id')->count())->toBe(0);
});

it('una riga sola resta agganciata e non produce anomalie', function () {
    $track = EcTrack::factory()->createQuietly();
    singleRowCode(4, $track->id);

    singleRowFakeSheet([['Z-NU-B', '4', '404', '']]);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::whereNotNull('trail_registry_code_id')->count())->toBe(1)
        ->and(TrailRegistryAnomaly::where('type', RegistroAnomalyTypes::RIGHE_MULTIPLE)->count())->toBe(0);
});
```

- [ ] **Step 2: Verifica che fallisca**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleSingleRowTest.php`
Expected: FAIL, costante `RIGHE_MULTIPLE` non definita.

- [ ] **Step 3: Implementa**

`RegistroCatastaleMatch`, ultimo parametro del costruttore:

```php
        public array $context = [],
        /** Agganciata dal link (e non dal ripiego sul numero): vince quando piu' righe cadono sullo stesso codice (oc:8540). */
        public bool $byLink = false,
```

`RegistroCatastaleMatcher::matchByTrack()`, ramo consistente:

```php
            if ($this->numberMatches($code, $row)) {
                return new RegistroCatastaleMatch($code['id'], null, $trackId, byLink: true);
            }
```

`RegistroAnomalyTypes`:

```php
    /** Piu' righe del registro cadono sullo stesso codice: resta agganciata solo quella del link, se e' una (oc:8540). */
    public const RIGHE_MULTIPLE = 'registro_righe_multiple';
```

`app/Services/RegistroCatastale/AnomalyTypes/RigheMultiple.php`:

```php
<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Piu' righe del registro cadono sullo stesso codice (di solito tratti
 * diversi dello stesso numero): il sentiero non riceve valori finche' il
 * foglio non viene sistemato (oc:8540).
 */
class RigheMultiple implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Più righe del registro per lo stesso codice');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $rows = $anomaly->context['rows'] ?? [];

        return [
            ...$this->commonRows($anomaly),
            [__('Righe'), e(implode(', ', is_array($rows) ? $rows : []))],
        ];
    }
}
```

`AppServiceProvider`: aggiungere `RegistroAnomalyTypes::RIGHE_MULTIPLE => RigheMultiple::class,` all'array e lo `use` della classe.

`lang/it.json` e `lang/en.json`:

```json
    "Più righe del registro per lo stesso codice": "Più righe del registro per lo stesso codice",
    "Righe": "Righe",
```

```json
    "Più righe del registro per lo stesso codice": "Multiple registry rows for the same code",
    "Righe": "Rows",
```

`RegistroCatastaleImporter::run()`: sostituire il ciclo unico con due giri. Il primo calcola i match (dopo `$this->matcher->prepare()`, e con `$now = now();` spostato prima), il secondo applica la regola e costruisce mirror e anomalie come oggi:

```php
        $matches = array_map(fn (RegistroCatastaleParsedRow $row) => $this->matcher->match($row), $rows);

        // Una sola riga per codice (oc:8540): fra piu' righe consistenti sullo
        // stesso codice resta quella del link, se e' una sola; le altre (o
        // tutte) si sganciano e il sentiero diventa RIGHE_MULTIPLE.
        $byCode = [];
        foreach ($matches as $i => $match) {
            if ($match->codeId !== null) {
                $byCode[$match->codeId][] = $i;
            }
        }

        $multiTrackIds = [];
        $multiAnomalies = [];

        foreach ($byCode as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $linked = array_values(array_filter($indexes, fn (int $i) => $matches[$i]->byLink));
            $keep = count($linked) === 1 ? $linked[0] : null;

            foreach ($indexes as $i) {
                if ($i !== $keep) {
                    $matches[$i]->codeId = null;
                }
            }

            $first = $rows[$indexes[0]];
            $trackId = $matches[$indexes[0]]->ecTrackId;

            if ($trackId !== null) {
                $multiTrackIds[$trackId] = true;
            }

            $multiAnomalies[] = [
                'ec_track_id' => $trackId,
                'type' => RegistroAnomalyTypes::RIGHE_MULTIPLE,
                'source' => RegistroAnomalyTypes::SOURCE,
                'created_at' => $now,
                'context' => json_encode([
                    'sheet' => $first->sheetName,
                    'gid' => $first->gid,
                    'row' => $first->rowNumber,
                    'link' => $first->link,
                    'rows' => array_map(fn (int $i) => "«{$rows[$i]->sheetName}» riga {$rows[$i]->rowNumber}", $indexes),
                ]),
            ];
        }
```

Nel ciclo esistente `foreach ($rows as $row)` diventa `foreach ($rows as $i => $row)` con `$match = $matches[$i];` al posto della chiamata a `match()`. Dopo il ciclo: `$anomalies = [...$anomalies, ...$multiAnomalies];` e `$anomaliesByType[RegistroAnomalyTypes::RIGHE_MULTIPLE] = count($multiAnomalies)` se maggiore di zero. `$now = now();` va spostato prima del secondo giro.

- [ ] **Step 4: Verifica che passino anche i test esistenti**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale tests/Unit/RegistroCatastale`
Expected: PASS, compresi `RegistroCatastaleImporterTest` e `RegistroCatastaleMatcherTest`.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Services/RegistroCatastale app/Providers/AppServiceProvider.php lang/it.json lang/en.json tests/Feature/RegistroCatastale/RegistroCatastaleSingleRowTest.php
git commit -m "feat(oc:8540): una sola riga del registro per codice, anomalia RIGHE_MULTIPLE"
```

### Task 3: scrittura sui sentieri e anomalia `VALORE_NON_SANITIZZABILE`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-scrittura-sui-sentieri)

**Files:**
- Create: `app/Services/RegistroCatastale/RegistroTrackWrite.php`
- Create: `app/Services/RegistroCatastale/RegistroCatastaleTrackWriter.php`
- Modify: `app/Services/RegistroCatastale/RegistroAnomalyTypes.php` (costante `VALORE_NON_SANITIZZABILE`)
- Create: `app/Services/RegistroCatastale/AnomalyTypes/ValoreNonSanitizzabile.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrazione)
- Modify: `app/Services/RegistroCatastale/RegistroCatastaleImporter.php` (evaluate prima della transazione, apply dentro)
- Modify: `app/Services/RegistroCatastale/RegistroCatastaleImportResult.php` (`tracksUpdated`)
- Modify: `lang/it.json`, `lang/en.json`
- Test: `tests/Feature/RegistroCatastale/RegistroCatastaleTrackWriterTest.php`

`ImportRegistroCatastaleJob` e `ImportRegistroCatastaleCommand` non cambiano: chiamano già `RegistroCatastaleImporter::run()` e scrivono `logContext()`, dove arriva `tracks_updated`.

**Interfaces:**
- Consumes: `RegistroValueSanitizer` (Task 1); `$multiTrackIds` e `$matches` dell'importer (Task 2).
- Produces:
  - `RegistroTrackWrite`: `int $trackId`, `?string $from`, `?string $to`, `?string $via`, `array<string, int|float> $manual`, `list<array{column: string, value: string}> $invalid`.
  - `RegistroCatastaleTrackWriter::evaluate(RegistroCatastaleParsedRow $row, int $trackId): RegistroTrackWrite`.
  - `RegistroCatastaleTrackWriter::apply(RegistroTrackWrite $write): bool` (true se ha cambiato qualcosa).
  - `RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE = 'registro_valore_non_sanitizzabile'`, context: `sheet`, `gid`, `row`, `link`, `column`, `value`.
  - `RegistroCatastaleImportResult::$tracksUpdated` (`int`, default 0, ultimo parametro); `logContext()` aggiunge `tracks_updated`.

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['forestas.registro_catastale.url' => 'https://docs.google.com/spreadsheets/d/FILE/edit']);
});

/**
 * Un foglio con le intestazioni reali delle colonne importate, e una riga
 * agganciata dal link a `node/77`.
 *
 * @param  array<string, string>  $cells  origine, destinazione, meta, lunghezza, andata, ritorno
 */
function writerFakeSheet(array $cells): void
{
    $headers = ['Cod. Provincia e Area', 'SETTORE', 'Numero', 'Origine (da)', 'Destinazione (a)', 'EVENTIUALE Meta intermedia', 'lunghezza (m)', 'T. percorrenza (A)', 'T. percorrenza (R)', 'Link SardegnaSENTIERI'];
    $row = ['Z-NU-B', '4', '400', $cells['origine'] ?? '', $cells['destinazione'] ?? '', $cells['meta'] ?? '', $cells['lunghezza'] ?? '', $cells['andata'] ?? '', $cells['ritorno'] ?? '', 'https://www.sardegnasentieri.it/node/77'];
    $csv = collect([$headers, $row])->map(fn (array $r) => collect($r)->map(fn ($v) => '"'.str_replace('"', '""', $v).'"')->implode(','))->implode("\n");

    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response('<script>items.push({name: "AREA B", pageUrl: "https:\/\/x", gid: "0"});</script>'),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
    ]);
}

function writerTrack(array $properties = []): EcTrack
{
    $track = EcTrack::factory()->createQuietly(['properties' => ['forestas' => ['source_id' => '77'], ...$properties]]);

    TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '4', 'number' => 0, 'variant' => '0',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ]);

    return $track;
}

function writerProperties(EcTrack $track): array
{
    return json_decode(DB::table('ec_tracks')->where('id', $track->id)->value('properties'), true);
}

it('scrive lunghezza e tempi in manual_data, convertiti, e lascia gli altri campi manuali', function () {
    $track = writerTrack(['manual_data' => ['distance' => 9.9, 'ascent' => 300]]);
    writerFakeSheet(['lunghezza' => '4.200', 'andata' => '1:20', 'ritorno' => '2:15']);

    app(RegistroCatastaleImporter::class)->run();

    expect(writerProperties($track)['manual_data'])->toBe(['ascent' => 300, 'distance' => 4.2, 'duration_forward' => 80, 'duration_backward' => 135]);
});

it('crea manual_data se il sentiero non lo ha', function () {
    $track = writerTrack();
    writerFakeSheet(['lunghezza' => '3.400']);

    app(RegistroCatastaleImporter::class)->run();

    expect(writerProperties($track)['manual_data'])->toBe(['distance' => 3.4]);
});

it('una cella vuota non scrive quel campo', function () {
    $track = writerTrack(['manual_data' => ['duration_backward' => 50]]);
    writerFakeSheet(['lunghezza' => '1.000', 'andata' => '0:30', 'ritorno' => '']);

    app(RegistroCatastaleImporter::class)->run();

    expect(writerProperties($track)['manual_data']['duration_backward'])->toBe(50);
});

it('from e to si scrivono solo se vuoti, via sempre, con i testi normalizzati', function () {
    $track = writerTrack(['from' => 'Partenza Drupal', 'to' => '']);
    writerFakeSheet(['origine' => 'S.S. 125', 'destinazione' => "Lago del\nFlumendosa", 'meta' => "Arcu Su\nMannau"]);

    app(RegistroCatastaleImporter::class)->run();

    expect(writerProperties($track))->toMatchArray(['from' => 'Partenza Drupal', 'to' => 'Lago del Flumendosa', 'via' => 'Arcu Su Mannau']);
});

it('un valore illeggibile non si scrive e diventa anomalia', function () {
    $track = writerTrack();
    writerFakeSheet(['lunghezza' => '1.900', 'andata' => '2:45   ???']);

    app(RegistroCatastaleImporter::class)->run();

    $anomaly = TrailRegistryAnomaly::where('type', RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE)->sole();

    expect(writerProperties($track)['manual_data'])->toBe(['distance' => 1.9])
        ->and($anomaly->ec_track_id)->toBe($track->id)
        ->and($anomaly->context)->toMatchArray(['column' => 'T. percorrenza (A)', 'value' => '2:45   ???']);
});

it('un giro che non cambia nulla non tocca updated_at', function () {
    $track = writerTrack();
    writerFakeSheet(['lunghezza' => '4.200', 'meta' => 'Iscacari']);
    app(RegistroCatastaleImporter::class)->run();

    DB::table('ec_tracks')->where('id', $track->id)->update(['updated_at' => '2020-01-01 00:00:00']);
    app(RegistroCatastaleImporter::class)->run();

    expect(DB::table('ec_tracks')->where('id', $track->id)->value('updated_at'))->toBe('2020-01-01 00:00:00');
});

it('un sentiero RIGHE_MULTIPLE non riceve valori', function () {
    $track = writerTrack();
    $headers = ['Cod. Provincia e Area', 'SETTORE', 'Numero', 'lunghezza (m)', 'Link SardegnaSENTIERI'];
    $csv = implode("\n", [implode(',', $headers), 'Z-NU-B,4,400,4200,https://www.sardegnasentieri.it/node/77', 'Z-NU-B,4,400,3400,']);
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response('<script>items.push({name: "AREA B", pageUrl: "https:\/\/x", gid: "0"});</script>'),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
    ]);

    app(RegistroCatastaleImporter::class)->run();

    expect(writerProperties($track))->not->toHaveKey('manual_data');
});

it('se il foglio non si legge non scrive sui sentieri', function () {
    $track = writerTrack();
    Http::fake(['docs.google.com/*' => Http::response('', 500)]);

    expect(fn () => app(RegistroCatastaleImporter::class)->run())->toThrow(\App\Services\RegistroCatastale\RegistroCatastaleReadException::class)
        ->and(writerProperties($track))->not->toHaveKey('manual_data');
});
```

- [ ] **Step 2: Verifica che fallisca**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale/RegistroCatastaleTrackWriterTest.php`
Expected: FAIL (manual_data non scritto, costante `VALORE_NON_SANITIZZABILE` non definita).

- [ ] **Step 3: Implementa**

`app/Services/RegistroCatastale/RegistroTrackWrite.php`:

```php
<?php

namespace App\Services\RegistroCatastale;

/**
 * Cosa una riga del registro scrive su un sentiero (oc:8540), gia' letto e
 * sanitizzato. `null` = non scrivere; `$invalid` = celle illeggibili, che
 * diventano anomalie VALORE_NON_SANITIZZABILE.
 */
final class RegistroTrackWrite
{
    /**
     * @param  array<string, int|float>  $manual  chiavi di manual_data da scrivere
     * @param  list<array{column: string, value: string}>  $invalid
     */
    public function __construct(
        public readonly int $trackId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $via,
        public readonly array $manual,
        public readonly array $invalid,
    ) {}
}
```

`app/Services/RegistroCatastale/RegistroCatastaleTrackWriter.php`:

```php
<?php

namespace App\Services\RegistroCatastale;

use Illuminate\Support\Facades\DB;

/**
 * Porta sul sentiero i campi del registro catastale (oc:8540).
 *
 * Non carica e non salva EcTrack: aggiorna solo le proprie chiavi del JSONB
 * con update atomici, perche' la catena DEM salva l'intero `properties` e due
 * processi che lo fanno insieme si cancellano i valori a vicenda (oc:8660).
 * Niente eventi del modello, quindi nessuna catena DEM accodata.
 */
class RegistroCatastaleTrackWriter
{
    /** Intestazione normalizzata => campo. */
    private const TEXT_COLUMNS = ['origineda' => 'from', 'destinazionea' => 'to'];

    private const LENGTH_COLUMN = 'lunghezzam';

    private const DURATION_COLUMNS = ['tpercorrenzaa' => 'duration_forward', 'tpercorrenzar' => 'duration_backward'];

    /** «EVENTIUALE Meta intermedia»: si cerca il finale, se Forestas corregge il refuso non cambia nulla. */
    private const VIA_SUFFIX = 'metaintermedia';

    /** `manual_data` come oggetto, anche quando manca o e' JSON null. */
    private const MANUAL = "(case when jsonb_typeof(properties->'manual_data') = 'object' then properties->'manual_data' else '{}'::jsonb end)";

    public function __construct(private readonly RegistroValueSanitizer $sanitizer) {}

    public function evaluate(RegistroCatastaleParsedRow $row, int $trackId): RegistroTrackWrite
    {
        $text = [];
        $via = null;
        $manual = [];
        $invalid = [];
        $lengthCell = null;
        $durationCells = [];

        foreach ($row->cells as $cell) {
            $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower($cell['header']));

            if (isset(self::TEXT_COLUMNS[$key])) {
                $text[self::TEXT_COLUMNS[$key]] = $this->sanitizer->text($cell['value']);
            } elseif (str_ends_with($key, self::VIA_SUFFIX)) {
                $via = $this->sanitizer->text($cell['value']);
            } elseif ($key === self::LENGTH_COLUMN) {
                $lengthCell = $cell;
            } elseif (isset(self::DURATION_COLUMNS[$key])) {
                $durationCells[self::DURATION_COLUMNS[$key]] = $cell;
            }
        }

        $lengthKm = null;

        if ($lengthCell !== null) {
            $length = $this->sanitizer->lengthKm($lengthCell['value']);
            $lengthKm = $length->value;

            if ($length->isInvalid()) {
                $invalid[] = ['column' => $lengthCell['header'], 'value' => $lengthCell['value']];
            } elseif (! $length->isEmpty()) {
                $manual['distance'] = $length->value;
            }
        }

        foreach ($durationCells as $field => $cell) {
            $duration = $this->sanitizer->durationMinutes($cell['value'], $lengthKm);

            if ($duration->isInvalid()) {
                $invalid[] = ['column' => $cell['header'], 'value' => $cell['value']];
            } elseif (! $duration->isEmpty()) {
                $manual[$field] = $duration->value;
            }
        }

        return new RegistroTrackWrite($trackId, $text['from'] ?? null, $text['to'] ?? null, $via, $manual, $invalid);
    }

    /**
     * Ogni update ha la sua condizione: un valore gia' uguale non si
     * riscrive, cosi' `updated_at` cambia solo quando cambia il dato e gli
     * export incrementali non riscaricano tutto a ogni giro.
     */
    public function apply(RegistroTrackWrite $write): bool
    {
        $changed = 0;

        if ($write->manual !== []) {
            $json = json_encode($write->manual);
            $changed += DB::update(
                'update ec_tracks set properties = jsonb_set(coalesce(properties, \'{}\'::jsonb), \'{manual_data}\', '.self::MANUAL.' || ?::jsonb), updated_at = now() where id = ? and not ('.self::MANUAL.' @> ?::jsonb)',
                [$json, $write->trackId, $json],
            );
        }

        // Origine e destinazione: vince Drupal, quindi solo se vuote.
        foreach (['from' => $write->from, 'to' => $write->to] as $key => $value) {
            if ($value !== null) {
                $changed += DB::update(
                    "update ec_tracks set properties = jsonb_set(coalesce(properties, '{}'::jsonb), '{{$key}}', to_jsonb(?::text)), updated_at = now() where id = ? and coalesce(properties->>'{$key}', '') = ''",
                    [$value, $write->trackId],
                );
            }
        }

        if ($write->via !== null) {
            $changed += DB::update(
                "update ec_tracks set properties = jsonb_set(coalesce(properties, '{}'::jsonb), '{via}', to_jsonb(?::text)), updated_at = now() where id = ? and properties->>'via' is distinct from ?",
                [$write->via, $write->trackId, $write->via],
            );
        }

        return $changed > 0;
    }
}
```

`RegistroAnomalyTypes`:

```php
    /** Una cella di lunghezza o tempi che il sanitizzatore non legge, o che chi l'ha scritta segna come dubbia (oc:8540). */
    public const VALORE_NON_SANITIZZABILE = 'registro_valore_non_sanitizzabile';
```

`app/Services/RegistroCatastale/AnomalyTypes/ValoreNonSanitizzabile.php`:

```php
<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Una cella di lunghezza o tempi del registro che non si legge, o che chi
 * l'ha scritta segna come dubbia: non si scrive sul sentiero, vale il DEM
 * finche' Forestas non la corregge sul foglio (oc:8540).
 */
class ValoreNonSanitizzabile implements AnomalyTypeDefinition
{
    use RegistroDetailRows;

    public function label(): string
    {
        return __('Valore del registro non interpretabile');
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];

        return [
            ...$this->commonRows($anomaly),
            [__('Colonna'), e((string) ($context['column'] ?? ''))],
            [__('Valore'), e((string) ($context['value'] ?? ''))],
        ];
    }
}
```

`AppServiceProvider`: aggiungere `RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE => ValoreNonSanitizzabile::class,` e lo `use`.

`lang/it.json` e `lang/en.json`:

```json
    "Valore del registro non interpretabile": "Valore del registro non interpretabile",
    "Colonna": "Colonna",
    "Valore": "Valore",
```

```json
    "Valore del registro non interpretabile": "Unreadable registry value",
    "Colonna": "Column",
    "Valore": "Value",
```

`RegistroCatastaleImporter`: iniettare `RegistroCatastaleTrackWriter $writer` nel costruttore. Dopo il ciclo che costruisce mirror e anomalie (Task 2), prima della transazione:

```php
        // Sentieri con una riga agganciata e non esclusi da RIGHE_MULTIPLE (oc:8540).
        $writes = [];

        foreach ($rows as $i => $row) {
            $trackId = $matches[$i]->ecTrackId;

            if ($matches[$i]->codeId === null || $trackId === null || isset($multiTrackIds[$trackId])) {
                continue;
            }

            $write = $this->writer->evaluate($row, $trackId);
            $writes[] = $write;

            foreach ($write->invalid as $cell) {
                $anomalies[] = [
                    'ec_track_id' => $trackId,
                    'type' => RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE,
                    'source' => RegistroAnomalyTypes::SOURCE,
                    'created_at' => $now,
                    'context' => json_encode([
                        'sheet' => $row->sheetName,
                        'gid' => $row->gid,
                        'row' => $row->rowNumber,
                        'link' => $row->link,
                        ...$cell,
                    ]),
                ];
                $anomaliesByType[RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE] = ($anomaliesByType[RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE] ?? 0) + 1;
            }
        }
```

Nella transazione, dopo l'insert delle anomalie:

```php
            foreach ($writes as $write) {
                if ($this->writer->apply($write)) {
                    $tracksUpdated++;
                }
            }
```

con `$tracksUpdated = 0;` prima della transazione, passato per riferimento alla closure (`use (..., &$tracksUpdated)`), e `new RegistroCatastaleImportResult(..., tracksUpdated: $tracksUpdated)`.

`RegistroCatastaleImportResult`: ultimo parametro `public readonly int $tracksUpdated = 0,`; in `logContext()` la chiave `'tracks_updated' => $this->tracksUpdated`.

- [ ] **Step 4: Verifica che passino, con la suite del registro**

Run: `docker exec php-forestas vendor/bin/pest tests/Feature/RegistroCatastale tests/Unit/RegistroCatastale`
Expected: PASS.

- [ ] **Step 5: Prova sul DB locale (sola lettura del risultato)**

Il DB locale è sacrificabile. Run: `docker exec php-forestas php artisan forestas:registro-import`, poi:

```bash
docker exec php-forestas php artisan tinker --execute="dump(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly::query()->whereIn('type', ['registro_righe_multiple', 'registro_valore_non_sanitizzabile'])->get(['type', 'context'])->toArray());"
```

Expected: 3 anomalie `registro_righe_multiple` (sentieri 252, 572, 689), 3 `registro_valore_non_sanitizzabile` (`2:45   ???` e i due «cartello … dice»), e nel log `import` `tracks_updated` maggiore di zero.

- [ ] **Step 6: PHPStan**

Run: `docker exec php-forestas vendor/bin/phpstan analyse`
Expected: nessun errore nuovo.

- [ ] **Step 7: Commit (istruzione per il dev)**

```bash
git add app/Services/RegistroCatastale app/Providers/AppServiceProvider.php lang/it.json lang/en.json tests/Feature/RegistroCatastale/RegistroCatastaleTrackWriterTest.php
git commit -m "feat(oc:8540): il registro catastale scrive origine, destinazione, meta intermedia, lunghezza e tempi sui sentieri"
```

### Task 4: pagina di conoscenza

**Files:**
- Modify: `docs/knowledge/registro-catastale.md`

- [ ] **Step 1:** In «Come funziona oggi» aggiungere la scrittura sui sentieri (colonne, regole, update atomico, una sola riga per codice, le due anomalie nuove). In «Perché così» riscrivere la voce «Drupal resta l'unica fonte di verità» (oc:8539): per lunghezza e tempi ora vince il registro fino al go-live (oc:8540), mentre per origine e destinazione vince Drupal; la voce vecchia va in «Come ci siamo arrivati» con il motivo. Mostrare al dev il prima e il dopo.
