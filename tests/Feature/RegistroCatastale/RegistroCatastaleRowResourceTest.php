<?php

use App\Models\RegistroCatastaleRow;
use App\Models\User;
use App\Nova\Filters\RegistroRowLinkedFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;

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
    // Ordine di creazione scelto perche' il latest(id) di Nova NON lo riproduca:
    // il test deve dipendere da defaultOrderings().
    rigaRegistro(['sheet_name' => 'AREA B', 'row_number' => 9]);
    rigaRegistro(['sheet_name' => 'AREA T', 'row_number' => 2]);
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

// Nova decodifica `filters` come [{<key del filtro>: <valore>}].
function filtroNova(string $key, string $value): string
{
    return base64_encode(json_encode([[$key => $value]]));
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

it('un Editor apre il detail di una riga non agganciata', function () {
    $row = rigaRegistro(['cells' => [['header' => 'Numero', 'value' => 'valore-unico-8700']]]);

    $this->actingAs(editor())->getJson("/nova-api/registro-catastale-rows/{$row->id}")
        ->assertOk()->assertJsonPath('resource.id.value', $row->id)
        ->assertSee('valore-unico-8700');
});

it('il filtro Agganciato a un codice si tiene solo le righe con codice', function () {
    $track = EcTrack::factory()->createQuietly();
    $code = TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'SU', 'area' => 'G', 'sector' => '5', 'number' => 6, 'variant' => 'C',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ]);
    $agganciata = rigaRegistro(['trail_registry_code_id' => $code->id]);
    rigaRegistro();

    $ids = $this->actingAs(editor())
        ->getJson('/nova-api/registro-catastale-rows?filters='.filtroNova(RegistroRowLinkedFilter::class, 'yes'))
        ->assertOk()->json('resources.*.id.value');

    expect($ids)->toBe([$agganciata->id]);
});

it('i filtri Area e Settore tengono solo le righe corrispondenti', function (string $key, array $diversa, string $valore) {
    $giusta = rigaRegistro(['area' => 'G', 'sector' => '1']);
    rigaRegistro($diversa);

    $ids = $this->actingAs(editor())
        ->getJson('/nova-api/registro-catastale-rows?filters='.filtroNova($key, $valore))
        ->assertOk()->json('resources.*.id.value');

    expect($ids)->toBe([$giusta->id]);
})->with([
    'area' => ['registro-row-area', ['area' => 'B'], 'G'],
    'settore' => ['registro-row-sector', ['sector' => '2'], '1'],
]);

it('a dominio spento un Editor riceve 403 sull index', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    $this->actingAs(editor())->getJson('/nova-api/registro-catastale-rows')->assertForbidden();
});

it('il Codice e il primo campo dell index e sta sopra la tabella Registry nel detail', function () {
    $track = EcTrack::factory()->createQuietly();
    $code = TrailRegistryCode::create([
        'region' => 'Z', 'province' => 'SU', 'area' => 'G', 'sector' => '5', 'number' => 6, 'variant' => 'C',
        'status' => TrailCodeStatus::Assigned, 'ec_track_id' => $track->id,
    ]);
    $row = rigaRegistro(['trail_registry_code_id' => $code->id]);
    rigaRegistro();

    $index = $this->actingAs(editor())->getJson('/nova-api/registro-catastale-rows')->assertOk();
    foreach ($index->json('resources') as $risorsa) {
        expect($risorsa['fields'][0]['attribute'])->toBe('trailRegistryCode');
    }

    $campi = $this->actingAs(editor())->getJson("/nova-api/registro-catastale-rows/{$row->id}")
        ->assertOk()->json('resource.fields');
    $attributi = array_column($campi, 'attribute');
    $posCodice = array_search('trailRegistryCode', $attributi, true);
    $posRegistry = array_search('Registry', array_column($campi, 'name'), true);

    expect($posCodice)->not->toBeFalse()
        ->and($posRegistry)->not->toBeFalse()
        ->and($posCodice)->toBeLessThan($posRegistry)
        ->and($campi[$posCodice]['belongsToId'])->toBe($code->id);
});

it('il titolo di una riga senza numero ripiega su tab e riga', function () {
    app()->setLocale('it');
    $riga = rigaRegistro(['sheet_name' => 'AREA G', 'row_number' => 7, 'sector' => null, 'number' => null]);
    $conNumero = rigaRegistro(['sector' => '1', 'number' => 1, 'variant' => '0']);

    expect((new App\Nova\RegistroCatastaleRow($riga))->title())->toBe('AREA G · riga 7')
        ->and((new App\Nova\RegistroCatastaleRow($conNumero))->title())->toBe('101');
});
