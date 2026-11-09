<?php

declare(strict_types=1);

use App\Dto\Import\SardegnaSentieriImageManifest;
use App\Jobs\Import\ImportSardegnaSentieriPoiMediaJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackMediaJob;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

uses(RefreshDatabase::class);

/**
 * Allegati e video: letti dalla copia Drupal con il titolo del media e
 * copiati nelle collection `attachments` e `videos` (oc:8932).
 */

/**
 * Downloader di Spatie senza rete: scrive un file «sparse» della dimensione
 * indicata nell'URL (`?size=<byte>`), 1 KB se manca.
 */
class SizedFileDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $size = (int) ($query['size'] ?? 1024);

        $file = tempnam(sys_get_temp_dir(), 'media-library');
        $handle = fopen($file, 'w');
        ftruncate($handle, $size);
        fclose($handle);

        return $file;
    }
}

/**
 * @return array<string, mixed>
 */
function drupalFileMedia(string $fileField, string $file, string $title): array
{
    return [
        'target_id' => '1',
        'entity' => [
            'langcode' => 'it',
            'translations' => ['it' => ['name' => $title]],
            'fields' => [$fileField => ['it' => [0 => ['entity' => ['uri' => "public://{$file}"]]]]],
        ],
    ];
}

/**
 * @param  array<string, array<string, array<int, array<string, mixed>>>>  $fields
 */
function drupalSource(string $bundle, array $fields): SardegnaSentieriDatabaseSource
{
    $record = [
        'entity_type' => 'node',
        'id' => 10,
        'bundle' => $bundle,
        'langcode' => 'it',
        'translations' => ['it' => ['title' => 'Titolo', 'created' => 1760000000, 'changed' => 1760000000]],
        'fields' => $fields,
        'aliases' => [],
    ];

    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(10)->andReturn($record);

    return new SardegnaSentieriDatabaseSource($reader);
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    $user = User::firstOrCreate(['email' => 'forestas@webmapp.it'], ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]);
    $user->assignRole('Editor');
    App::withoutEvents(fn () => App::query()->create([
        'id' => 1, 'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri', 'customer_name' => 'forestas', 'user_id' => $user->id,
    ]));
    Storage::fake(config('wm-media-library.disk_name'));
    config([
        'media-library.max_file_size' => 1024 * 1024 * 20,
        'media-library.media_downloader' => SizedFileDownloader::class,
    ]);
});

it('copia gli allegati di un sentiero nella collection attachments', function () {
    $detail = drupalSource('sentiero', [
        'field_allegati_media' => ['it' => [
            0 => drupalFileMedia('field_media_document', 'carta 1.pdf', 'Carta 1'),
            1 => drupalFileMedia('field_media_document', 'carta 2.pdf', 'Carta 2'),
        ]],
    ])->getTrackDetail(10);

    $base = rtrim((string) config('forestas.sardegnasentieri.files_url'), '/');
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedFiles(
        $ente,
        SardegnaSentieriMediaSyncService::ATTACHMENTS_COLLECTION,
        SardegnaSentieriImageManifest::fromFiles($detail->attachmentFiles),
    );

    $media = $ente->fresh()->getMedia('attachments');

    // `properties.forestas.allegati` resta com'è: gli indirizzi continuano a esserci.
    expect($detail->allegati)->toBe(["{$base}/carta%201.pdf", "{$base}/carta%202.pdf"])
        ->and($media)->toHaveCount(2)
        ->and($media[0]->custom_properties['sardegnasentieri_source_url'])->toBe("{$base}/carta%201.pdf")
        ->and($media[1]->order_column)->toBe(1)
        ->and($ente->fresh()->getMedia('default'))->toHaveCount(0);
});

