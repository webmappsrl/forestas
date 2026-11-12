<?php

declare(strict_types=1);

use App\Http\Clients\SardegnaSentieriClient;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\DrupalValues;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

/**
 * Dati degli enti che mancavano al nuovo sito: immagine principale dal campo
 * media, galleria, collegamenti, titolo della pagina web, data di creazione e
 * slug (oc:8932). I primi test leggono la copia del DB Drupal; gli ultimi
 * provano la scrittura con una fonte finta.
 */
function enteNodeFromDrupal(int $nid): array
{
    try {
        return app(SardegnaSentieriDatabaseSource::class)->getNodeDetail($nid);
    } catch (Throwable) {
        Assert::markTestSkipped('Copia del DB Drupal non raggiungibile');
    }
}

it('legge l\'immagine principale dal campo media nuovo', function () {
    // L'ente 275 ha l'immagine solo nel campo media nuovo (50 enti su 92).
    $node = enteNodeFromDrupal(275);

    expect($node['immagini'])->not->toBeEmpty()
        ->and($node['immagini'][0]['url'])->toStartWith('http');
});

it('importa la galleria', function () {
    // L'ente 1108 ha 30 immagini in galleria (31 enti ne hanno una).
    $node = enteNodeFromDrupal(1108);

    expect(count($node['immagini']))->toBeGreaterThan(1)
        ->and(array_column($node['immagini'], 'url'))->toBe(array_values(array_unique(array_column($node['immagini'], 'url'))));
});

it('importa i collegamenti esterni', function () {
    $node = enteNodeFromDrupal(109);

    expect($node['collegamenti'])->toBe([[
        'uri' => 'https://www.parcoditepilora.it',
        'title' => 'Sito Ufficiale del Parco Naturale Regionale di Tepilora',
    ]]);
});

it('salva il titolo della pagina web', function () {
    $node = enteNodeFromDrupal(95);

    expect($node['pagina_web_title'])->toBe('Complesso Forestale del Gerrei');
});

it('salva la data di creazione di Drupal', function () {
    // Oasi Tepilora: 09/05/2013, convertita in PHP dal timestamp.
    $node = enteNodeFromDrupal(109);

    expect($node['created'])->toBe(DrupalValues::dateTime(1368097531))
        ->and(substr($node['created'], 0, 10))->toBe('2013-05-09');
});

it('ricava lo slug dall\'ultima parte dell\'alias', function () {
    $node = enteNodeFromDrupal(109);

    expect($node['slug'])->toBe('complesso-forestale-oasi-tepilora-ente-foreste-della-sardegna-servizio-territoriale-di');
});

/**
 * Scrive un ente da un nodo dato e restituisce l'ente e gli argomenti passati
 * alla sync delle immagini.
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: Ente, 1: array<int, array<string, mixed>>}
 */
function importEnteWith(array $extra): array
{
    $node = array_merge([
        'nid' => [['value' => 109]],
        'title' => [['value' => 'Oasi Tepilora']],
    ], $extra);
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldReceive('getNodeDetail')->andReturn($node);
    $captured = [];
    $mediaSync = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $mediaSync->shouldReceive('syncImportedImages')->once()->andReturnUsing(function ($ente, array $items) use (&$captured) {
        $captured = $items;
    });
    $service = new SardegnaSentieriImportService($source, Mockery::mock(SardegnaSentieriClient::class), $mediaSync);

    return [Ente::findOrFail($service->importOrUpdateEnte('109', '2026-10-30 10:00:00')), &$captured];
}

it('scrive le colonne nuove e le immagini in ordine nella collection default', function () {
    [$ente, $items] = importEnteWith([
        'pagina_web_title' => 'Sito',
        'slug' => 'oasi-tepilora',
        'created' => '2013-05-09 11:05:31',
        'collegamenti' => [['uri' => 'https://a.it', 'title' => 'A']],
        'immagini' => [
            ['url' => 'https://x/1.jpg', 'autore' => 'Rossi', 'credits' => '', 'caption' => 'Uno'],
            ['url' => 'https://x/2.jpg', 'autore' => '', 'credits' => '', 'caption' => ''],
        ],
    ]);

    expect($ente->pagina_web_title)->toBe('Sito')
        ->and($ente->slug)->toBe('oasi-tepilora')
        ->and($ente->drupal_created_at->format('Y-m-d H:i:s'))->toBe('2013-05-09 11:05:31')
        ->and($ente->properties['related_url'])->toBe(['A' => 'https://a.it'])
        ->and($ente->properties['forestas'])->not->toHaveKey('collegamenti')
        ->and($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['url' => 'https://x/1.jpg', 'autore' => 'Rossi', 'caption' => 'Uno', 'order' => 0])
        ->and($items[1]['order'])->toBe(1);
});

it('ripiega sullo slug del titolo se l\'ente non ha alias', function () {
    [$ente] = importEnteWith([]);

    expect($ente->slug)->toBe('oasi-tepilora');
});
