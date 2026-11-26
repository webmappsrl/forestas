<?php

declare(strict_types=1);

use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le bozze di Drupal non entrano nello shard (oc:8999): gli elenchi della copia lasciano fuori i nodi
 * in bozza nella lingua originale. Una copia finta in SQLite al posto di quella vera, così il test
 * gira anche dove la copia non c'è (CI, locale con il container spento).
 */
beforeEach(function () {
    config(['database.connections.sardegnasentieri' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);
    DB::purge('sardegnasentieri');

    Schema::connection('sardegnasentieri')->create('node_field_data', function (Blueprint $table) {
        $table->integer('nid');
        $table->string('type');
        $table->string('langcode');
        $table->integer('default_langcode');
        $table->integer('status');
        $table->integer('changed');
    });

    $node = fn (int $nid, string $type, string $langcode, int $original, int $status) => [
        'nid' => $nid, 'type' => $type, 'langcode' => $langcode,
        'default_langcode' => $original, 'status' => $status, 'changed' => 1760000000,
    ];

    DB::connection('sardegnasentieri')->table('node_field_data')->insert([
        $node(10, 'sentiero', 'it', 1, 1),
        $node(11, 'sentiero', 'it', 1, 0),
        $node(12, 'itinerario', 'it', 1, 0),
        $node(13, 'poi', 'it', 1, 1),
        $node(14, 'poi', 'it', 1, 0),
        $node(15, 'ente_istituzione_societa', 'it', 1, 1),
        // Originale in bozza, traduzione pubblicata: resta fuori del tutto.
        $node(16, 'sentiero', 'it', 1, 0),
        $node(16, 'sentiero', 'en', 0, 1),
        // Originale pubblicato, traduzione in bozza: entra (la traduzione la toglie il record).
        $node(17, 'sentiero', 'it', 1, 1),
        $node(17, 'sentiero', 'en', 0, 0),
    ]);
});

afterEach(function () {
    DB::purge('sardegnasentieri');
});

it('lascia fuori dagli elenchi i nodi in bozza nella lingua originale', function () {
    $source = new SardegnaSentieriDatabaseSource(Mockery::mock(DrupalRecordReader::class));

    expect(array_keys($source->getTrackList()))->toBe([10, 17])
        ->and(array_keys($source->getPoiList()))->toBe([13])
        ->and(array_keys($source->getEnteList()))->toBe([15]);
});
