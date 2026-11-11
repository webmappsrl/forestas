<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EcTrack;
use App\Support\RelatedTrackCards;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Aggiorna le card dei sentieri correlati quando cambia la foto che mostrano (oc:8959): la prima
 * della collection `default` della traccia, con i suoi crediti. Le altre foto non entrano nella
 * card, e cambiarle non riscrive nulla.
 */
class RelatedTrackMediaObserver
{
    private const COLLECTION = 'default';

    /**
     * Le colonne di una foto che finiscono nella card: indirizzo, nome, crediti, formati.
     */
    private const CARD_COLUMNS = ['file_name', 'disk', 'name', 'custom_properties', 'generated_conversions', 'responsive_images'];

    public function saved(Media $media): void
    {
        if (! $this->isTrackPhoto($media)) {
            return;
        }

        $first = $this->isFirst($media, (int) $media->order_column);

        $relevant = match (true) {
            $media->wasRecentlyCreated => $first,
            // Spostata: conta se è diventata la prima o se lo era prima.
            $media->wasChanged('order_column') => $first || $this->isFirst($media, (int) $media->getOriginal('order_column')),
            default => $first && $media->wasChanged(self::CARD_COLUMNS),
        };

        if ($relevant) {
            RelatedTrackCards::refresh((int) $media->model_id);
        }
    }

    public function deleted(Media $media): void
    {
        if ($this->isTrackPhoto($media) && $this->isFirst($media, (int) $media->order_column)) {
            RelatedTrackCards::refresh((int) $media->model_id);
        }
    }

    private function isTrackPhoto(Media $media): bool
    {
        return $media->collection_name === self::COLLECTION
            && $media->model_type === (new EcTrack)->getMorphClass();
    }

    /**
     * Se con $order la foto sta prima di tutte le altre foto della traccia: `getMedia()` le
     * ordina per `order_column`.
     */
    private function isFirst(Media $media, int $order): bool
    {
        $minOthers = $media->newQuery()
            ->where('model_type', $media->model_type)
            ->where('model_id', $media->model_id)
            ->where('collection_name', self::COLLECTION)
            ->whereKeyNot($media->getKey())
            ->min('order_column');

        return $minOthers === null || $order <= (int) $minOthers;
    }
}
