<?php

use App\Models\RegistroCatastaleRow;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

// La creazione di un codice puo' accodare il ricalcolo del DEM: qui
// interessa solo l'import, non quell'effetto (stesso pattern usato altrove).
beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['forestas.registro_catastale.url' => 'https://docs.google.com/spreadsheets/d/FILE/edit']);
});

/**
 * Un'unica riga di intestazione riconosciuta dal parser come registro:
 * area, settore, numero e link, nell'ordine atteso da RegistroCatastaleRowParser.
 */
function registroHeader(): string
{
    return "Cod. Provincia e Area,SETTORE,Numero,Link SardegnaSENTIERI\n";
}

/** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows */
function registroCsv(array $rows): string
{
    return registroHeader().collect($rows)->map(fn (array $r) => implode(',', $r))->implode("\n");
}

function fakeHtmlview(array $gidToName): string
{
    $items = collect($gidToName)
        ->map(fn (string $name, string $gid) => 'items.push({name: "'.$name.'", pageUrl: "https:\/\/x", gid: "'.$gid.'"});')
        ->implode("\n");

    return "<script>{$items}</script>";
}

/**
 * Prepara Http::fake per un giro con un solo foglio di registro (gid "0").
 */
function fakeSingleSheet(string $csv): void
{
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(fakeHtmlview(['0' => 'Z-SU-D'])),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
    ]);
}

it('scrive righe e anomalie del registro e lascia quelle del catasto', function () {
    $track = EcTrack::factory()->createQuietly(['properties' => ['forestas' => ['source_id' => '123']]]);

    TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'NU', 'area' => 'B', 'sector' => '1', 'number' => 5, 'variant' => '0',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ]);

    $catastoAnomaly = TrailRegistryAnomaly::create([
        'type' => TrailRegistryAnomalyType::CodiceIlleggibile->value,
        'source' => TrailRegistryAnomaly::SOURCE_CATASTO,
        'created_at' => now(),
    ]);

    fakeSingleSheet(registroCsv([
        ['Z-NU-B', '1', '105', 'https://x/node/123'],
        ['Z-NU-B', '1', '299', ''],
    ]));

    $result = app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::count())->toBe(2)
        ->and(RegistroCatastaleRow::whereNotNull('trail_registry_code_id')->count())->toBe(1)
        ->and($result->consistentRows)->toBe(1)
        ->and($result->anomaliesByType[RegistroAnomalyTypes::LINK_ORFANO] ?? 0)->toBe(0);

    // La riga senza link e senza traccia agganciata e' un numero prenotato:
    // non e' un'anomalia (il cliente l'ha chiesto esplicitamente).
    expect(TrailRegistryAnomaly::query()->fromSource(RegistroAnomalyTypes::SOURCE)->count())->toBe(0);

    // L'anomalia del catasto, gia' presente prima del run, resta intatta.
    expect(TrailRegistryAnomaly::query()->fromSource(TrailRegistryAnomaly::SOURCE_CATASTO)->count())->toBe(1)
        ->and(TrailRegistryAnomaly::find($catastoAnomaly->id))->not->toBeNull();
});

it('rifiuta con un eccezione chiara un anomalyType non noto a TrailRegistryAnomalyTypes', function () {
    // Nessun tipo del registro e' dichiarato: anche registro_link_orfano
    // (prodotto qui dal link senza traccia e senza ripiego possibile)
    // diventa "sconosciuto" agli occhi di TrailRegistryAnomalyTypes::isKnown().
    config(['wm-package.features.trail_registry.anomaly_types' => []]);

    fakeSingleSheet(registroCsv([
        ['Z-NU-B', '9', '999', 'https://sardegnasentieri.it/node/senza-traccia'],
    ]));

    expect(fn () => app(RegistroCatastaleImporter::class)->run())
        ->toThrow(LogicException::class, 'registro_link_orfano');
});

