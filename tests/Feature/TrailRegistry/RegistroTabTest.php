<?php

use App\Models\RegistroCatastaleRow;
use App\Nova\RegistroTabRenderer;
use App\Nova\TrailRegistryAnomaly;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use App\Services\RegistroCatastale\RegistroCatastaleMatcher;
use App\Services\RegistroCatastale\RegistroCatastaleParsedRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly as TrailRegistryAnomalyModel;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

/**
 * La Tab «Registro» sulla scheda di un codice (oc:8539): mostra la riga del
 * foglio agganciata a quel codice, oppure — se quella riga e' finita in
 * anomalia — l'avviso con il link, oppure la frase che dice che il foglio
 * non ha questo codice.
 */
uses(RefreshDatabase::class);

function creaCodiceRegistro(array $overrides = []): TrailRegistryCode
{
    $track = EcTrack::factory()->createQuietly();

    return TrailRegistryCode::create(array_merge([
        'region' => 'Z', 'province' => 'SU', 'area' => 'G', 'sector' => '5', 'number' => 6, 'variant' => 'C',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ], $overrides));
}

it('la Tab Registro elenca le celle della riga del codice', function () {
    $code = creaCodiceRegistro();

    RegistroCatastaleRow::create([
        'sheet_gid' => '0',
        'sheet_name' => 'Z-SU-D',
        'row_number' => 34,
        'cells' => [
            ['header' => 'Cod. Provincia e Area', 'value' => 'Z-SU-G'],
            ['header' => 'SETTORE', 'value' => '5'],
            ['header' => 'Numero', 'value' => '<script>06C</script>'],
        ],
        'link' => 'https://x/node/1',
        'trail_registry_code_id' => $code->id,
        'imported_at' => '2026-09-27 10:00:00',
    ]);

    $html = RegistroTabRenderer::render($code);

    expect($html)
        ->toContain('Cod. Provincia e Area')
        ->toContain('SETTORE')
        ->toContain('Z-SU-G')
        // Il valore contiene HTML grezzo: deve uscire con escape, non eseguito.
        ->toContain('&lt;script&gt;06C&lt;/script&gt;')
        ->toContain('Z-SU-D')
        ->toContain('34')
        ->toContain('27/09/2026');
    expect($html)->not->toContain('<script>06C</script>');
});

/**
 * Scrive un'anomalia con la stessa forma che produce il giro vero:
 * `ec_track_id` e `context` presi dal matcher, piu' foglio, gid, riga e link
 * aggiunti da RegistroCatastaleImporter.
 */
function anomaliaDalMatcher(RegistroCatastaleParsedRow $row): TrailRegistryAnomalyModel
{
    $matcher = app(RegistroCatastaleMatcher::class);
    $matcher->prepare();
    $match = $matcher->match($row);

    return TrailRegistryAnomalyModel::create([
        'type' => $match->anomalyType,
        'source' => RegistroAnomalyTypes::SOURCE,
        'ec_track_id' => $match->ecTrackId,
        'created_at' => now(),
        'context' => [
            'sheet' => $row->sheetName,
            'gid' => $row->gid,
            'row' => $row->rowNumber,
            'link' => $row->link,
            ...$match->context,
        ],
    ]);
}

function rigaDelFoglio(string $link, string $area, string $sector, int $number, string $variant): RegistroCatastaleParsedRow
{
    return new RegistroCatastaleParsedRow(
        gid: '0',
        sheetName: 'Z-SU-D',
        rowNumber: 34,
        cells: [],
        link: $link,
        area: $area,
        sector: $sector,
        number: $number,
        variant: $variant,
    );
}

it('se la riga del codice e in anomalia la Tab mostra il link all anomalia', function () {
    Bus::fake();

    $track = EcTrack::factory()->createQuietly(['properties' => ['forestas' => ['source_id' => '2576']]]);
    $code = creaCodiceRegistro(['ec_track_id' => $track->id]);

    // Il foglio scrive 507 dove il catasto ha 506: numero diverso.
    $anomaly = anomaliaDalMatcher(rigaDelFoglio('https://www.sardegnasentieri.it/node/2576', 'G', '5', 7, 'C'));

    expect($anomaly->type)->toBe(RegistroAnomalyTypes::NUMERO_DIVERSO)
        ->and($anomaly->ec_track_id)->toBe($track->id)
        ->and($anomaly->context['code'])->toBe($code->code);

    $html = RegistroTabRenderer::render($code);

    expect($html)->toContain('/nova/resources/trail-registry-anomalies/'.$anomaly->id);
    expect($html)->not->toContain('The registry has no row for this code');
});

it('il codice fra i candidati di un ripiego ambiguo porta all anomalia', function () {
    Bus::fake();

    // Stesso area/settore/numero/variante in due province: il ripiego senza
    // link non sa scegliere, e l'anomalia non ha traccia. La Tab la trova
    // dai codici candidati.
    $code = TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'SU', 'area' => 'D', 'sector' => '3', 'number' => 32, 'variant' => '0',
        'status' => TrailCodeStatus::Reserved, 'trail_application_id' => TrailApplication::factory()->create()->id,
    ]);
    TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'CA', 'area' => 'D', 'sector' => '3', 'number' => 32, 'variant' => '0',
        'status' => TrailCodeStatus::Reserved, 'trail_application_id' => TrailApplication::factory()->create()->id,
    ]);

    $anomaly = anomaliaDalMatcher(rigaDelFoglio('', 'D', '3', 32, '0'));

    expect($anomaly->type)->toBe(RegistroAnomalyTypes::RIPIEGO_AMBIGUO)
        ->and($anomaly->ec_track_id)->toBeNull();

    expect(RegistroTabRenderer::render($code))->toContain('/nova/resources/trail-registry-anomalies/'.$anomaly->id);
});

it('senza riga ne anomalia la Tab dice che il foglio non ha questo codice', function () {
    $code = creaCodiceRegistro();

    $html = RegistroTabRenderer::render($code);

    expect($html)->toContain('The registry has no row for this code');
});

it('il titolo di un anomalia del registro e foglio e riga', function () {
    $anomaly = new TrailRegistryAnomalyModel([
        'type' => RegistroAnomalyTypes::LINK_ORFANO,
        'source' => RegistroAnomalyTypes::SOURCE,
        'context' => [
            'sheet' => 'Z-SU-D',
            'gid' => '0',
            'row' => 34,
            'link' => 'https://x/orfano',
        ],
    ]);
    $anomaly->exists = true;

    $resource = new TrailRegistryAnomaly($anomaly);

    expect($resource->title())->toBe('Z-SU-D · row 34');

    app()->setLocale('it');

    expect($resource->title())->toBe('Z-SU-D · riga 34');
});

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
