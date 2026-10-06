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

it('scrive updated_at con il fuso dell\'app, non quello della sessione del database', function () {
    $track = writerTrack();
    writerFakeSheet(['lunghezza' => '4.200', 'origine' => 'S.S. 125', 'meta' => 'Iscacari']);

    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-03-10 15:30:00', config('app.timezone')));

    try {
        app(RegistroCatastaleImporter::class)->run();
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }

    expect(DB::table('ec_tracks')->where('id', $track->id)->value('updated_at'))->toBe('2026-03-10 15:30:00');
});