it('se un foglio non si scarica non tocca mirror e anomalie del giro prima', function () {
    // Due Http::fake() nello stesso test non si sovrascrivono a vicenda (i
    // gestori si accodano, e vince il primo che combacia con l'URL): la
    // seconda richiesta allo stesso foglio va simulata con una sequenza di
    // risposte sulla stessa chiamata Http::fake().
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(fakeHtmlview(['0' => 'Z-SU-D'])),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::sequence()
            ->push(registroCsv([['Z-NU-B', '1', '105', 'https://sardegnasentieri.it/qualcosa']]), 200, ['Content-Type' => 'text/csv'])
            ->push('<html>Accedi</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    app(RegistroCatastaleImporter::class)->run();

    $rowsBefore = RegistroCatastaleRow::count();
    $anomaliesBefore = TrailRegistryAnomaly::query()->fromSource(RegistroAnomalyTypes::SOURCE)->count();

    expect($rowsBefore)->toBe(1)->and($anomaliesBefore)->toBe(1);

    // Secondo giro: il foglio risponde con una pagina di login invece del CSV.
    expect(fn () => app(RegistroCatastaleImporter::class)->run())
        ->toThrow(RegistroCatastaleReadException::class);

    expect(RegistroCatastaleRow::count())->toBe($rowsBefore)
        ->and(TrailRegistryAnomaly::query()->fromSource(RegistroAnomalyTypes::SOURCE)->count())->toBe($anomaliesBefore);
});

it('una riga sparita dal foglio sparisce dal mirror', function () {
    // Stessa ragione del test precedente: la seconda lettura dello stesso
    // foglio, con meno righe, va simulata con una sequenza di risposte.
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(fakeHtmlview(['0' => 'Z-SU-D'])),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::sequence()
            ->push(registroCsv([
                ['Z-NU-B', '1', '105', 'https://sardegnasentieri.it/uno'],
                ['Z-NU-B', '1', '106', 'https://sardegnasentieri.it/due'],
            ]), 200, ['Content-Type' => 'text/csv'])
            ->push(registroCsv([
                ['Z-NU-B', '1', '105', 'https://sardegnasentieri.it/uno'],
            ]), 200, ['Content-Type' => 'text/csv']),
    ]);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::count())->toBe(2);

    app(RegistroCatastaleImporter::class)->run();

    expect(RegistroCatastaleRow::count())->toBe(1)
        ->and(RegistroCatastaleRow::first()->row_number)->toBe(2);
});

it('il contesto di ogni anomalia porta foglio, riga e link', function () {
    fakeSingleSheet(registroCsv([
        ['Z-NU-B', '1', '105', 'https://sardegnasentieri.it/orfano'],
    ]));

    app(RegistroCatastaleImporter::class)->run();

    $anomaly = TrailRegistryAnomaly::query()->fromSource(RegistroAnomalyTypes::SOURCE)->firstOrFail();

    expect($anomaly->type->value ?? $anomaly->type)->toBe(RegistroAnomalyTypes::LINK_ORFANO)
        ->and($anomaly->context['sheet'])->toBe('Z-SU-D')
        ->and($anomaly->context['row'])->toBe(2)
        ->and($anomaly->context['link'])->toBe('https://sardegnasentieri.it/orfano');
});

it('il risultato elenca tutti i fogli letti, anche quelli che non sono registro', function () {
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(fakeHtmlview(['0' => 'Z-SU-D', '7' => 'Legenda'])),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response(registroCsv([
            ['Z-NU-B', '1', '105', ''],
            ['Z-NU-B', '1', '106', ''],
        ]), 200, ['Content-Type' => 'text/csv']),
        'docs.google.com/spreadsheets/d/FILE/export*gid=7*' => Http::response("Campo Shape,Tipo\nnome,testo\n", 200, ['Content-Type' => 'text/csv']),
    ]);

    $result = app(RegistroCatastaleImporter::class)->run();

    expect($result->sheets)->toBe([
        ['name' => 'Z-SU-D', 'gid' => '0', 'rows' => 2, 'registro' => true],
        ['name' => 'Legenda', 'gid' => '7', 'rows' => 1, 'registro' => false],
    ])
        ->and($result->logContext()['sheets'])->toBe($result->sheets)
        ->and($result->rowsBySheet)->toBe(['Z-SU-D' => 2]);
});

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
