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
