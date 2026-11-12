<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;

/**
 * Il roadbook per lingua (oc:8959): `roadbook_stages` ha i paragrafi propri di ogni lingua, letti dal
 * record di Drupal senza accumularli. `roadbook` resta accumulato come le vecchie API, per
 * `compare-source`.
 */

/**
 * @param  array<string, list<string>>  $paragraphs  lingua => paragrafi
 */
function roadbookTrack(array $paragraphs): ApiTrackResponse
{
    $field = [];
    foreach ($paragraphs as $langcode => $values) {
        $field[$langcode] = array_map(fn ($value) => ['value' => $value], $values);
    }
    $translations = [];
    foreach (array_unique(['it', ...array_keys($paragraphs)]) as $langcode) {
        $translations[$langcode] = ['title' => 'Sentiero', 'created' => 1760000000, 'changed' => 1760000000];
    }
    $record = [
        'entity_type' => 'node',
        'id' => 75,
        'bundle' => 'sentiero',
        'langcode' => 'it',
        'translations' => $translations,
        'fields' => ['field_roadbook' => $field],
        'aliases' => [],
    ];

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(75)->andReturn($record);

    return (new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(75);
}

it('dà a ogni lingua i suoi paragrafi, senza quelli delle lingue precedenti', function () {
    $detail = roadbookTrack([
        'it' => ['<p>Tappa uno</p>', '<p>Tappa due</p>'],
        'en' => ['<p>Stage one</p>', '<p>Stage two</p>'],
    ]);

    expect($detail->roadbook_stages)->toBe([
        'it' => ['<p>Tappa uno</p>', '<p>Tappa due</p>'],
        'en' => ['<p>Stage one</p>', '<p>Stage two</p>'],
    ])
        // Il formato delle vecchie API resta, per il confronto con le API.
        ->and($detail->roadbook['en'])->toBe(['<p>Tappa uno</p>', '<p>Tappa due</p>', '<p>Stage one</p>', '<p>Stage two</p>']);
});

it('lascia fuori una lingua senza paragrafi propri', function () {
    $detail = roadbookTrack([
        'it' => ['<p>Tappa uno</p>'],
        'en' => [],
    ]);

    expect($detail->roadbook_stages)->toBe(['it' => ['<p>Tappa uno</p>']]);
});

it('non ha roadbook_stages se Drupal non ha il roadbook', function () {
    expect(roadbookTrack([])->roadbook_stages)->toBeNull();
});
