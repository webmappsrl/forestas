<?php

declare(strict_types=1);

use App\Dto\Import\SardegnaSentieriImageManifest;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\DrupalRecordReader;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

uses(RefreshDatabase::class);

/**
 * Didascalia delle foto: su Drupal è il testo alternativo dell'immagine,
 * quello che il sito mostra sotto la foto (oc:8932).
 */

class CaptionFileDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $file = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($file, 'x');

        return $file;
    }
}

/**
 * @return array<string, mixed>
 */
function captionImageMedia(string $file, ?string $alt): array
{
    return [
        'target_id' => '1',
        'entity' => [
            'langcode' => 'it',
            'translations' => ['it' => ['name' => $file]],
            'fields' => [
                'field_autore' => ['it' => [0 => ['value' => 'Gianluca Schirru']]],
                'field_credits' => ['it' => [0 => ['value' => 'Agenzia Forestas']]],
                'field_media_image' => ['it' => [0 => [
                    'alt' => $alt,
                    'entity' => ['uri' => "public://{$file}"],
                ]]],
            ],
        ],
    ];
}

function captionManifest(string $file, ?string $alt): array
{
    $record = [
        'entity_type' => 'node', 'id' => 10, 'bundle' => 'sentiero', 'langcode' => 'it',
        'translations' => ['it' => ['title' => 'Titolo', 'created' => 1760000000, 'changed' => 1760000000]],
        'fields' => ['field_immagine_principale_media' => ['it' => [0 => captionImageMedia($file, $alt)]]],
        'aliases' => [],
    ];
    $reader = Mockery::mock(DrupalRecordReader::class);
    $reader->shouldReceive('node')->with(10)->andReturn($record);

    return SardegnaSentieriImageManifest::fromApiTrackResponse((new SardegnaSentieriDatabaseSource($reader))->getTrackDetail(10));
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
    config(['media-library.media_downloader' => CaptionFileDownloader::class]);
});

it('salva la didascalia che Drupal mostra sotto la foto', function () {
    $file = 'Nuoro_20e_20Ortobene_20visti_20dal_20Monte_20Corrasi_2C_20Oliena.jpg';
    $items = captionManifest($file, 'Nuoro e Ortobene visti dal Monte Corrasi, Oliena');
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedImages($ente, $items);

    $media = $ente->fresh()->getMedia('default');

    expect($media)->toHaveCount(1)
        ->and($media[0]->custom_properties['sardegnasentieri_caption'])->toBe('Nuoro e Ortobene visti dal Monte Corrasi, Oliena')
        ->and($media[0]->custom_properties['sardegnasentieri_autore'])->toBe('Gianluca Schirru')
        ->and($media[0]->custom_properties['sardegnasentieri_credits'])->toBe('Agenzia Forestas')
        // Il name resta quello di prima: il nome del file, non la didascalia.
        ->and($media[0]->name)->not->toBe('Nuoro e Ortobene visti dal Monte Corrasi, Oliena');
});

it('non sovrascrive la didascalia con il nome del file quando manca', function () {
    $file = 'foto.jpg';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);
    $sync = app(SardegnaSentieriMediaSyncService::class);

    $sync->syncImportedImages($ente, captionManifest($file, 'Didascalia vera'));
    $sync->syncImportedImages($ente->fresh(), captionManifest($file, null));

    $media = $ente->fresh()->getMedia('default');

    expect($media)->toHaveCount(1)
        ->and($media[0]->custom_properties['sardegnasentieri_caption'])->toBe('Didascalia vera');

    // Senza didascalia fin dall'inizio, la chiave non c'è: niente nome del file al suo posto.
    $other = Ente::create(['sardegnasentieri_id' => '2', 'name' => ['it' => 'Altro'], 'properties' => []]);
    $sync->syncImportedImages($other, captionManifest('altra.jpg', ''));

    expect($other->fresh()->getMedia('default')[0]->custom_properties)->not->toHaveKey('sardegnasentieri_caption');
});

it('tiene una sola copia per file, quella con la didascalia', function () {
    // Due esecuzioni dello stesso job dei media non si vedono fra loro e scaricano entrambe:
    // sui dati veri sono nate 120 righe doppie su 57 file, in 46 casi una con la didascalia e
    // una senza, e la scheda mostrava la stessa foto due volte (oc:8932).
    $file = 'doppia.jpg';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    $url = captionManifest($file, null)[0]['url'];

    // Le due righe che le esecuzioni concorrenti hanno gia' lasciato: stesso file, una con la
    // didascalia e una senza. La copia vuota nasce per seconda, cosi' il test fallisce se la
    // deduplica tiene la piu' recente invece di quella che porta il dato.
    foreach (['La didascalia buona', null] as $caption) {
        $props = ['sardegnasentieri_import' => true, 'sardegnasentieri_source_url' => $url];
        if ($caption !== null) {
            $props['sardegnasentieri_caption'] = $caption;
        }
        $ente->addMediaFromString('x')->usingFileName($file)->withCustomProperties($props)->toMediaCollection('default');
    }

    expect($ente->fresh()->getMedia('default'))->toHaveCount(2);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedImages($ente->fresh(), captionManifest($file, 'La didascalia buona'));

    $media = $ente->fresh()->getMedia('default');

    expect($media)->toHaveCount(1)
        ->and($media[0]->custom_properties['sardegnasentieri_caption'])->toBe('La didascalia buona');
});
