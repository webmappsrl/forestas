<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Import\ForestasPoiData;

it('converts zona_geografica list to associative array in toArray', function () {
    $data = new ForestasPoiData(
        codice: null,
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
        come_arrivare: null,
        url: null,
        updated_at: null,
        zona_geografica: [],
        allegati: [],
        video: [],
        poi_correlati: [],
    );

    $result = $data->toArray();

    expect($result)->not->toHaveKey('collegamenti');
    expect($result['zona_geografica'])->toBe([]);
});
