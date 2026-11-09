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
     * Collection degli allegati (PDF e altri documenti) e dei video caricati.
     * Mai `default`: è quella delle immagini, da cui si legge la copertina
     * indicizzata delle tracce.
     */
    public const ATTACHMENTS_COLLECTION = 'attachments';

    public const VIDEOS_COLLECTION = 'videos';

    /**
     * Limite di dimensione, in byte, per i soli file scaricati dall'import.
     * Il limite generale dello shard (20 MB) resta invariato: lo alza solo
     * lo scaricamento, perché il file più grande da importare è un video da
     * 78,2 MB.
     */
    public const IMPORT_MAX_FILE_SIZE = 1024 * 1024 * 80;

    /**
     * @param  list<array{url: string, autore: string, credits: string, caption?: string, order: int}>  $items
     */
    public function syncImportedImages(Model&HasMedia $model, array $items): void
    {
        $this->syncCollection($model, self::COLLECTION, $items);
    }

    /**
     * Allegati o video: come le immagini, in una collection a parte e con il
     * titolo che Drupal mostra. Con la lista vuota si tolgono i file importati
     * che Drupal non ha più.
     *
     * @param  list<array{url: string, title: string, order: int}>  $files
     */
    public function syncImportedFiles(Model&HasMedia $model, string $collection, array $files): void
    {
        $this->syncCollection($model, $collection, array_map(
            fn (array $file) => $file + ['autore' => '', 'credits' => ''],
            $files,
        ));
    }

    /**
     * @param  list<array{url: string, autore: string, credits: string, caption?: string, order: int, title?: string}>  $items
     */
    private function syncCollection(Model&HasMedia $model, string $collection, array $items): void
    {
        $manifestUrls = [];

        foreach ($items as $item) {
            $url = $item['url'];
            $manifestUrls[$url] = true;

            try {
                $this->syncOne($model, $collection, $item);
            } catch (\Throwable $e) {
                Log::warning('SardegnaSentieri media import failed for single URL', [
                    'model' => $model::class,
                    'model_id' => $model->getKey(),
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->pruneRemoved($model, $collection, array_keys($manifestUrls));
        $this->pruneDuplicates($model, $collection);
    }

    /**
     * @param  array{url: string, autore: string, credits: string, caption?: string, order: int, title?: string}  $item
     */
    private function syncOne(Model&HasMedia $model, string $collection, array $item): void
    {
        $url = $item['url'];
        $disk = config('wm-media-library.disk_name');

        $existing = $model->getMedia($collection)
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
        // La didascalia sta a parte dal `name`, che resta il nome del file. Se
        // Drupal non ne ha una, la chiave non si scrive e quella già salvata
        // non si tocca.
        $caption = trim((string) ($item['caption'] ?? ''));
        if ($caption !== '') {
            $customProps['sardegnasentieri_caption'] = $caption;
        }
        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
            $customProps['sardegnasentieri_title'] = $title;
        }

        if ($existing !== null) {
            $existing->updateQuietly([
                ...($title !== '' ? ['name' => $title] : []),
                'order_column' => $item['order'],
                'custom_properties' => array_merge((array) $existing->custom_properties, $customProps),
            ]);

            return;
        }

        $fileName = $this->sanitizeFileName($url);

        // Spatie legge il limite al momento dell'aggiunta. Non si può metterlo
        // in config/wm-media-library.php: il package lo riversa in
        // `media-library` e varrebbe anche per Nova e per le foto dell'app.
        $previousLimit = config('media-library.max_file_size');
        config(['media-library.max_file_size' => self::IMPORT_MAX_FILE_SIZE]);

        try {
            $mediaItem = $model->addMediaFromUrl($url)
                ->usingName($title !== '' ? $title : (pathinfo($fileName, PATHINFO_FILENAME) ?: 'image'))
                ->usingFileName($fileName)
                ->withCustomProperties($customProps)
                ->toMediaCollection($collection, $disk);
        } finally {
            config(['media-library.max_file_size' => $previousLimit]);
        }

        $mediaItem->updateQuietly(['order_column' => $item['order']]);
    }

    /**
     * @param  list<string>  $manifestUrls
     */
    private function pruneRemoved(Model&HasMedia $model, string $collection, array $manifestUrls): void
    {
        $keep = array_flip($manifestUrls);

        foreach ($model->getMedia($collection) as $media) {
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

    /**
     * Tiene una sola riga per indirizzo di origine, dentro lo stesso contenuto e la stessa
     * collection (oc:8932).
     *
     * `syncOne()` riconosce un file gia' scaricato leggendo i media che il modello ha in memoria:
     * due esecuzioni dello stesso job non si vedono fra loro, controllano insieme e scaricano
     * entrambe. Sui dati veri sono nate cosi' 120 righe doppie su 57 file, a mezzo minuto l'una
     * dall'altra e con lo stesso `order_column`; in 46 casi una copia aveva la didascalia e
     * l'altra no, e la scheda mostrava due volte la stessa foto.
     *
     * Si tiene la copia con la didascalia — e a parita', la piu' vecchia — perche' e' quella che
     * porta il dato. Gira a ogni sync, quindi riassorbe anche le righe doppie gia' create prima.
     * La sovrapposizione dei job e' impedita a monte, ma questa resta la rete: un media duplicato
     * non si nota finche' qualcuno non apre la scheda.
     */
    private function pruneDuplicates(Model&HasMedia $model, string $collection): void
    {
        $byUrl = [];

        foreach ($model->getMedia($collection) as $media) {
            $props = $media->custom_properties ?? [];
            if (($props['sardegnasentieri_import'] ?? false) !== true) {
                continue;
            }

            $src = (string) ($props['sardegnasentieri_source_url'] ?? '');
            if ($src === '') {
                continue;
            }

            $byUrl[$src][] = $media;
        }

        foreach ($byUrl as $copies) {
            if (count($copies) < 2) {
                continue;
            }

            usort($copies, function ($a, $b) {
                $captionOf = fn ($m) => trim((string) (($m->custom_properties ?? [])['sardegnasentieri_caption'] ?? '')) !== '' ? 0 : 1;

                return [$captionOf($a), $a->id] <=> [$captionOf($b), $b->id];
            });

            foreach (array_slice($copies, 1) as $duplicate) {
                $duplicate->delete();
            }
        }

        $model->unsetRelation('media');
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
