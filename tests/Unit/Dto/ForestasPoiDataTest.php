<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Import\ForestasPoiData;

it('converts collegamenti list to associative array in toArray', function () {
    $data = new ForestasPoiData(
        codice: 'A001',
        collegamenti: ['url-a', 'url-b'],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [],
        allegati: [],
        video: [],
        poi_correlati: [],
    );

    $result = $data->toArray();

    expect($result['collegamenti'])->toBe(['0' => 'url-a', '1' => 'url-b']);
});

it('converts zona_geografica list to associative array in toArray', function () {
    $data = new ForestasPoiData(
        codice: null,
        collegamenti: [],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [42, 99],
        allegati: [],
        video: [],
        poi_correlati: [],
    );

    $result = $data->toArray();

    expect($result['zona_geografica'])->toBe(['0' => 42, '1' => 99]);
});

it('returns empty array for empty lists', function () {
    $data = new ForestasPoiData(
        codice: null,
        collegamenti: [],
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [],
        allegati: [],
        video: [],
        poi_correlati: [],
    );

    $result = $data->toArray();

    expect($result['collegamenti'])->toBe([]);
    expect($result['zona_geografica'])->toBe([]);
});
