<?php

declare(strict_types=1);

use App\Support\RoadbookBlock;

/**
 * Il roadbook come blocco `info` dei Blocchi Dettaglio (oc:8959): una riga per tappa, titolo
 * «Tappa N» nelle lingue in cui la tappa ha un testo, gli altri blocchi intatti.
 */
function manualBox(): array
{
    return ['box_type' => 'info', 'items' => [['title' => ['it' => 'Orari'], 'content' => ['it' => '<p>Sempre aperto</p>']]]];
}

it('mette una riga per tappa con titolo e testo per lingua', function () {
    $config = RoadbookBlock::apply(null, [
        'it' => ['<p>Uno</p>', '<p>Due</p>'],
        'en' => ['<p>One</p>', '<p>Two</p>'],
    ]);

    expect($config)->toBe([[
        'box_type' => 'info',
        'items' => [
            ['title' => ['it' => 'Tappa 1', 'en' => 'Stage 1'], 'content' => ['it' => '<p>Uno</p>', 'en' => '<p>One</p>']],
            ['title' => ['it' => 'Tappa 2', 'en' => 'Stage 2'], 'content' => ['it' => '<p>Due</p>', 'en' => '<p>Two</p>']],
        ],
    ]]);
});

it('fa tante tappe quante la lingua che ne ha di più, ognuna solo nelle sue lingue', function () {
    $items = RoadbookBlock::apply([], ['it' => ['<p>Uno</p>', '<p>Due</p>', '<p>Tre</p>'], 'en' => ['<p>One</p>']])[0]['items'];

    expect($items)->toHaveCount(3)
        ->and($items[0]['title'])->toBe(['it' => 'Tappa 1', 'en' => 'Stage 1'])
        ->and($items[2])->toBe(['title' => ['it' => 'Tappa 3'], 'content' => ['it' => '<p>Tre</p>']]);
});

it('traduce il titolo nelle lingue del progetto', function () {
    $items = RoadbookBlock::apply([], ['fr' => ['<p>Un</p>'], 'es' => ['<p>Uno</p>'], 'de' => ['<p>Eins</p>']])[0]['items'];

    expect($items[0]['title'])->toBe(['fr' => 'Étape 1', 'es' => 'Etapa 1', 'de' => 'Etappe 1']);
});

it('mette il roadbook in testa e lascia i blocchi aggiunti a mano', function () {
    $config = RoadbookBlock::apply([manualBox()], ['it' => ['<p>Uno</p>']]);

    expect($config)->toHaveCount(2)
        ->and($config[0]['items'][0]['title'])->toBe(['it' => 'Tappa 1'])
        ->and($config[1])->toBe(manualBox());
});

it('sostituisce il roadbook già presente nella stessa posizione', function () {
    $old = RoadbookBlock::apply([], ['it' => ['<p>Vecchia</p>', '<p>Vecchia due</p>']])[0];

    $config = RoadbookBlock::apply([manualBox(), $old], ['it' => ['<p>Nuova</p>']]);

    expect($config)->toHaveCount(2)
        ->and($config[0])->toBe(manualBox())
        ->and($config[1]['items'])->toBe([['title' => ['it' => 'Tappa 1'], 'content' => ['it' => '<p>Nuova</p>']]]);
});

it('toglie il roadbook se Drupal non lo ha più, lasciando gli altri blocchi', function () {
    $old = RoadbookBlock::apply([], ['it' => ['<p>Vecchia</p>']])[0];

    expect(RoadbookBlock::apply([$old, manualBox()], null))->toBe([manualBox()])
        ->and(RoadbookBlock::apply([$old], []))->toBe([]);
});

it('non scambia per roadbook un blocco con altri titoli', function () {
    $box = ['box_type' => 'info', 'items' => [['title' => ['it' => 'Tappa 1'], 'content' => []], ['title' => ['it' => 'Note'], 'content' => []]]];

    expect(RoadbookBlock::apply([$box], null))->toBe([$box]);
});

it('tratta come vuoto un config_detail salvato in una forma non valida', function () {
    expect(RoadbookBlock::apply('[]', ['it' => ['<p>Uno</p>']]))->toHaveCount(1)
        ->and(RoadbookBlock::apply(['non un blocco'], null))->toBe(['non un blocco']);
});
