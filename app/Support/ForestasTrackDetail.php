<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
 * Il roadbook esce com'è: diventerà un blocco del dettaglio con un pezzo successivo.
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
            'roadbook' => $stored['roadbook'] ?? null,
            'videos' => [
                ...self::remoteVideos($stored['video'] ?? []),
                ...self::files($track, SardegnaSentieriMediaSyncService::VIDEOS_COLLECTION),
            ],
            'attachments' => self::files($track, SardegnaSentieriMediaSyncService::ATTACHMENTS_COLLECTION),
            'registry_code' => $track->forestas_registry_code,
            'survey_date' => $track->forestas_survey_date?->format('Y-m-d'),
            'inclusivity' => self::inclusivity($track, $stored),
            'image_credits' => self::imageCredits($track),
            'entities' => self::entities($track),
            'nearby_tracks' => self::nearbyTracks($track),
            'downloads' => self::downloads($track),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Didascalia, autore e credits delle foto, per id del media: Drupal li mostra sotto ogni foto e
     * l'artifact di analisi chiede di esporli (la didascalia vale anche come testo alternativo).
     * Non stanno nelle foto: `feature_image` e `image_gallery` le riscrive `MediaResource` del
     * package con campi fissi, quindi il frontend li abbina per `id`. Li salva l'import di forestas
     * con il prefisso `sardegnasentieri_`; i campi vuoti non escono, e nemmeno le foto senza dati.
     *
     * @return array<string, array<string, string>>
     */
    private static function imageCredits(EcTrack $track): array
    {
        $credits = [];
        foreach ($track->getMedia() as $media) {
            $item = array_filter([
                'caption' => $media->getCustomProperty('sardegnasentieri_caption'),
                'author' => $media->getCustomProperty('sardegnasentieri_autore'),
                'credits' => $media->getCustomProperty('sardegnasentieri_credits'),
            ], fn ($value) => is_string($value) && $value !== '');
            if ($item !== []) {
                $credits[(string) $media->id] = $item;
            }
        }

        return $credits;
    }

    /**
     * Gli enti collegati, uno per ente con tutti i suoi ruoli (gestore, manutentore, rilevatore,
     * operatore, complesso forestale): in `enteables` lo stesso ente ha una riga per ruolo. Il
     * resto della scheda dell'ente lo restituisce l'API degli enti, a partire dall'id.
     *
     * @return list<array{id: int, name: array<string, string>, roles: list<string>}>
     */
    private static function entities(EcTrack $track): array
    {
        $entities = [];
        /** @var Ente $ente */
        foreach ($track->enti as $ente) {
            $id = (int) $ente->id;
            $entities[$id] ??= ['id' => $id, 'name' => $ente->getTranslations('name'), 'roles' => []];
            $role = $ente->getRelationValue('pivot')?->getAttribute('ruolo');
            if (is_string($role) && $role !== '' && ! in_array($role, $entities[$id]['roles'], true)) {
                $entities[$id]['roles'][] = $role;
            }
        }

        return array_values($entities);
    }

    /**
     * I sentieri vicini calcolati dalla geometria (`CalculateNearbyTracksJob`, entro
     * `sardegnasentieri.nearby_tracks_radius_meters`): `id`, `ref` e nome, per la mappa e i link
     * della scheda. La distanza non è salvata, quindi non esce.
     *
     * @return list<array{id: int, ref: string|null, name: array<string, string>}>
     */
    private static function nearbyTracks(EcTrack $track): array
    {
        $nearby = [];
        /** @var EcTrack $near */
        foreach ($track->relatedTracks->sortBy('id') as $near) {
            $nearby[] = [
                'id' => (int) $near->id,
                'ref' => $near->properties['ref'] ?? null,
                'name' => $near->getTranslations('name'),
            ];
        }

        return $nearby;
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
     * I video remoti (YouTube) salvati dall'import come lista di indirizzi: Drupal non dà un titolo.
     *
     * @return list<array{url: string, title: null}>
     */
    private static function remoteVideos(mixed $urls): array
    {
        $videos = [];
        foreach (is_array($urls) ? $urls : [] as $url) {
            if (is_string($url) && $url !== '') {
                $videos[] = ['url' => $url, 'title' => null];
            }
        }

        return $videos;
    }

    /**
     * I file copiati nella media library dello shard (allegati, video caricati): l'indirizzo è
     * quello dello shard, non di Drupal, che verrà spento; il titolo è quello di Drupal, `null` se
     * non c'era (Drupal mostra allora il nome del file).
     *
     * @return list<array{url: string, title: string|null}>
     */
    private static function files(EcTrack $track, string $collection): array
    {
        return $track->getMedia($collection)
            ->sortBy('order_column')
            ->map(fn (Media $media) => [
                'url' => $media->getUrl(),
                'title' => self::title($media),
            ])
            ->values()
            ->all();
    }

    private static function title(Media $media): ?string
    {
        $title = $media->getCustomProperty('sardegnasentieri_title');

        return is_string($title) && $title !== '' ? $title : null;
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