it('salva il titolo che Drupal mostra sull\'allegato', function () {
    $detail = drupalSource('sentiero', [
        'field_allegati_media' => ['it' => [0 => drupalFileMedia('field_media_document', 'CARTA n.14_IT_EN.pdf', 'Carta 14 - Italiano/Inglese')]],
    ])->getTrackDetail(10);
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    $sync = app(SardegnaSentieriMediaSyncService::class);
    $files = SardegnaSentieriImageManifest::fromFiles($detail->attachmentFiles);
    $sync->syncImportedFiles($ente, 'attachments', $files);
    // Un secondo giro (un job nuovo ricarica il modello) non duplica il file.
    $sync->syncImportedFiles($ente->fresh(), 'attachments', $files);

    $media = $ente->fresh()->getMedia('attachments');

    expect($media)->toHaveCount(1)
        ->and($media[0]->name)->toBe('Carta 14 - Italiano/Inglese')
        ->and($media[0]->custom_properties['sardegnasentieri_title'])->toBe('Carta 14 - Italiano/Inglese')
        ->and($media[0]->file_name)->toEndWith('.pdf');
});

it('copia gli allegati dei POI, che oggi sono sempre vuoti', function () {
    $detail = drupalSource('poi', [
        'field_posizione' => ['it' => [0 => ['lon' => 9.1, 'lat' => 41.1]]],
        'field_allegati_media' => ['it' => [0 => drupalFileMedia('field_media_document', 'scheda.pdf', 'Scheda del punto')]],
    ])->getPoiDetail(10);

    $base = rtrim((string) config('forestas.sardegnasentieri.files_url'), '/');
    expect($detail->allegati)->toBe(["{$base}/scheda.pdf"])
        ->and($detail->attachmentFiles)->toBe([['url' => "{$base}/scheda.pdf", 'title' => 'Scheda del punto']]);

    $poi = EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'POI'], 'properties' => [], 'app_id' => 1, 'user_id' => User::first()->id,
        'geometry' => DB::raw("ST_GeomFromText('POINT(9.1 41.1)', 4326)"),
    ]));

    (new ImportSardegnaSentieriPoiMediaJob(
        $poi->id,
        [],
        SardegnaSentieriImageManifest::fromFiles($detail->attachmentFiles),
        SardegnaSentieriImageManifest::fromFiles($detail->uploadedVideos),
    ))->handle(app(SardegnaSentieriMediaSyncService::class));

    expect($poi->fresh()->getMedia('attachments'))->toHaveCount(1);
});

it('copia i video caricati su Drupal, anche quello da 78 MB', function () {
    $detail = drupalSource('sentiero', [
        'field_video_media' => ['it' => [
            0 => drupalFileMedia('field_media_video_file', '2024-03/Rio Oridda.mp4', 'Rio Oridda'),
            // Un video di YouTube: nessun file, resta in `video`.
            1 => ['target_id' => '2', 'entity' => [
                'langcode' => 'it',
                'translations' => ['it' => ['name' => 'YouTube']],
                'fields' => ['field_media_oembed_video' => ['it' => [0 => ['value' => 'https://youtu.be/abc']]]],
            ]],
        ]],
    ])->getTrackDetail(10);

    expect($detail->video)->toBe(['https://youtu.be/abc'])
        ->and($detail->uploadedVideos)->toHaveCount(1);

    $files = SardegnaSentieriImageManifest::fromFiles($detail->uploadedVideos);
    $files[0]['url'] .= '?size=78185555';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedFiles($ente, 'videos', $files);

    $media = $ente->fresh()->getMedia('videos');

    expect($media)->toHaveCount(1)
        ->and($media[0]->size)->toBe(78185555)
        ->and($media[0]->name)->toBe('Rio Oridda')
        ->and(config('media-library.max_file_size'))->toBe(1024 * 1024 * 20);
});

