<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\Api\ApiTrackResponse;
use App\Dto\Import\TrackPropertiesData;

function trackResponse(array $properties = []): ApiTrackResponse
{
    return ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => null,
        'properties' => array_merge([
            'id' => '75',
            'name' => ['it' => 'Sentiero Test'],
            'description' => ['it' => 'Desc'],
            'excerpt' => null,
            'type' => 'sentiero',
            'allegati' => [],
            'video' => [],
            'gpx' => [],
            'url' => null,
            'updated_at' => '2024-01-15T10:00:00',
            'partenza' => null,
            'arrivo' => null,
            'taxonomies' => [],
        ], $properties),
    ]);
}

// I valori di lunghezza, dislivello, durata e quote su Drupal vengono dal vecchio
// calcolo di Webmapp, non da misure: l'import li ignora (oc:8641).
it('non scrive manual_data anche se la API manda lunghezza, dislivello, durata e quote', function () {
    $response = trackResponse([
        'lunghezza' => '5000',
        'dislivello_totale' => '300',
        'durata' => '3600',
        'ele_min' => '120',
        'ele_max' => '420',
    ]);

    $result = TrackPropertiesData::fromApiResponse(75, $response)->toArray();

    expect($result)->not->toHaveKey('manual_data')
        ->and($result['sardegnasentieri_id'])->toBe('75');
});

it('non scrive manual_data quando la API non manda i valori', function () {
    $result = TrackPropertiesData::fromApiResponse(75, trackResponse())->toArray();

    expect($result)->not->toHaveKey('manual_data');
});
