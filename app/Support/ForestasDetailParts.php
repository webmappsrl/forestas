<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcTrack;
use App\Models\Ente;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Wm\WmPackage\Http\Resources\MediaResource;

/**
 * Le parti comuni del dettaglio di tracce e POI di Forestas (oc:8959): video, allegati, enti per
 * ruolo e card di un percorso. Le usano `ForestasTrackDetail` e `ForestasPoiDetail`, così la stessa
 * voce ha la stessa forma nei due dettagli.
 */
final class ForestasDetailParts
{
    /**
     * Le relazioni che la card di un percorso legge: vanno caricate insieme ai percorsi, una query
     * per relazione e non una per percorso.
     */
    public const TRACK_CARD_RELATIONS = ['media', 'taxonomyTerms.parent'];

    /**
     * Il tipo di percorso (sentiero, itinerario, cammino…) nella card.
     */
    private const ROUTE_TYPE_TAXONOMY = 'route-type';

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
     * I video remoti (YouTube) salvati dall'import come lista di indirizzi: Drupal non dà un titolo.
     *
     * @return list<array{url: string, title: null}>
     */
    public static function remoteVideos(mixed $urls): array
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
    public static function files(HasMedia $model, string $collection): array
    {
        return $model->getMedia($collection)
            ->sortBy('order_column')
            ->map(fn (Media $media) => [
                'url' => $media->getUrl(),
                'title' => self::title($media),
            ])
            ->values()
            ->all();
    }

    /**
     * Gli enti collegati, raggruppati per ruolo (gestore, manutentore, rilevatore, operatore,
     * complesso forestale): in `enteables` lo stesso ente ha una riga per ruolo, quindi compare in
     * ogni gruppo in cui ha un ruolo. Ogni ente porta id, slug (l'indirizzo della scheda,
     * `/enti/<slug>`) e nome in tutte le lingue; il resto della scheda lo dà l'API degli enti,
     * mentre i ruoli dipendono dal contenuto e lì non ci sono. La chiave è `organizations` e non
     * `entities`: sono enti (comuni, complessi forestali, istituzioni, partner, associazioni), non
     * «entità». I gruppi vuoti non escono; un ruolo non previsto esce con il nome salvato nel DB.
     *
     * @param  iterable<mixed>  $entes  gli enti (`Ente`) con il pivot `ruolo`
     * @return array<string, list<array{id: int, slug: string|null, name: array<string, string>}>>
     */
    public static function organizations(iterable $entes): array
    {
        $groups = array_fill_keys(array_values(self::ORGANIZATION_ROLES), []);
        $sorted = collect($entes)->sortBy('id');
        /** @var Ente $ente */
        foreach ($sorted as $ente) {
            $role = $ente->getRelationValue('pivot')?->getAttribute('ruolo');
            if (! is_string($role) || $role === '') {
                continue;
            }
            $key = self::ORGANIZATION_ROLES[$role] ?? $role;
            $id = (int) $ente->id;
            if (in_array($id, array_column($groups[$key] ?? [], 'id'), true)) {
                continue;
            }
            $groups[$key][] = ['id' => $id, 'slug' => $ente->slug, 'name' => $ente->getTranslations('name')];
        }

        return array_filter($groups);
    }

    /**
     * La card di un percorso, come la mostra il carosello dei correlati nel wireframe: foto, tipo di
     * percorso, nome e codice, tempo e lunghezza. Tempo e lunghezza seguono la precedenza del
     * dettaglio (manuale, OSM, DEM): i sentieri importati non li hanno finché non gira il DEM
     * (oc:8641), e allora sono `null`. Il percorso va caricato con `TRACK_CARD_RELATIONS`.
     *
     * @return array<string, mixed>
     */
    public static function trackCard(EcTrack $track): array
    {
        $routeType = $track->term(self::ROUTE_TYPE_TAXONOMY);
        $photo = $track->getMedia()->first();

        return [
            'id' => (int) $track->id,
            'ref' => $track->properties['ref'] ?? null,
            'name' => $track->getTranslations('name'),
            'route_type' => $routeType === null ? null : [
                'identifier' => $routeType->identifier,
                'name' => $routeType->getTranslations('name'),
            ],
            // Come `feature_image` del dettaglio: la prima foto, con gli stessi campi.
            'feature_image' => $photo === null ? null : (new MediaResource($photo))->resolve(),
            'distance' => $track->classifyField($track, 'distance')['currentValue'],
            'duration_forward' => $track->classifyField($track, 'duration_forward')['currentValue'],
        ];
    }

    private static function title(Media $media): ?string
    {
        $title = $media->getCustomProperty('sardegnasentieri_title');

        return is_string($title) && $title !== '' ? $title : null;
    }
}