it('toglie dalla collection gli allegati che Drupal non ha più', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);
    $sync = app(SardegnaSentieriMediaSyncService::class);

    $sync->syncImportedFiles($ente, 'attachments', [['url' => 'https://example.org/a.pdf', 'title' => 'A', 'order' => 0]]);
    $sync->syncImportedFiles($ente->fresh(), 'attachments', []);

    expect($ente->fresh()->getMedia('attachments'))->toHaveCount(0);
});

it('il job dei punti di interesse mette video in videos e allegati in attachments', function () {
    $poi = EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'POI'], 'properties' => [], 'app_id' => 1, 'user_id' => User::first()->id,
        'geometry' => DB::raw("ST_GeomFromText('POINT(9.1 41.1)', 4326)"),
    ]));

    (new ImportSardegnaSentieriPoiMediaJob(
        $poi->id,
        [],
        [['url' => 'https://example.org/scheda.pdf', 'title' => 'Scheda', 'order' => 0]],
        [['url' => 'https://example.org/video.mp4', 'title' => 'Video', 'order' => 0]],
    ))->handle(app(SardegnaSentieriMediaSyncService::class));

    $poi = $poi->fresh();

    expect($poi->getMedia('attachments')->pluck('name')->all())->toBe(['Scheda'])
        ->and($poi->getMedia('videos')->pluck('name')->all())->toBe(['Video'])
        ->and($poi->getMedia('default'))->toHaveCount(0);
});

it('il job delle tracce mette video in videos e allegati in attachments', function () {
    Bus::fake([UpdateEcTrackAwsJob::class]);
    $track = EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Traccia'], 'properties' => [], 'app_id' => 1, 'user_id' => User::first()->id,
        'geometry' => DB::raw("ST_GeomFromText('LINESTRING(9.1 41.1, 9.2 41.2)', 4326)"),
    ]));

    (new ImportSardegnaSentieriTrackMediaJob(
        $track->id,
        [],
        [['url' => 'https://example.org/carta.pdf', 'title' => 'Carta', 'order' => 0]],
        [['url' => 'https://example.org/clip.mp4', 'title' => 'Clip', 'order' => 0]],
    ))->handle(app(SardegnaSentieriMediaSyncService::class));

    $track = $track->fresh();

    expect($track->getMedia('attachments')->pluck('name')->all())->toBe(['Carta'])
        ->and($track->getMedia('videos')->pluck('name')->all())->toBe(['Clip'])
        ->and($track->getMedia('default'))->toHaveCount(0);
    Bus::assertDispatched(UpdateEcTrackAwsJob::class);
});

it('le miniature si registrano sulle immagini e non su allegati e video', function () {
    config(['wm-package.thumbnail_sizes' => [['width' => 100, 'height' => 100]]]);
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);
    $poi = EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => ['it' => 'POI'], 'properties' => [], 'app_id' => 1, 'user_id' => User::first()->id,
        'geometry' => DB::raw("ST_GeomFromText('POINT(9.1 41.1)', 4326)"),
    ]));
    $sync = app(SardegnaSentieriMediaSyncService::class);
    $sync->syncImportedImages($poi, [['url' => 'https://example.org/f.jpg', 'autore' => '', 'credits' => '', 'order' => 0]]);
    $poi = $poi->fresh();
    $sync->syncImportedFiles($poi, 'attachments', [['url' => 'https://example.org/a.pdf', 'title' => 'A', 'order' => 0]]);
    $sync->syncImportedFiles($poi->fresh(), 'videos', [['url' => 'https://example.org/v.mp4', 'title' => 'V', 'order' => 0]]);
    $poi = $poi->fresh();

    $conversions = fn ($media) => ConversionCollection::createForMedia($media)
        ->filter(fn ($conversion) => $conversion->shouldBePerformedOn($media->collection_name))
        ->count();

    expect($conversions($poi->getMedia('default')->first()))->toBeGreaterThan(0)
        ->and($conversions($poi->getMedia('attachments')->first()))->toBe(0)
        ->and($conversions($poi->getMedia('videos')->first()))->toBe(0);
});
