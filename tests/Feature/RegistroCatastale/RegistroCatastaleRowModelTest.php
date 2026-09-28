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
