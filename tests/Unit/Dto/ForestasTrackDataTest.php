<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Import\ForestasTrackData;

it('converts allegati list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '42',
        allegati: ['http://example.com/a.pdf', 'http://example.com/b.pdf'],
        video: [],
        gpx: [],
        url: null,
        created_at: null,
        updated_at: null,
        zona_geografica: [],
        codice: null,
        data_rilievo: null,
        info_utili: null,
        roadbook: null,
    );

    $result = $data->toArray();

    expect($result['allegati'])->toBe([
        '0' => 'http://example.com/a.pdf',
        '1' => 'http://example.com/b.pdf',
    ]);
});

it('converts video list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        allegati: [],
        video: ['https://youtu.be/abc', 'https://youtu.be/xyz'],
        gpx: [],
        url: null,
        created_at: null,
        updated_at: null,
        zona_geografica: [],
        codice: null,
        data_rilievo: null,
        info_utili: null,
        roadbook: null,
    );

    $result = $data->toArray();

    expect($result['video'])->toBe([
        '0' => 'https://youtu.be/abc',
        '1' => 'https://youtu.be/xyz',
    ]);
});

it('converts gpx list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        allegati: [],
        video: [],
        gpx: ['https://example.com/track.gpx'],
        url: null,
        created_at: null,
        updated_at: null,
        zona_geografica: [],
        codice: null,
        data_rilievo: null,
        info_utili: null,
        roadbook: null,
    );

    $result = $data->toArray();

    expect($result['gpx'])->toBe(['0' => 'https://example.com/track.gpx']);
});

it('converts zona_geografica list to associative array in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        allegati: [],
        video: [],
        gpx: [],
        url: null,
        created_at: null,
        updated_at: null,
        zona_geografica: [10, 20],
        codice: null,
        data_rilievo: null,
        info_utili: null,
        roadbook: null,
    );

    $result = $data->toArray();

    expect($result['zona_geografica'])->toBe(['0' => 10, '1' => 20]);
});

it('preserves skip_dem_jobs in toArray', function () {
    $data = new ForestasTrackData(
        source_id: '1',
        allegati: [],
        video: [],
        gpx: [],
        url: null,
        created_at: null,
        updated_at: null,
        zona_geografica: [],
        codice: null,
        data_rilievo: null,
        info_utili: null,
        roadbook: null,
        skip_dem_jobs: true,
    );

    expect($data->toArray()['skip_dem_jobs'])->toBeTrue();
});
