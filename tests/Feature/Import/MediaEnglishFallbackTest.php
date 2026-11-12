<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;

/**
 * Su Drupal alcuni file sono collegati solo alla traduzione inglese del
 * contenuto: l'import li legge da lì quando la lingua originale non ne ha
 * (oc:8932).
 */

/**
 * @return array<string, mixed>
 */
function imageMediaItem(string $file): array
{
    return [
        'target_id' => '1',
        'entity' => [
            'langcode' => 'it',
            'fields' => [
                'field_media_image' => ['it' => [0 => ['entity' => ['uri' => "public://{$file}"]]]],
                'field_autore' => ['it' => [0 => ['value' => 'Autore']]],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function documentMediaItem(string $file): array
{
    return [
        'target_id' => '2',
        'entity' => [
            'langcode' => 'it',
            'fields' => ['field_media_document' => ['it' => [0 => ['entity' => ['uri' => "public://{$file}"]]]]],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function videoMediaItem(string $value): array
{
    return [
        'target_id' => '3',
        'entity' => ['langcode' => 'it', 'fields' => ['field_media_oembed_video' => ['it' => [0 => ['value' => $value]]]]],
    ];
}

/**
 * @param  array<string, array<string, array<int, array<string, mixed>>>>  $fields
 */
function trackDetailFromFields(array $fields): ApiTrackResponse
{
    $record = [
        'entity_type' => 'node',
        'id' => 75,
        'bundle' => 'itinerario',
        'langcode' => 'it',
        'translations' => ['it' => ['title' => 'Itinerario', 'created' => 1760000000, 'changed' => 1760000000]],
        'fields' => $fields,
        'aliases' => [],
    ];

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(75)->andReturn($record);

    return (new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(75);
}

it('prende i media dalla traduzione inglese quando la lingua originale non ne ha', function () {
    $detail = trackDetailFromFields([
        'field_galleria_media' => ['en' => [0 => imageMediaItem('foto-en.jpg'), 1 => imageMediaItem('altra foto.jpg')]],
        'field_immagine_principale_media' => ['en' => [0 => imageMediaItem('principale-en.jpg')]],
        'field_allegati_media' => ['en' => [0 => documentMediaItem('scheda en.pdf')]],
        'field_video_media' => ['en' => [0 => videoMediaItem('https://youtu.be/abc')]],
    ]);

    $base = rtrim((string) config('forestas.sardegnasentieri.files_url'), '/');

    expect(collect($detail->galleriaImmagini)->pluck('url')->all())->toBe(["{$base}/foto-en.jpg", "{$base}/altra%20foto.jpg"])
        ->and($detail->immaginePrincipale?->url)->toBe("{$base}/principale-en.jpg")
        ->and($detail->allegati)->toBe(["{$base}/scheda%20en.pdf"])
        ->and($detail->video)->toBe(['https://youtu.be/abc']);
});

it('ignora la traduzione inglese quando la lingua originale ha i media', function () {
    $detail = trackDetailFromFields([
        'field_galleria_media' => [
            'it' => [0 => imageMediaItem('foto-it.jpg')],
            'en' => [0 => imageMediaItem('foto-en.jpg'), 1 => imageMediaItem('altra-en.jpg')],
        ],
        'field_allegati_media' => ['it' => [0 => documentMediaItem('it.pdf')], 'en' => [0 => documentMediaItem('en.pdf')]],
    ]);

    $base = rtrim((string) config('forestas.sardegnasentieri.files_url'), '/');

    expect(collect($detail->galleriaImmagini)->pluck('url')->all())->toBe(["{$base}/foto-it.jpg"])
        ->and($detail->allegati)->toBe(["{$base}/it.pdf"]);
});

it('non fallisce su un nodo senza traduzione inglese', function () {
    $detail = trackDetailFromFields([
        'field_galleria_media' => ['it' => [0 => imageMediaItem('foto-it.jpg')]],
    ]);

    expect($detail->allegati)->toBe([])
        ->and($detail->video)->toBe([])
        ->and(count($detail->galleriaImmagini))->toBe(1);
});
