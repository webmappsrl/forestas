<?php

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\Source\SardegnaSentieriApiSource;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

uses(RefreshDatabase::class);

// Il comando che dimostra che la copia del DB Drupal dà all'import gli stessi
// dati delle API (oc:8900).

// Ogni test salva le risposte delle API in una cartella sua: quella vera è
// del comando lanciato a mano.
beforeEach(function () {
    Storage::fake(config('wm-media-library.disk_name'));
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

// -----------------------------------------------------------------------------
// Copia del DB Drupal contro database dello shard (oc:8932)
// -----------------------------------------------------------------------------

function shardApp(): void
{
    if (App::query()->doesntExist()) {
        Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
        $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
        App::withoutEvents(fn () => App::query()->create([
            'id' => 1, 'name' => 'Sardegna Sentieri',
            'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
        ]));
    }
}

function shardTrack(int $nid, string $wkt = 'LINESTRING(9.1 41.1, 9.2 41.2)', array $properties = []): EcTrack
{
    shardApp();

    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => "Sentiero {$nid}"], 'app_id' => 1, 'user_id' => User::first()->id,
        'properties' => ['sardegnasentieri_id' => (string) $nid] + $properties,
        'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
    ]));
}

function shardPoi(int $nid, string $wkt = 'POINT(9.1 41.1)', array $properties = []): EcPoi
{
    shardApp();

    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => "POI {$nid}"], 'app_id' => 1, 'user_id' => User::first()->id,
        'properties' => ['out_source_feature_id' => (string) $nid] + $properties,
        'geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
    ]));
}

function shardMedia(object $model, string $collection, string $url, string $caption = ''): void
{
    shardApp();
    DB::table('media')->insert([
        'model_type' => $model->getMorphClass(), 'model_id' => $model->getKey(), 'uuid' => (string) Str::uuid(),
        'collection_name' => $collection, 'name' => 'file', 'file_name' => 'file.bin', 'mime_type' => 'application/octet-stream',
        'disk' => 'local', 'conversions_disk' => 'local', 'size' => 1, 'manipulations' => '[]', 'generated_conversions' => '[]',
        'responsive_images' => '[]', 'order_column' => 1, 'app_id' => 1, 'geometry' => DB::raw("ST_GeomFromText('POINT Z(0 0 0)', 4326)"),
        'custom_properties' => json_encode(array_filter([
            'sardegnasentieri_import' => true, 'sardegnasentieri_source_url' => $url, 'sardegnasentieri_caption' => $caption,
        ])),
    ]);
}

/**
 * @param  array<int, ApiTrackResponse>  $tracks
 * @param  array<int, ApiPoiResponse>  $pois
 * @param  array<int, array<string, mixed>>  $enti
 * @param  array<int, array<string, mixed>>  $records  record Drupal dei nodi, per i campi dei legami
 */
function shardSources(array $tracks = [], array $pois = [], array $enti = [], array $records = []): void
{
    $db = Mockery::mock(SardegnaSentieriDatabaseSource::class);
    $db->shouldReceive('preload');
    $db->shouldReceive('getTrackList')->andReturn(collect($tracks)->mapWithKeys(fn ($v, $k) => [(string) $k => 'x'])->all());
    $db->shouldReceive('getPoiList')->andReturn(collect($pois)->mapWithKeys(fn ($v, $k) => [(string) $k => 'x'])->all());
    $db->shouldReceive('getEnteList')->andReturn(collect($enti)->mapWithKeys(fn ($v, $k) => [(string) $k => 'x'])->all());
    $db->shouldReceive('getTrackDetail')->andReturnUsing(fn (int $id) => $tracks[$id]);
    $db->shouldReceive('getPoiDetail')->andReturnUsing(fn (int $id) => $pois[$id]);
    $db->shouldReceive('getNodeDetail')->andReturnUsing(fn (int $id) => $enti[$id]);
    $db->shouldReceive('record')->andReturnUsing(fn (int $id) => ['langcode' => 'it', 'fields' => $records[$id] ?? []]);

    app()->instance(SardegnaSentieriApiSource::class, Mockery::mock(SardegnaSentieriApiSource::class));
    app()->instance(SardegnaSentieriDatabaseSource::class, $db);
}

