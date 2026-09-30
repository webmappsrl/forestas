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
        ->and($anomaly->context['rows'])->toEqual([['sheet' => 'AREA B', 'row' => 2], ['sheet' => 'AREA B', 'row' => 3]])
        ->and($anomaly->context['code'])->toBe($code->code)
        ->and($anomaly->context['row'])->toBe(2);
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
