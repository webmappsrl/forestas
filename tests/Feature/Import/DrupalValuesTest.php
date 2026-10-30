<?php

use App\Services\Import\Source\DrupalValues;

// Le conversioni che rendono i dati della copia identici a quelli delle API
// (oc:8900): un formato diverso fa reimportare tutto o riscaricare le immagini.

it('converte changed nell\'ora italiana come le API', function () {
    expect(DrupalValues::dateTime(1731751201))->toBe('2024-11-16 11:00:01'); // novembre, +1
    expect(DrupalValues::dateTime(1719828000))->toBe('2024-07-01 12:00:00'); // luglio, +2
});

it('ricostruisce l\'URL del file come le API', function () {
    expect(DrupalValues::fileUrl('public://2024-01/Carta 1. Baronie e Montalbo (unita 2).gpx'))
        ->toBe('https://www.sardegnasentieri.it/sites/default/files/2024-01/Carta%201.%20Baronie%20e%20Montalbo%20%28unita%202%29.gpx');
    expect(DrupalValues::fileUrl('public://207764.jpg'))
        ->toBe('https://www.sardegnasentieri.it/sites/default/files/207764.jpg');
});

it('non ricostruisce un URL per i file privati', function () {
    expect(DrupalValues::fileUrl('private://files_privati/x.pdf'))->toBeNull();
});

it('usa l\'URL base dei file dalla configurazione', function () {
    config(['forestas.sardegnasentieri.files_url' => 'https://files.example.org/drupal/']);

    expect(DrupalValues::fileUrl('public://a b.jpg'))->toBe('https://files.example.org/drupal/a%20b.jpg');
});