function comparePoi(int $id, array $properties = []): ApiPoiResponse
{
    return ApiPoiResponse::fromJson([
        'type' => 'Feature',
        'properties' => array_merge(['id' => (string) $id, 'name' => ['it' => "POI {$id}"], 'url' => "https://www.sardegnasentieri.it/p-{$id}"], $properties),
        'geometry' => ['type' => 'Point', 'coordinates' => [9.1, 41.1]],
    ]);
}

it('non segnala niente se lo shard ha tutti i dati portati dalla copia', function () {
    $track = shardTrack(10, properties: ['related_url' => ['Sito' => 'https://example.org']]);
    $poi = shardPoi(20, properties: ['forestas' => ['created_at' => '2020-05-01 10:00:00']]);
    shardMedia($track, 'attachments', 'https://files/a.pdf');
    shardMedia($track, 'default', 'https://files/foto.jpg', 'Una didascalia');
    $track->ecPois()->sync([$poi->id]);

    shardSources(
        [10 => compareTrack(10, [
            'collegamenti' => [['uri' => 'https://example.org', 'title' => 'Sito']],
            'attachment_files' => [['url' => 'https://files/a.pdf', 'title' => 'A']],
            'immagine_principale' => ['url' => 'https://files/foto.jpg', 'caption' => 'Una didascalia'],
            'poi_correlati' => ['20'],
        ])],
        [20 => comparePoi(20, ['created_at' => '2020-05-01 10:00:00'])],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('shard legami track-poi: copia=1 shard=1')
        ->expectsOutputToContain('Nessuna differenza.')
        ->assertExitCode(0);
});

it('segnala uno scarto nei collegamenti fra contenuti', function () {
    $track = shardTrack(10);
    $poi = shardPoi(20);
    $other = shardPoi(21);
    $track->ecPois()->sync([$other->id]);

    shardSources(
        [10 => compareTrack(10, ['poi_correlati' => ['20']])],
        [20 => comparePoi(20), 21 => comparePoi(21)],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('legame track 10 poi 20: copia=presente shard=assente')
        ->expectsOutputToContain('legame track 10 poi 21: copia=assente shard=presente')
        ->assertExitCode(1);
});

it('non segnala un legame oltre i 10 km, che lo shard scarta di proposito', function () {
    shardTrack(10);
    shardPoi(20, 'POINT(10.5 40.0)');

    shardSources([10 => compareTrack(10, ['poi_correlati' => ['20']])], [20 => comparePoi(20)]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])->assertExitCode(0);
});

it('legge i legami dei POI verso i percorsi dal DTO', function () {
    shardTrack(10);
    shardPoi(20);

    shardSources([10 => compareTrack(10)], [20 => comparePoi(20, ['sentieri_collegati' => ['10']])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('legame track 10 poi 20: copia=presente shard=assente')
        ->assertExitCode(1);
});

it('segnala un allegato presente su Drupal e assente nello shard', function () {
    $track = shardTrack(10);
    shardMedia($track, 'attachments', 'https://files/a.pdf');

    shardSources([10 => compareTrack(10, ['attachment_files' => [
        ['url' => 'https://files/a.pdf', 'title' => 'A'],
        ['url' => 'https://files/b.pdf', 'title' => 'B'],
    ]])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('track 10 attachments: copia=2 mancano: https://files/b.pdf shard=1')
        ->assertExitCode(1);
});

it('segnala un video caricato che manca nello shard', function () {
    shardPoi(20);

    shardSources([], [20 => comparePoi(20, ['uploaded_videos' => [['url' => 'https://files/v.mp4', 'title' => 'V']]])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('poi 20 videos')
        ->assertExitCode(1);
});

it('segnala uno scarto nei collegamenti esterni', function () {
    shardTrack(10, properties: ['related_url' => ['Sito' => 'https://example.org']]);

    shardSources([10 => compareTrack(10, ['collegamenti' => [['uri' => 'https://altro.org', 'title' => 'Altro']]])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('track 10 related_url')
        ->assertExitCode(1);
});

it('segnala una didascalia diversa da quella di Drupal', function () {
    $track = shardTrack(10);
    shardMedia($track, 'default', 'https://files/foto.jpg');

    shardSources([10 => compareTrack(10, ['immagine_principale' => ['url' => 'https://files/foto.jpg', 'caption' => 'Panorama']])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('track 10 didascalia https://files/foto.jpg: copia=Panorama shard=∅')
        ->assertExitCode(1);
});

it('segnala un POI senza data di creazione nello shard', function () {
    shardPoi(20);

    shardSources([], [20 => comparePoi(20, ['created_at' => '2020-05-01 10:00:00'])]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('poi 20 created_at: copia=2020-05-01 10:00:00 shard=∅')
        ->assertExitCode(1);
});

it('confronta i dati degli enti con lo shard', function () {
    $ente = Ente::create([
        'sardegnasentieri_id' => '30', 'name' => ['it' => 'GAL BMG'], 'slug' => 'gal-bmg', 'pagina_web_title' => 'Sito',
        'drupal_created_at' => '2021-03-04 09:00:00', 'properties' => ['related_url' => ['Sito' => 'https://gal.example']],
    ]);
    shardMedia($ente, 'default', 'https://files/ente.jpg');

    shardSources([], [], [30 => [
        'created' => '2021-03-04 09:00:00', 'slug' => 'gal-bmg', 'pagina_web_title' => 'Sito',
        'collegamenti' => [['uri' => 'https://gal.example', 'title' => 'Sito']],
        'immagini' => [['url' => 'https://files/ente.jpg', 'caption' => '']],
        'title' => [['value' => 'GAL BMG']],
    ]]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('Nessuna differenza.')
        ->assertExitCode(0);
});

it('segnala un ente con slug, immagini e collegamenti diversi', function () {
    Ente::create(['sardegnasentieri_id' => '30', 'name' => ['it' => 'GAL BMG'], 'slug' => 'altro', 'pagina_web_title' => 'Vecchio', 'drupal_created_at' => '2020-01-01 00:00:00', 'properties' => []]);

    shardSources([], [], [30 => [
        'slug' => 'gal-bmg', 'pagina_web_title' => 'Nuovo', 'created' => '2021-03-04 09:00:00',
        'collegamenti' => [['uri' => 'https://gal.example', 'title' => 'Sito']],
        'immagini' => [['url' => 'https://files/ente.jpg', 'caption' => '']],
        'title' => [['value' => 'GAL BMG']],
    ]]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('ente 30 slug: copia=gal-bmg shard=altro')
        ->expectsOutputToContain('ente 30 pagina_web_title: copia=Nuovo shard=Vecchio')
        ->expectsOutputToContain('ente 30 drupal_created_at: copia=2021-03-04 09:00:00 shard=2020-01-01 00:00:00')
        ->expectsOutputToContain('ente 30 immagini')
        ->expectsOutputToContain('ente 30 related_url')
        ->assertExitCode(1);
});

it('segnala un contenuto che lo shard non ha', function () {
    shardSources([10 => compareTrack(10)]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('track 10: copia=presente shard=assente')
        ->assertExitCode(1);
});

it('nel confronto con le API ignora i campi che solo la copia porta', function () {
    compareSources(
        ['14' => '2024-01-01 10:00:00'],
        ['14' => '2024-01-01 10:00:00'],
        [14 => compareTrack(14, ['immagine_principale' => ['url' => 'https://files/foto.jpg']])],
        [14 => compareTrack(14, [
            'collegamenti' => [['uri' => 'https://example.org', 'title' => 'Sito']],
            'attachment_files' => [['url' => 'https://files/a.pdf', 'title' => 'A']],
            'immagine_principale' => ['url' => 'https://files/foto.jpg', 'caption' => 'Didascalia'],
        ])],
    );

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'tracks'])
        ->doesntExpectOutputToContain('track 14')
        ->assertExitCode(0);
});

it('legge dal percorso i punti di interesse di un itinerario', function () {
    shardTrack(10);
    shardPoi(20);

    shardSources([10 => compareTrack(10, ['punti_di_interesse' => ['20']])], [20 => comparePoi(20)]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('legame track 10 poi 20: copia=presente shard=assente')
        ->assertExitCode(1);
});

it('si aspetta i collegamenti fra POI reciproci e ignora chi cita se stesso', function () {
    $a = shardPoi(20);
    $b = shardPoi(21);
    shardPoi(22);
    DB::table('ec_poi_related_pois')->insert(['ec_poi_id' => $a->id, 'related_poi_id' => $b->id]);

    shardSources([], [
        20 => comparePoi(20, ['poi_correlati' => ['21']]),
        21 => comparePoi(21),
        22 => comparePoi(22, ['poi_correlati' => ['22']]),
    ]);

    $this->artisan('sardegnasentieri:compare-source', ['--only' => 'shard'])
        ->expectsOutputToContain('legame poi 21 poi 20: copia=presente shard=assente')
        ->doesntExpectOutputToContain('poi 22 poi 22')
        ->assertExitCode(1);
});
