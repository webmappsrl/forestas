<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

class SardegnaSentieriMediaSyncService
{
    private const COLLECTION = 'default';

    /**
     * @param  list<array{url: string, autore: string, credits: string, order: int}>  $items
     */
    public function syncImportedImages(Model&HasMedia $model, array $items): void
    {
        $manifestUrls = [];

        foreach ($items as $item) {
            $url = $item['url'];
            $manifestUrls[$url] = true;

            try {
                $this->syncOne($model, $item);
            } catch (\Throwable $e) {
                Log::warning('SardegnaSentieri media import failed for single URL', [
                    'model' => $model::class,
                    'model_id' => $model->getKey(),
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->pruneRemoved($model, array_keys($manifestUrls));
    }

    /**
     * @param  array{url: string, autore: string, credits: string, order: int}  $item
     */
    private function syncOne(Model&HasMedia $model, array $item): void
    {
        $url = $item['url'];
        $disk = config('wm-media-library.disk_name');

        $existing = $model->getMedia(self::COLLECTION)
            ->first(function ($media) use ($url) {
                $props = $media->custom_properties ?? [];

                return ($props['sardegnasentieri_import'] ?? false) === true
                    && ($props['sardegnasentieri_source_url'] ?? '') === $url;
            });

        $customProps = [
            'sardegnasentieri_import' => true,
            'sardegnasentieri_source_url' => $url,
            'sardegnasentieri_autore' => $item['autore'],
            'sardegnasentieri_credits' => $item['credits'],
        ];

        if ($existing !== null) {
            $existing->updateQuietly([
                'order_column' => $item['order'],
                'custom_properties' => array_merge((array) $existing->custom_properties, $customProps),
            ]);

            return;
        }

        $fileName = $this->sanitizeFileName($url);

        $mediaItem = $model->addMediaFromUrl($url)
            ->usingName(pathinfo($fileName, PATHINFO_FILENAME) ?: 'image')
            ->usingFileName($fileName)
            ->withCustomProperties($customProps)
            ->toMediaCollection(self::COLLECTION, $disk);

        $mediaItem->updateQuietly(['order_column' => $item['order']]);
    }

    /**
     * @param  list<string>  $manifestUrls
     */
    private function pruneRemoved(Model&HasMedia $model, array $manifestUrls): void
    {
        $keep = array_flip($manifestUrls);

        foreach ($model->getMedia(self::COLLECTION) as $media) {
            $props = $media->custom_properties ?? [];
            if (($props['sardegnasentieri_import'] ?? false) !== true) {
                continue;
            }

            $src = (string) ($props['sardegnasentieri_source_url'] ?? '');
            if ($src === '' || isset($keep[$src])) {
                continue;
            }

            $media->delete();
        }
    }

    private function sanitizeFileName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = is_string($path) ? basename($path) : 'image';
        $base = $base !== '' ? $base : 'image';
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'image';

        if ($sanitized === '_' || strpos($sanitized, '.') === false) {
            $sanitized = 'sardegnasentieri_'.Str::random(8).'.jpg';
        }

        return $sanitized;
    }
}
