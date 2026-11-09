<?php

declare(strict_types=1);

use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;

uses(RefreshDatabase::class);

/**
 * Downloader di Spatie che non passa dalla rete: scrive un file di 30 MB,
 * oppure fallisce se l'URL contiene «rotto».
 */
class ThirtyMegabyteDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        if (str_contains($url, 'rotto')) {
            throw UnreachableUrl::create($url);
        }

        $file = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($file, str_repeat('a', 30 * 1024 * 1024));

        return $file;
    }
}

/**
 * @return array{url: string, autore: string, credits: string, order: int}
 */
function bigFileItem(string $url): array
{
    return ['url' => $url, 'autore' => '', 'credits' => '', 'order' => 1];
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
        'media-library.media_downloader' => ThirtyMegabyteDownloader::class,
    ]);
});

it('accetta un file da 30 MB benché il limite dello shard sia 20', function () {
    $url = 'https://example.org/allegato.pdf';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedImages($ente, [bigFileItem($url)]);

    expect($ente->fresh()->getMedia('default'))->toHaveCount(1);
});

it('lascia invariato il limite generale dopo lo scaricamento', function () {
    $url = 'https://example.org/allegato.pdf';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedImages($ente, [bigFileItem($url)]);

    expect(config('media-library.max_file_size'))->toBe(1024 * 1024 * 20);
});

it('ripristina il limite generale anche se lo scaricamento fallisce', function () {
    $url = 'https://example.org/rotto.pdf';
    $ente = Ente::create(['sardegnasentieri_id' => '1', 'name' => ['it' => 'Ente'], 'properties' => []]);

    app(SardegnaSentieriMediaSyncService::class)->syncImportedImages($ente, [bigFileItem($url)]);

    expect(config('media-library.max_file_size'))->toBe(1024 * 1024 * 20);
});
