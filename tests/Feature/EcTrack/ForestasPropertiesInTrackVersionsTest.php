<?php

declare(strict_types=1);

use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\StorageService;

/**
 * oc:8997 — `properties.forestas` sopravvive in tutte le versioni del JSON della traccia.
 *
 * Il blocco nasce in `App\Models\EcTrack::getDetailGeojson()`, che il package non conosce: il
 * refactor che ha separato la forma corrente da quella senza numero passa proprio di lì, e lo
 * legge il sito `wp-forestas`. Perderlo romperebbe il sito oltre alle app, e il package non
 * potrebbe accorgersene.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'filesystems.disks.s3.region' => 'eu-west-1',
        'filesystems.disks.wmfe.region' => 'eu-west-1',
        'cache.stores.redis.driver' => 'array',
        'database.redis.client' => 'predis',
        'wm-package.wmfe_track_versions' => 'legacy,v1',
    ]);
    Storage::fake('wmfe');
    Storage::fake('s3');

    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function versionedTrack(array $forestas = []): EcTrack
{
    $created = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'osmid' => null,
        'properties' => ['forestas' => $forestas, 'ref' => 'SS-1', 'sardegnasentieri_id' => 42],
    ]);

    // Il job ricarica il modello da `EC_TRACK_MODEL`, che qui è App\Models\EcTrack.
    return EcTrack::findOrFail($created->id);
}

/**
 * Il contenuto grezzo del file scritto dal job, per versione. Il percorso si compone dal nome
 * dello shard: in locale `SHARD_NAME` non è valorizzata e vale `APP_NAME`, quindi non è
 * `forestasdev`.
 */
function writtenTrackRaw(EcTrack $track, string $versionPrefix): string
{
    $shard = config('wm-package.shard_name');
    $path = "/{$shard}/tracks/{$versionPrefix}{$track->id}.json";

    $contents = Storage::disk('wmfe')->get($path);
    expect($contents)->not->toBeNull("Manca il file $path");

    return (string) $contents;
}

/**
 * Lo stesso file, decodificato.
 */
function writtenTrackJson(EcTrack $track, string $versionPrefix): array
{
    $shard = config('wm-package.shard_name');
    $path = "/{$shard}/tracks/{$versionPrefix}{$track->id}.json";

    $contents = Storage::disk('wmfe')->get($path);
    expect($contents)->not->toBeNull("Manca il file $path");

    return json_decode((string) $contents, true);
}

it('tiene properties.forestas nel file senza versione, che è quello che legge wp-forestas', function () {
    $track = versionedTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = writtenTrackJson($track, '');

    expect($legacy['properties'])->toHaveKey('forestas')
        ->and($legacy['properties']['forestas'])->toHaveKey('downloads')
        ->and($legacy['properties']['forestas'])->toHaveKey('sardegnasentieri_id');
});

it('tiene lo stesso properties.forestas anche nella v1, oggetto vuoto compreso', function () {
    // Senza dati di Forestas il blocco è un oggetto vuoto, e App\Models\EcTrack:91 lo cura
    // apposta perché non diventi `[]`. Il confronto parte dalle stringhe grezze dei due file:
    // dopo un json_decode associativo `{}` e `[]` sarebbero già lo stesso array.
    $track = versionedTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = json_decode(writtenTrackRaw($track, ''));
    $v1 = json_decode(writtenTrackRaw($track, 'v1/'));

    expect($v1->properties->forestas)->toEqual($legacy->properties->forestas)
        ->and($v1->properties->forestas)->toBeInstanceOf(stdClass::class);
});

it('tratta layer_name allo stesso modo in tutte le versioni', function () {
    // La traccia del test non sta in un layer di tipologia, quindi `typologyLayerName()` torna
    // null e la chiave non c'è: quello che va provato è che le due versioni si comportino
    // uguale, perché `layer_name` lo aggiunge lo shard nel punto che il refactor ha toccato.
    $track = versionedTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    $legacy = writtenTrackJson($track, '')['properties'];
    $v1 = writtenTrackJson($track, 'v1/')['properties'];

    expect(array_key_exists('layer_name', $v1))->toBe(array_key_exists('layer_name', $legacy));
});

it('toglie sardegnasentieri_id dalle properties e lo lascia dentro forestas, in entrambe le versioni', function () {
    $track = versionedTrack();

    (new UpdateEcTrackAwsJob($track))->handle(new StorageService);

    foreach (['', 'v1/'] as $prefix) {
        $properties = writtenTrackJson($track, $prefix)['properties'];

        expect($properties)->not->toHaveKey('sardegnasentieri_id')
            ->and($properties['forestas'])->toHaveKey('sardegnasentieri_id');
    }
});

it('nella v1 le tassonomie hanno la sola forma taxonomies, nel file senza versione ci sono tutte', function () {
    $track = versionedTrack();
    $where = TaxonomyWhere::create(['name' => ['it' => 'Zona '.uniqid()]]);
    $track->taxonomyWheres()->attach($where->id);
    $track->properties = [...$track->properties, 'taxonomy_where' => [(string) $where->id => ['name' => ['it' => 'Zona'], 'admin_level' => 8]]];
    $track->saveQuietly();

    (new UpdateEcTrackAwsJob($track->fresh()))->handle(new StorageService);

    $legacy = writtenTrackJson($track, '')['properties'];
    $v1 = writtenTrackJson($track, 'v1/')['properties'];

    expect($legacy)->toHaveKeys(['taxonomies', 'taxonomy_where', 'taxonomyWheres'])
        ->and($v1)->toHaveKey('taxonomies')
        ->and($v1)->not->toHaveKey('taxonomy')
        ->and($v1)->not->toHaveKey('taxonomy_where')
        ->and($v1)->not->toHaveKey('taxonomyWheres');
});
