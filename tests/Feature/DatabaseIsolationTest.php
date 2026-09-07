<?php

it('non gira sul database reale', function () {
    expect(config('database.connections.pgsql.database'))->toBe('forestas_testing');
})->skip(fn () => env('CI') === 'true', 'In CI il database e\' un container effimero.');
