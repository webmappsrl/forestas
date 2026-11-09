<?php

declare(strict_types=1);

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Import\PoiPropertiesData;
use App\Services\Import\Source\DrupalValues;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;

/**
 * Data di creazione dei POI: la sorgente la leggeva già, ma si perdeva nel
 * DTO prima di arrivare in `properties.forestas.created_at` (oc:8932).
 */
it('porta created_at dalla risposta al forestas del POI', function () {
    $response = ApiPoiResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [9.1, 40.1]],
        'properties' => [
            'id' => '137',
            'name' => ['it' => 'Nuraghe Serbissi (Osini)'],
            'created_at' => '2013-07-02 14:12:40',
            'updated_at' => '2024-01-15 10:00:00',
        ],
    ]);

    $forestas = PoiPropertiesData::fromApiResponse(137, $response)->toArray()['forestas'];

    expect($forestas['created_at'])->toBe('2013-07-02 14:12:40');
});

it('lascia created_at nullo se la sorgente non lo dà', function () {
    $response = ApiPoiResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [9.1, 40.1]],
        'properties' => ['id' => '1', 'name' => ['it' => 'X']],
    ]);

    expect(PoiPropertiesData::fromApiResponse(1, $response)->toArray()['forestas']['created_at'])->toBeNull();
});

it('legge dalla copia Drupal la data di «Nuraghe Serbissi (Osini)»: 02/07/2013', function () {
    try {
        $response = app(SardegnaSentieriDatabaseSource::class)->getPoiDetail(137);
    } catch (Throwable) {
        $this->markTestSkipped('Copia del DB Drupal non raggiungibile');
    }

    $forestas = PoiPropertiesData::fromApiResponse(137, $response)->toArray()['forestas'];

    expect($forestas['created_at'])->toBe(DrupalValues::dateTime(1372781560))
        ->and(substr($forestas['created_at'], 0, 10))->toBe('2013-07-02');
});
