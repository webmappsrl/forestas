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
    'minuti oltre 59' => ['1:75', null],
    'cifre attaccate ai minuti' => ['1:305', null],
    'secondi' => ['1:30:45', null],
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
