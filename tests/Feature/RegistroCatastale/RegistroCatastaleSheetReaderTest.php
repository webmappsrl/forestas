<?php

use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use App\Services\RegistroCatastale\RegistroCatastaleSheetReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['forestas.registro_catastale.url' => 'https://docs.google.com/spreadsheets/d/FILE/edit']);
});

it('ricava i gid dalla pagina htmlview e scarica ogni foglio', function () {
    Http::fake([
        'docs.google.com/spreadsheets/d/FILE/htmlview*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/htmlview.html'))),
        'docs.google.com/spreadsheets/d/FILE/export*gid=0*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/sheet_registro.csv')), 200, ['Content-Type' => 'text/csv']),
        'docs.google.com/spreadsheets/d/FILE/export*gid=1001546814*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/sheet_legenda.csv')), 200, ['Content-Type' => 'text/csv']),
    ]);

    $sheets = app(RegistroCatastaleSheetReader::class)->readAll();

    expect(collect($sheets)->pluck('gid')->all())->toBe(['0', '1001546814']);
    expect($sheets[0]->rows[0][19])->toBe('Link SardegnaSENTIERI');
});

it('si ferma se la pagina non elenca nessun foglio', function () {
    Http::fake(['*htmlview*' => Http::response('<html></html>')]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'nessun foglio');

it('si ferma se un foglio risponde con HTML invece del CSV', function () {
    Http::fake([
        '*htmlview*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/htmlview.html'))),
        '*export*' => Http::response('<html>Accedi</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'non è un CSV');

it('una risposta 500 diventa un errore di lettura, non un eccezione HTTP', function () {
    Sleep::fake();

    Http::fake([
        '*htmlview*' => Http::response(file_get_contents(base_path('tests/Fixtures/registro/htmlview.html'))),
        '*export*' => Http::response('errore', 500),
    ]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'ha risposto 500');

it('una pagina htmlview che risponde 500 diventa un errore di lettura', function () {
    Sleep::fake();

    Http::fake(['*htmlview*' => Http::response('errore', 500)]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'htmlview ha risposto 500');

it('un errore di connessione diventa un errore di lettura', function () {
    Sleep::fake();

    Http::fake(['*htmlview*' => fn () => throw new ConnectionException('Connection refused')]);

    app(RegistroCatastaleSheetReader::class)->readAll();
})->throws(RegistroCatastaleReadException::class, 'Connection refused');
