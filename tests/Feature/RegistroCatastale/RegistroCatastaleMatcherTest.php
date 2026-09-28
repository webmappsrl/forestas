<?php

use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use App\Services\RegistroCatastale\RegistroCatastaleMatcher;
use App\Services\RegistroCatastale\RegistroCatastaleParsedRow;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

// La creazione di un codice del registro (assegnato o riservato) puo' accodare
// il ricalcolo del DEM: qui interessa solo l'aggancio, non quell'effetto.
beforeEach(function () {
    Bus::fake();
});

/**
 * Una traccia con un codice assegnato: il caso normale del "trovo il link,
 * trovo la traccia, trovo il codice".
 *
 * @return array{0: EcTrack, 1: TrailRegistryCode}
 */
function trackWithCode(
    string $sourceId,
    string $area,
    string $sector,
    int $number,
    string $variant = '0',
    string $province = 'NU',
): array {
    $track = EcTrack::factory()->createQuietly([
        'properties' => ['forestas' => ['source_id' => $sourceId]],
    ]);

    $code = TrailRegistryCode::create([
        'region' => 'Z',
        'province' => $province,
        'area' => $area,
        'sector' => $sector,
        'number' => $number,
        'variant' => $variant,
        'status' => TrailCodeStatus::Assigned,
        'ec_track_id' => $track->id,
    ]);

    return [$track, $code];
}

/**
 * Una traccia senza codice: source_id valorizzato, nessuna riga in
 * trail_registry_codes con quell'ec_track_id.
 */
function trackOnly(string $sourceId): EcTrack
{
    return EcTrack::factory()->createQuietly([
        'properties' => ['forestas' => ['source_id' => $sourceId]],
    ]);
}

/**
 * Un codice prenotato ma non ancora assegnato a nessuna traccia: nasce da
 * un'istanza, non ha ec_track_id. E' il caso che il ripiego deve trovare.
 */
function reservedCode(
    string $area,
    string $sector,
    int $number,
    string $variant = '0',
    string $province = 'NU',
): TrailRegistryCode {
    $application = TrailApplication::factory()->create();

    return TrailRegistryCode::create([
        'region' => 'Z',
        'province' => $province,
        'area' => $area,
        'sector' => $sector,
        'number' => $number,
        'variant' => $variant,
        'status' => TrailCodeStatus::Reserved,
        'trail_application_id' => $application->id,
    ]);
}

/**
 * Una riga del registro, con solo i campi che il matcher legge.
 */
function registroRow(
    string $link = '',
    ?string $area = null,
    ?string $sector = null,
    ?int $number = null,
    string $variant = '0',
): RegistroCatastaleParsedRow {
    return new RegistroCatastaleParsedRow(
        gid: '0',
        sheetName: 'Z-SU-D',
        rowNumber: 2,
        cells: [],
        link: $link,
        area: $area,
        sector: $sector,
        number: $number,
        variant: $variant,
    );
}

function matcher(): RegistroCatastaleMatcher
{
    $matcher = new RegistroCatastaleMatcher;
    $matcher->prepare();

    return $matcher;
}

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
    [$track, $code] = trackWithCode(sourceId: '2576', area: 'G', sector: '5', number: 6, variant: 'C', province: 'SS');

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/2576', area: 'G', sector: '6', number: 2, variant: 'C'));

    expect($match->anomalyType)->toBe(RegistroAnomalyTypes::NUMERO_DIVERSO)
        ->and($match->codeId)->toBeNull()
        ->and($match->ecTrackId)->toBe($track->id)
        // Il codice del catasto nel contesto e' quello completo, nella stessa
        // forma dell'accessor `code`: e' su questo che la Tab «Registro» cerca.
        ->and($match->context['code'])->toBe('ZSSG506C')
        ->and($match->context['code'])->toBe($code->code)
        ->and($match->context['sheet_code'])->toBe('G602C');
});

it('la provincia non entra nel confronto', function () {
    // Codice ZORT510 (regione Z, provincia OR, area T, settore 5, numero 10):
    // il foglio lo riporta con la provincia storica NU, ma senza link — il
    // ripiego deve trovarlo comunque, ignorando la provincia.
    $code = reservedCode(area: 'T', sector: '5', number: 10, province: 'OR');

    $match = matcher()->match(registroRow(link: '', area: 'T', sector: '5', number: 10, variant: '0'));

    expect($match->codeId)->toBe($code->id)->and($match->anomalyType)->toBeNull();
});

it('link che non porta a nessuna traccia → ripiego sul numero', function () {
    $code = reservedCode(area: 'G', sector: '5', number: 6, variant: 'C');

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/999999', area: 'G', sector: '5', number: 6, variant: 'C'));

    expect($match->codeId)->toBe($code->id)->and($match->anomalyType)->toBeNull();
});

it('link che non porta a nessuna traccia e il ripiego fallisce → anomalia link orfano', function () {
    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/999999', area: 'G', sector: '5', number: 6, variant: 'C'));

    expect($match->anomalyType)->toBe(RegistroAnomalyTypes::LINK_ORFANO)->and($match->codeId)->toBeNull();
});

it('ripiego con due codici candidati → anomalia ripiego ambiguo', function () {
    // Due province diverse, stessa area/settore/numero/variante: possibile
    // perche' l'indice unico dei codici attivi include la provincia, e la
    // provincia e' l'unica cosa che il ripiego ignora.
    reservedCode(area: 'D', sector: '3', number: 32, province: 'NU');
    reservedCode(area: 'D', sector: '3', number: 32, province: 'OR');

    $match = matcher()->match(registroRow(link: '', area: 'D', sector: '3', number: 32, variant: '0'));

    expect($match->anomalyType)->toBe(RegistroAnomalyTypes::RIPIEGO_AMBIGUO)
        ->and($match->codeId)->toBeNull()
        // Codici completi, non id: sono quelli che si cercano nel Catasto.
        ->and($match->context['candidates'])->toEqualCanonicalizing(['ZNUD332', 'ZORD332']);
});

it('traccia senza codice e senza anomalia del catasto → anomalia', function () {
    $track = trackOnly(sourceId: '4001');

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/4001'));

    expect($match->anomalyType)->toBe(RegistroAnomalyTypes::TRACCIA_SENZA_CODICE)
        ->and($match->codeId)->toBeNull()
        ->and($match->ecTrackId)->toBe($track->id);
});

it('traccia senza codice ma con anomalia del catasto → nessuna anomalia del registro', function () {
    $track = trackOnly(sourceId: '4002');

    TrailRegistryAnomaly::create([
        'ec_track_id' => $track->id,
        'type' => 'fuori_da_ogni_settore',
        'source' => TrailRegistryAnomaly::SOURCE_CATASTO,
        'created_at' => now(),
    ]);

    $match = matcher()->match(registroRow(link: 'https://www.sardegnasentieri.it/node/4002'));

    expect($match->anomalyType)->toBeNull()
        ->and($match->codeId)->toBeNull()
        ->and($match->ecTrackId)->toBe($track->id);
});

it('link vuoto e nessun codice corrispondente → ne consistente ne anomalia', function () {
    $match = matcher()->match(registroRow(link: '', area: 'B', sector: '9', number: 99, variant: '0'));

    expect($match->anomalyType)->toBeNull()->and($match->codeId)->toBeNull();
});
