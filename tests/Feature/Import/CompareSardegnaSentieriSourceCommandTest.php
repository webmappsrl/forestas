<?php

use App\Dto\Api\ApiTrackResponse;
use App\Services\Import\Source\SardegnaSentieriApiSource;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Support\Facades\File;

// Il comando che dimostra che la copia del DB Drupal dà all'import gli stessi
// dati delle API (oc:8900).

// Ogni test salva le risposte delle API in una cartella sua: quella vera è
// del comando lanciato a mano.
beforeEach(function () {
    $this->compareCache = storage_path('framework/testing/compare-cache-'.uniqid());
    config(['forestas.sardegnasentieri.compare_cache_path' => $this->compareCache]);
});

afterEach(function () {
    File::deleteDirectory($this->compareCache);
});

function compareTrack(int $id, array $properties = []): ApiTrackResponse
{
    return ApiTrackResponse::fromJson(['properties' => array_merge([
        'id' => (string) $id,
        'name' => ['it' => "Sentiero {$id}"],
        'url' => "https://www.sardegnasentieri.it/sentieri/s-{$id}",
        'lunghezza' => '100',
    ], $properties)]);
}

function compareSources(array $apiList, array $dbList, array $apiTracks, array $dbTracks): void
{
    $api = Mockery::mock(SardegnaSentieriApiSource::class);
    $db = Mockery::mock(SardegnaSentieriDatabaseSource::class);

    foreach ([[$api, $apiList, $apiTracks], [$db, $dbList, $dbTracks]] as [$source, $list, $tracks]) {
        $source->shouldReceive('getTrackList')->andReturn($list);
        $source->shouldReceive('getTrackDetail')->andReturnUsing(fn (int $id) => $tracks[$id]);
    }

    $db->shouldReceive('preload');

    app()->instance(SardegnaSentieriApiSource::class, $api);
    app()->instance(SardegnaSentieriDatabaseSource::class, $db);
}

it('segnala le differenze sui campi che l\'import usa', function () {
    compareSources(
        ['10' => '2024-01-01 10:00:00'],
        ['10' => '2024-01-01 10:00:00'],
        [10 => compareTrack(10)],
        [10 => compareTrack(10, ['name' => ['it' => 'Altro nome']])],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->expectsOutputToContain('track 10 name.it')
        ->assertExitCode(1);
});

it('ignora i campi che l\'import non usa', function () {
    compareSources(
        ['11' => '2024-01-01 10:00:00'],
        ['11' => '2024-01-01 10:00:00'],
        [11 => compareTrack(11)],
        [11 => compareTrack(11, ['lunghezza' => '999'])],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->doesntExpectOutputToContain('track 11')
        ->assertExitCode(0);
});

it('salta i nodi modificati dopo il dump', function () {
    // Venti nodi uguali e uno modificato dopo il dump: sotto la soglia.
    $list = [];
    $tracks = [];
    foreach (range(20, 39) as $id) {
        $list[(string) $id] = '2024-01-01 10:00:00';
        $tracks[$id] = compareTrack($id);
    }
    compareSources(
        $list + ['12' => '2024-01-02 10:00:00'],
        $list + ['12' => '2024-01-01 10:00:00'],
        $tracks + [12 => compareTrack(12, ['name' => ['it' => 'Nome nuovo']])],
        $tracks + [12 => compareTrack(12)],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->doesntExpectOutputToContain('track 12 name')
        ->expectsOutputToContain('1 nodi saltati (changed diverso)')
        ->assertExitCode(0);
});

it('fallisce se salta troppi nodi: un changed sbagliato li salterebbe tutti', function () {
    compareSources(
        ['17' => '2024-01-01 11:00:00', '18' => '2024-01-01 11:00:00'],
        ['17' => '2024-01-01 10:00:00', '18' => '2024-01-01 10:00:00'],
        [17 => compareTrack(17), 18 => compareTrack(18)],
        [17 => compareTrack(17), 18 => compareTrack(18)],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->expectsOutputToContain('troppi nodi saltati')
        ->assertExitCode(1);
});

it('segnala un nodo presente in una sola fonte', function () {
    compareSources(
        ['13' => '2024-01-01 10:00:00'],
        [],
        [13 => compareTrack(13)],
        [],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->expectsOutputToContain('track 13 lista')
        ->assertExitCode(1);
});
