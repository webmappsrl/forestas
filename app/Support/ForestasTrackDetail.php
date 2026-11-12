<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Http\Resources\MediaResource;

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
 * Il roadbook esce com'è: diventerà un blocco del dettaglio con un pezzo successivo. Didascalia,
 * autore e credits delle foto non stanno qui ma con le foto (`feature_image`, `image_gallery`):
 * li porta il package, dalle custom properties del media (`ImageCredits`, oc:8959).
 */
class ForestasTrackDetail
{
    /**
     * Il tipo di percorso (sentiero, itinerario, cammino…) nella card dei sentieri correlati.
     */
    private const ROUTE_TYPE_TAXONOMY = 'route-type';

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
            'organizations' => self::organizations($track),
            'nearby_tracks' => self::nearbyTracks($track),
            'downloads' => self::downloads($track),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * I ruoli di `enteables.ruolo` con la chiave inglese del gruppo, nell'ordine in cui escono.
     */
    private const ORGANIZATION_ROLES = [
        'gestore' => 'manager',
        'manutentore' => 'maintainer',
        'rilevatore' => 'surveyor',
        'operatore' => 'operator',
        'complesso_forestale' => 'forest_complex',
    ];

    /**
     * Gli enti collegati, raggruppati per ruolo (gestore, manutentore, rilevatore, operatore,
     * complesso forestale): in `enteables` lo stesso ente ha una riga per ruolo, quindi compare in
     * ogni gruppo in cui ha un ruolo. Ogni ente porta id, slug e nome in tutte le lingue; il resto della
     * scheda lo dà l'API degli enti, mentre i ruoli dipendono dalla traccia e lì non ci sono (scrum
     * del 2026-11-11). La chiave è `organizations` e non `entities`: sono enti (comuni, complessi
     * forestali, istituzioni, partner, associazioni), non «entità». I gruppi vuoti non escono; un
     * ruolo non previsto esce con il nome salvato nel DB.
     *
     * @return array<string, list<array{id: int, slug: string|null, name: array<string, string>}>>
     */
    private static function organizations(EcTrack $track): array
    {
        $groups = array_fill_keys(array_values(self::ORGANIZATION_ROLES), []);
        /** @var Ente $ente */
        foreach ($track->enti->sortBy('id') as $ente) {
            $role = $ente->getRelationValue('pivot')?->getAttribute('ruolo');
            if (! is_string($role) || $role === '') {
                continue;
            }
            $key = self::ORGANIZATION_ROLES[$role] ?? $role;
            $id = (int) $ente->id;
            if (in_array($id, array_column($groups[$key] ?? [], 'id'), true)) {
                continue;
            }
            // Lo slug è l'indirizzo della scheda dell'ente nel sito (`/enti/<slug>`).
            $groups[$key][] = ['id' => $id, 'slug' => $ente->slug, 'name' => $ente->getTranslations('name')];
        }

        return array_filter($groups);
    }

    /**
     * I sentieri correlati, calcolati dalla geometria (`CalculateNearbyTracksJob`, entro
     * `sardegnasentieri.nearby_tracks_radius_meters`): sono i «Sentieri correlati» della scheda,
     * che su Drupal si inserivano a mano (scrum del 2026-11-11). Ogni elemento porta quello che
     * mostra la card del carosello nel wireframe: foto, tipo di percorso, nome e codice, tempo e
     * lunghezza. Tempo e lunghezza seguono la precedenza del dettaglio (manuale, OSM, DEM): i
     * sentieri importati non li hanno finché non gira il DEM (oc:8641), e allora sono `null`.
     *
     * @return list<array<string, mixed>>
     */
    private static function nearbyTracks(EcTrack $track): array
    {
        $nearby = [];
        /** @var EcTrack $near */
        foreach ($track->relatedTracks()->with(['media', 'taxonomyTerms.parent'])->orderBy('ec_tracks.id')->get() as $near) {
            $routeType = $near->term(self::ROUTE_TYPE_TAXONOMY);
            $photo = $near->getMedia()->first();
            $nearby[] = [
                'id' => (int) $near->id,
                'ref' => $near->properties['ref'] ?? null,
                'name' => $near->getTranslations('name'),
                'route_type' => $routeType === null ? null : [
                    'identifier' => $routeType->identifier,
                    'name' => $routeType->getTranslations('name'),
                ],
                // Come `feature_image` del dettaglio: la prima foto, con gli stessi campi.
                'feature_image' => $photo === null ? null : (new MediaResource($photo))->resolve(),
                'distance' => $near->classifyField($near, 'distance')['currentValue'],
                'duration_forward' => $near->classifyField($near, 'duration_forward')['currentValue'],
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
