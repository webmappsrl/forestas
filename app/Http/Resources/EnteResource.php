<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Http\Resources\MediaResource;

/**
 * Un ente nel JSON degli enti (oc:8965), oggi usato dal sito WordPress: i dati della scheda Drupal, più tracce e POI
 * collegati con i loro ruoli. Il contratto è fissato da tests/Feature/Entes/EnteIndexTest.php.
 *
 * La chiave `en` di nome, descrizione e contatti contiene il testo italiano: su Drupal gli enti
 * sono solo in italiano, l'import lo copia anche lì.
 *
 * @mixin Ente
 */
class EnteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ente $ente */
        $ente = $this->resource;
        $media = $ente->getMedia('default');
        $properties = is_array($ente->properties) ? $ente->properties : [];

        return [
            'id' => (int) $ente->id,
            'slug' => $ente->slug,
            'name' => $ente->getTranslations('name'),
            'type' => $ente->tipoEnteValue(),
            'description' => $ente->getTranslations('description'),
            'contacts' => $ente->getTranslations('contatti'),
            'website' => empty($ente->pagina_web) ? null : [
                'url' => $ente->pagina_web,
                'title' => $ente->pagina_web_title,
            ],
            'geometry' => $this->point($ente),
            // Prima foto per order_column = immagine principale, come nella card dei sentieri
            // (ForestasDetailParts::trackCard); le altre sono la galleria.
            'feature_image' => $media->isEmpty() ? null : $this->image($media->first()),
            'image_gallery' => $media->slice(1)->map(fn (Media $item) => $this->image($item))->values()->all(),
            'related_url' => $properties['related_url'] ?? [],
            'created_at' => $ente->drupal_created_at?->toIso8601String(),
            'updated_at' => $properties['forestas']['updated_at'] ?? null,
            'tracks' => $this->tracks($ente->ecTracks),
            'pois' => $this->pois($ente->ecPois),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function image(Media $media): array
    {
        return (new MediaResource($media))->resolve();
    }

    /**
     * Punto 2D dalle colonne `longitude` e `latitude` che EnteController seleziona insieme
     * all'ente (la colonna è una geography 3D).
     *
     * @return array{type: string, coordinates: array{0: float, 1: float}}|null
     */
    private function point(Ente $ente): ?array
    {
        $longitude = $ente->getAttribute('longitude');
        $latitude = $ente->getAttribute('latitude');
        if ($longitude === null || $latitude === null) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]];
    }

    /**
     * Una voce per traccia, con i ruoli dell'ente su quella traccia in ordine alfabetico.
     *
     * @param  Collection<int, Model>  $tracks
     * @return list<array<string, mixed>>
     */
    private function tracks(Collection $tracks): array
    {
        return $tracks->groupBy('id')->map(function (Collection $rows) {
            /** @var EcTrack $track */
            $track = $rows->first();

            return [
                'id' => (int) $track->id,
                'ref' => $track->getAttribute('ref'),
                'name' => $track->getTranslations('name'),
                'validation_status' => $track->term(EcTrack::VALIDATION_STATUS_TAXONOMY)?->identifier,
                'roles' => $this->roles($rows),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Model>  $pois
     * @return list<array<string, mixed>>
     */
    private function pois(Collection $pois): array
    {
        return $pois->groupBy('id')->map(function (Collection $rows) {
            /** @var EcPoi $poi */
            $poi = $rows->first();

            return [
                'id' => (int) $poi->id,
                'name' => $poi->getTranslations('name'),
                'roles' => $this->roles($rows),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return list<string>
     */
    private function roles(Collection $rows): array
    {
        return $rows->map(fn (Model $row) => (string) $row->getRelation('pivot')->getAttribute('ruolo'))
            ->unique()->sort()->values()->all();
    }
}
