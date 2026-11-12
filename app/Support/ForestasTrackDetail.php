<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriMediaSyncService;

/**
 * `properties.forestas` nel dettaglio pubblico della traccia (oc:8959).
 *
 * Si costruisce in uscita: il `properties.forestas` salvato nel DB resta com'è, perché lo usano
 * import, Nova e job (`source_relations`, `source_id`, `updated_at`…). Nel file pubblico vanno solo
 * i dati che il frontend usa, con chiavi in inglese (scrum del 2026-11-11: «se il front-end non
 * consuma quel dato, non metterlo»). Restano fuori gli id di Drupal (`source_id`,
 * `source_relations`, `zona_geografica`), il GPX di Drupal, che sparirà con il sito, e le chiavi
 * interne dell'import. Le chiavi vuote non escono.
 *
 * Il roadbook non sta qui: è nei Blocchi Dettaglio (`properties.config_detail`, `RoadbookBlock`),
 * un blocco con una riga per tappa scritto dall'import. Didascalia,
 * autore e credits delle foto non stanno qui ma con le foto (`feature_image`, `image_gallery`):
 * li porta il package, dalle custom properties del media (`ImageCredits`, oc:8959).
 */
class ForestasTrackDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function build(EcTrack $track): array
    {
        $stored = (array) ($track->properties['forestas'] ?? []);

        return array_filter([
            // Indirizzo della pagina su Drupal e date della scheda: l'artifact di analisi li dà come
            // già usati dal WordPress.
            'url' => $stored['url'] ?? null,
            'created_at' => $stored['created_at'] ?? null,
            'updated_at' => $stored['updated_at'] ?? null,
            'useful_info' => $stored['info_utili'] ?? null,
            'videos' => [
                ...ForestasDetailParts::remoteVideos($stored['video'] ?? []),
                ...ForestasDetailParts::files($track, SardegnaSentieriMediaSyncService::VIDEOS_COLLECTION),
            ],
            'attachments' => ForestasDetailParts::files($track, SardegnaSentieriMediaSyncService::ATTACHMENTS_COLLECTION),
            'registry_code' => $track->forestas_registry_code,
            'survey_date' => $track->forestas_survey_date?->format('Y-m-d'),
            'inclusivity' => self::inclusivity($track, $stored),
            'organizations' => ForestasDetailParts::organizations($track->enti),
            'nearby_tracks' => self::nearbyTracks($track),
            'downloads' => self::downloads($track),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * I sentieri correlati, calcolati dalla geometria (`CalculateNearbyTracksJob`, entro
     * `sardegnasentieri.nearby_tracks_radius_meters`): sono i «Sentieri correlati» della scheda,
     * che su Drupal si inserivano a mano (scrum del 2026-11-11). Ogni elemento porta quello che
     * mostra la card del carosello nel wireframe: foto, tipo di percorso, nome e codice, tempo e
     * lunghezza. Tempo e lunghezza seguono la precedenza del dettaglio (manuale, OSM, DEM): i
     * sentieri importati non li hanno finché non gira il DEM (oc:8641), e allora sono `null`. La
     * card è `ForestasDetailParts::trackCard()`, la stessa dei percorsi vicini ai POI.
     *
     * @return list<array<string, mixed>>
     */
    private static function nearbyTracks(EcTrack $track): array
    {
        $cards = [];
        /** @var EcTrack $near */
        foreach ($track->relatedTracks()->with(ForestasDetailParts::TRACK_CARD_RELATIONS)->orderBy('ec_tracks.id')->get() as $near) {
            $cards[] = ForestasDetailParts::trackCard($near);
        }

        return $cards;
    }

    /**
     * I link per scaricare la traccia, con le rotte dello shard (il GPX di Drupal sparirà con il
     * sito). GPX e KML dipendono dalla correzione delle rotte di oc:8953.
     *
     * @return array{geojson: string, gpx: string, kml: string}
     */
    private static function downloads(EcTrack $track): array
    {
        return [
            'geojson' => route('default.api.ec.track.download.geojson', ['id' => $track->id]),
            'gpx' => route('default.api.ec.track.download.gpx', ['id' => $track->id]),
            'kml' => route('default.api.ec.track.download.kml', ['id' => $track->id]),
        ];
    }

    /**
     * I tre valori del blocco «Sentiero per tutti», con punteggio e classe calcolati da
     * `InclusivityScore` (solo se ci sono tutti e tre) e i due testi scritti a mano in Nova.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function inclusivity(EcTrack $track, array $stored): array
    {
        $score = InclusivityScore::for($track);

        return array_filter([
            'physical' => $track->forestas_inclusivity_physical,
            'aids' => $track->forestas_inclusivity_aids,
            'digital' => $track->forestas_inclusivity_digital,
            'score' => $score['score'] ?? null,
            'class' => $score['class'] ?? null,
            'independent_use' => $stored['inclusivity_independent_use'] ?? null,
            'assistance' => $stored['inclusivity_assistance'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
