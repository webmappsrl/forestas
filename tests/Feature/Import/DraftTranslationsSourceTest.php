<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;

/**
 * Di un nodo pubblicato l'import porta solo le traduzioni pubblicate (oc:8999): in Drupal `status` è
 * per traduzione, e una traduzione in bozza non deve dare né testi né immagini. La lingua originale
 * resta sempre: un nodo con l'originale in bozza non arriva fin qui, lo esclude changedList().
 */

/**
 * Un sentiero con due lingue: titolo e roadbook in tutte e due, una foto della galleria solo nella
 * traduzione (il ripiego sull'inglese di mediaReferences()).
 *
 * @param  array<string, int>  $status  lingua => status della traduzione
 */
function draftTranslationTrack(string $original, array $status): ApiTrackResponse
{
    $titles = ['it' => 'Sentiero', 'en' => 'Trail'];
    $translation = $original === 'it' ? 'en' : 'it';

    $translations = [];
    foreach ([$original, $translation] as $langcode) {
        $translations[$langcode] = [
            'title' => $titles[$langcode],
            'status' => $status[$langcode],
            'created' => 1760000000,
            'changed' => 1760000000,
        ];
    }

    $photo = [
        'target_id' => 500,
        'entity' => [
            'langcode' => $translation,
            'translations' => [],
            'fields' => [
                'field_media_image' => [
                    $translation => [['alt' => 'Foto', 'entity' => ['uri' => 'public://foto.jpg']]],
                ],
            ],
        ],
    ];

    $record = [
        'entity_type' => 'node',
        'id' => 75,
        'bundle' => 'sentiero',
        'langcode' => $original,
        'translations' => $translations,
        'fields' => [
            'field_roadbook' => [
                $original => [['value' => '<p>Tappa originale</p>']],
                $translation => [['value' => '<p>Tappa tradotta</p>']],
            ],
            'field_galleria_media' => [$translation => [$photo]],
        ],
        'aliases' => [],
    ];

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(75)->andReturn($record);

    return (new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(75);
}

it('non porta i testi di una traduzione in bozza', function () {
    $detail = draftTranslationTrack('it', ['it' => 1, 'en' => 0]);

    expect($detail->name)->toBe(['it' => 'Sentiero'])
        ->and($detail->roadbook_stages)->toBe(['it' => ['<p>Tappa originale</p>']]);
});

it('non prende immagini dall\'inglese in bozza', function () {
    $detail = draftTranslationTrack('it', ['it' => 1, 'en' => 0]);

    expect($detail->galleriaImmagini)->toBeEmpty();
});

it('porta la traduzione pubblicata', function () {
    $detail = draftTranslationTrack('it', ['it' => 1, 'en' => 1]);

    expect($detail->name)->toBe(['it' => 'Sentiero', 'en' => 'Trail'])
        ->and($detail->galleriaImmagini)->toHaveCount(1);
});

it('non toglie mai la lingua originale, anche quando non è l\'italiano', function () {
    $detail = draftTranslationTrack('en', ['en' => 1, 'it' => 0]);

    // La chiave `it` porta sempre la lingua originale, e un nodo nato in inglese la ripete sotto `en`
    // (languages(), come le vecchie API). La traduzione italiana in bozza non compare.
    expect($detail->name)->toBe(['it' => 'Trail', 'en' => 'Trail'])
        ->and($detail->roadbook_stages)->toBe([
            'it' => ['<p>Tappa originale</p>'],
            'en' => ['<p>Tappa originale</p>'],
        ]);
});
