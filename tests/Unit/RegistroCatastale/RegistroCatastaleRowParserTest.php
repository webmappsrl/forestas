<?php

use App\Services\RegistroCatastale\RegistroCatastaleRowParser;
use App\Services\RegistroCatastale\RegistroCatastaleSheet;

function sheetWith(array $dataRows, ?array $headers = null): RegistroCatastaleSheet
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

it('una cella area scritta ma ambigua non eredita l area della riga sopra', function () {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([
        row('Z-SU-D', '2', '200'),
        row('Z-SU-D Z-CA-C', '2', '201'),
    ]));

    expect($rows[1]->area)->toBeNull();
});

it('ignora le righe di servizio', function () {
    expect((new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-NU-B', '', '34 numeri disponibili')])))->toBe([]);
});

it('quando il numero non si legge, prende il settore dal testo grezzo solo se e una singola cifra', function () {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-NU-B', '3', 'n/d', 'https://sardegnasentieri.it/percorso')]));

    expect($rows[0]->number)->toBeNull()->and($rows[0]->sector)->toBe('3');
});

it('quando il numero non si legge, il settore resta null se il testo grezzo non e una singola cifra', function () {
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([row('Z-NU-B', 'vedi mappa', 'n/d', 'https://sardegnasentieri.it/percorso')]));

    expect($rows[0]->number)->toBeNull()->and($rows[0]->sector)->toBeNull();
});

it('conserva intestazioni ripetute come colonne distinte', function () {
    $headers = ['Cod. Provincia e Area', 'SETTORE', 'Numero', 'Comuni di appartenenza', 'Comuni di appartenenza', ...array_fill(0, 14, 'x'), 'Link SardegnaSENTIERI'];
    $rows = (new RegistroCatastaleRowParser)->parse(sheetWith([['Z-TT-G', '1', '100', 'Luras', 'Calangianus', ...array_fill(0, 14, ''), '']], $headers));

    expect(collect($rows[0]->cells)->where('header', 'Comuni di appartenenza')->pluck('value')->all())
        ->toBe(['Luras', 'Calangianus']);
});
