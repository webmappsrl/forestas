<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Wm\WmPackage\Http\Resources\MediaResource;

/**
 * `properties.forestas` nel dettaglio pubblico del POI (oc:8959), come `ForestasTrackDetail` per
 * le tracce: si costruisce in uscita, il `properties.forestas` salvato nel DB resta com'è (lo usano
 * import e Nova), le chiavi sono in inglese e restano fuori gli id di Drupal (`poi_correlati`,
 * `source_relations`, `zona_geografica`) e le chiavi interne dell'import. Le chiavi vuote non escono.
 */
final class ForestasPoiDetail
{
    /**
     * Quanti percorsi mostra il dettaglio, dal più vicino: come la tavola 7 del wireframe.
     */
    public const NEARBY_TRACKS_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public static function build(EcPoi $poi): array
    {
        $stored = (array) ($poi->properties['forestas'] ?? []);
        $tracks = self::nearbyTracks($poi);

        return array_filter([
            'url' => $stored['url'] ?? null,
            'code' => $stored['codice'] ?? null,
            'created_at' => $stored['created_at'] ?? null,
            'updated_at' => $stored['updated_at'] ?? null,
            // Il modello di forestas gestisce i valori legacy `null` di come_arrivare.
            'directions' => $poi->getTranslations('properties->forestas->come_arrivare'),
            'videos' => [
                ...ForestasDetailParts::remoteVideos($stored['video'] ?? []),
                ...ForestasDetailParts::files($poi, SardegnaSentieriMediaSyncService::VIDEOS_COLLECTION),
            ],
            'attachments' => ForestasDetailParts::files($poi, SardegnaSentieriMediaSyncService::ATTACHMENTS_COLLECTION),
            'organizations' => ForestasDetailParts::organizations($poi->enti),
            'related_pois' => self::relatedPois($poi),
            'nearby_tracks' => array_slice($tracks, 0, self::NEARBY_TRACKS_LIMIT),
            'nearby_tracks_count' => $tracks === [] ? null : count($tracks),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * I POI correlati importati da Drupal (`ec_poi_related_pois`, resi reciproci da oc:8932), con i
     * dati della card dei POI del sito: nome, foto, località e quota. La quota esce solo se la
     * geometria ne ha una: oggi è 0 su tutti i POI, e la quota di Drupal sta nel testo della
     * località (es. «Tuones (1025 m)»).
     *
     * @return list<array<string, mixed>>
     */
    private static function relatedPois(EcPoi $poi): array
    {
        $cards = [];
        $related = $poi->relatedPois()
            ->with('media')
            ->select('ec_pois.*')
            ->selectRaw('ST_Z(ec_pois.geometry::geometry) AS elevation_z')
            ->orderBy('ec_pois.id')
            ->get();
        /** @var EcPoi $item */
        foreach ($related as $item) {
            $photo = $item->getMedia()->first();
            $elevation = (float) $item->getAttribute('elevation_z');
            $cards[] = array_filter([
                'id' => (int) $item->id,
                'name' => $item->getTranslations('name'),
                'feature_image' => $photo === null ? null : (new MediaResource($photo))->resolve(),
                'addr_complete' => $item->properties['addr_complete'] ?? null,
                'elevation' => $elevation === 0.0 ? null : $elevation,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $cards;
    }

    /**
     * I percorsi collegati al POI da Drupal (`ec_poi_ec_track`), dal più vicino: la card delle
     * tracce (`ForestasDetailParts::trackCard`) più `distance_from_poi`, in metri dal tratto più
     * vicino.
     *
     * @return list<array<string, mixed>>
     */
    private static function nearbyTracks(EcPoi $poi): array
    {
        $cards = [];
        /** @var EcTrack $track */
        foreach ($poi->nearbyTracks()->with(ForestasDetailParts::TRACK_CARD_RELATIONS)->get() as $track) {
            $cards[] = [
                ...ForestasDetailParts::trackCard($track),
                'distance_from_poi' => round((float) $track->getAttribute('distance_from_poi'), 1),
            ];
        }

        return $cards;
    }
}
