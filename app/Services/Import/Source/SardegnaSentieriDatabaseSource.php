<?php

declare(strict_types=1);

namespace App\Services\Import\Source;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * La fonte dell'import: la copia SQL del DB Drupal di Sardegna Sentieri
 * (oc:8900).
 *
 * Legge il record completo di ogni nodo con DrupalRecordReader, poi ne
 * ricostruisce le stesse strutture che davano le API del modulo Drupal
 * `api_webmapp`, comprese le loro stranezze, perché l'import non cambi
 * risultato. Il resto del record è a disposizione con record(). La corrispondenza campo per campo è in
 * docs/knowledge/copia-database-drupal.md; la verifica è
 * `sardegnasentieri:compare-source`.
 *
 * Due regole valgono ovunque:
 * - nessun filtro su `status`: le API esponevano anche le bozze;
 * - la lingua originale del nodo è la riga con `default_langcode = 1`, non `it`.
 */
class SardegnaSentieriDatabaseSource implements SardegnaSentieriSource
{
    private const CONNECTION = 'sardegnasentieri';

    private const SITE_URL = 'https://www.sardegnasentieri.it';

    private const TRACK_TYPES = ['sentiero', 'itinerario'];

    /**
     * Gli enti collegati a un sentiero, con la chiave sotto cui le API li
     * mettevano in `ente_istituzione_societa`. I soggetti sono singoli, gli
     * operatori una lista.
     */
    private const ENTE_FIELDS = [
        'riferimento_operatori_guid' => true,
        'soggetto_gestore' => false,
        'soggetto_manutentore' => false,
        'soggetto_rilevatore' => false,
    ];

    /**
     * Record già letti. L'istanza vive quanto un job, quindi la memoria non
     * invecchia.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $records = [];

    public function __construct(private readonly DrupalRecordReader $reader) {}

    public function isAvailable(): bool
    {
        try {
            return $this->getTrackList() !== [] && $this->getPoiList() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function getPoiList(): array
    {
        return $this->changedList(['poi']);
    }

    public function getTrackList(): array
    {
        return $this->changedList(self::TRACK_TYPES);
    }

    public function getTrackDetail(int $id): ApiTrackResponse
    {
        $record = $this->record($id, self::TRACK_TYPES);
        $node = $this->original($record);

        $properties = [
            'id' => (string) $id,
            'created_at' => DrupalValues::dateTime((int) $node['created']),
            'updated_at' => DrupalValues::dateTime((int) $node['changed']),
            'type' => $record['bundle'],
            'name' => $this->titles($record),
            'codice' => $this->single($record, 'field_codice'),
            'codice_cai' => $this->single($record, 'field_codice_cai'),
            'description' => $this->bodyByLang($record, 'value'),
            'excerpt' => $this->bodyByLang($record, 'summary'),
            'allegati' => $this->documentUrls($record, 'field_allegati_media'),
            'gpx' => $this->documentUrls($record, 'field_gpx_media'),
            'video' => $this->videos($record),
            'immagine_principale' => $this->images($record, 'field_immagine_principale_media')[0] ?? null,
            'galleria_immagini' => $this->images($record, 'field_galleria_media'),
            'complesso_forestale' => $this->single($record, 'field_complesso_forestale', 'target_id'),
            'data_rilievo' => $this->dataRilievo($record),
            'info_utili' => $this->accumulatedTexts($record, 'field_info_utili'),
            'roadbook' => $this->accumulatedTexts($record, 'field_roadbook'),
            'partenza' => $this->single($record, 'field_partenza', 'target_id'),
            'arrivo' => $this->single($record, 'field_arrivo', 'target_id'),
            'poi_correlati' => $this->references($record, 'field_poi_correlati'),
            'ente_istituzione_societa' => $this->enti($record),
            'taxonomies' => array_filter([
                'tipologia_sentieri' => $this->references($record, 'field_tipologia_sentiero'),
                'tipologia_di_avvertenze' => $this->references($record, 'field_avvertenze_e_pericoli'),
                'categorie_fruibilita_sentieri' => $this->references($record, 'field_frubilita'),
                'stato_di_validazione' => $this->single($record, 'field_stato_di_validazione', 'target_id'),
                'zona_geografica' => $this->references($record, 'field_zona_geografica'),
            ], fn ($v) => $v !== null && $v !== []),
            'url' => $this->url($record),
        ];

        return ApiTrackResponse::fromJson([
            'type' => 'Feature',
            'properties' => $this->withoutEmpty($properties),
            'geometry' => $this->trackGeometry($record),
        ]);
    }

    public function getPoiDetail(int $id): ApiPoiResponse
    {
        $record = $this->record($id, ['poi']);
        $node = $this->original($record);
        $position = $this->items($record, 'field_posizione')[0] ?? null;

        $properties = [
            'id' => (string) $id,
            'created_at' => DrupalValues::dateTime((int) $node['created']),
            'updated_at' => DrupalValues::dateTime((int) $node['changed']),
            'name' => $this->titles($record),
            'description' => $this->bodyByLang($record, 'value'),
            'codice' => $this->single($record, 'field_codice'),
            'addr_locality' => $this->single($record, 'field_localita'),
            'collegamenti' => $this->links($record),
            'come_arrivare' => $this->accumulatedTexts($record, 'field_come_arrivare'),
            // Le API non esponevano gli allegati dei POI, anche se Drupal li ha.
            'allegati' => [],
            'video' => $this->videos($record),
            'immagine_principale' => $this->images($record, 'field_immagine_principale_media')[0] ?? null,
            'galleria' => $this->images($record, 'field_galleria_media'),
            'poi_correlati' => $this->references($record, 'field_poi_correlati'),
            'ente_istituzione_societa' => $this->enti($record),
            'taxonomies' => array_filter([
                'tipologia_poi' => $this->references($record, 'field_tipologia_poi'),
                'servizi' => $this->references($record, 'field_servizi'),
                'zona_geografica' => $this->references($record, 'field_zona_geografica'),
            ], fn ($v) => $v !== []),
            'url' => $this->url($record),
        ];

        return ApiPoiResponse::fromJson([
            'type' => 'Feature',
            'properties' => $this->withoutEmpty($properties),
            'geometry' => [
                'type' => 'Point',
                'coordinates' => $position === null ? [] : [
                    (float) $position['lon'],
                    (float) $position['lat'],
                ],
            ],
        ]);
    }

    public function getTaxonomy(string $vocabulary): array
    {
        $result = [];
        foreach ($this->reader->vocabulary($vocabulary) as $tid => $term) {
            $tid = (string) $tid;
            $result[$tid]['vid'] = $vocabulary;
            foreach ($term['translations'] as $langcode => $translation) {
                $result[$tid]['name'][$langcode] = $translation['name'];
            }

            // Come le API: l'ultimo valore trovato, in qualunque lingua.
            $geohub = array_column($this->allItems($term, 'field_geohub_identifier'), 'value');
            if ($geohub !== []) {
                $result[$tid]['geohub_identifier'] = end($geohub);
            }

            $parents = array_column($this->allItems($term, 'parent'), 'target_id');
            $parent = $parents === [] ? 0 : (int) end($parents);
            $result[$tid]['parent'] = $parent === 0 ? 'NULL' : (string) $parent;
        }

        return $result;
    }

    public function getTaxonomyWarnings(): array
    {
        return $this->getTaxonomy('tipologia_di_avvertenze');
    }

    public function getNodeDetail(int $id): array
    {
        $record = $this->record($id);
        $node = $this->original($record);
        $image = collect($this->items($record, 'field_immagine_principale'))
            ->first(fn ($item) => isset($item['entity']['uri']));
        $position = $this->items($record, 'field_geolocalizzazione')[0] ?? null;
        $body = $this->items($record, 'body')[0]['value'] ?? null;
        $web = $this->items($record, 'field_pagina_web')[0]['uri'] ?? null;
        $tipoEnte = $this->single($record, 'field_tipo_ente', 'target_id');
        $contatti = $this->single($record, 'field_contatti');
        $imageUrl = $image === null ? null : DrupalValues::fileUrl((string) $image['entity']['uri']);

        return array_filter([
            'nid' => [['value' => $id]],
            'title' => [['value' => $node['title']]],
            'body' => $body === null ? null : [['value' => $body]],
            'field_contatti' => $contatti === null ? null : [['value' => $contatti]],
            'field_pagina_web' => $web === null ? null : [['uri' => $web]],
            'field_tipo_ente' => $tipoEnte === null ? null : [['target_id' => (int) $tipoEnte]],
            'field_immagine_principale' => $imageUrl === null ? null : [['url' => $imageUrl]],
            // Drupal tiene la riga anche con lat e lon NULL: le API la omettevano.
            'field_geolocalizzazione' => ($position['lat'] ?? null) === null ? null : [[
                'lat' => (float) $position['lat'],
                'lon' => (float) $position['lon'],
            ]],
        ], fn ($v) => $v !== null);
    }

    public function getTaxonomyTerm(int $id): array
    {
        $term = $this->reader->term($id);

        if ($term === null) {
            throw new \RuntimeException("Termine {$id} non trovato nella copia del DB Drupal");
        }

        return ['tid' => [['value' => $id]], 'name' => [['value' => $this->original($term)['name']]]];
    }

    /**
     * Legge in anticipo i record di molti nodi, a blocchi: per chi li legge
     * tutti in fila (il confronto con le API), dove una query per tabella di
     * campo per ogni nodo costerebbe ore attraverso il tunnel.
     *
     * @param  list<int>  $ids
     */
    public function preload(array $ids, int $chunk = 100): void
    {
        foreach (array_chunk(array_values(array_diff($ids, array_keys($this->records))), $chunk) as $block) {
            $this->records += $this->reader->nodes($block);
        }
    }

    /**
     * Il record completo del nodo, con anche i dati che l'import oggi non
     * usa: vedi DrupalRecordReader.
     *
     * @param  list<string>|null  $types
     * @return array<string, mixed>
     */
    public function record(int $id, ?array $types = null): array
    {
        $record = $this->records[$id] ??= $this->reader->node($id);

        if ($types !== null && ! in_array($record['bundle'], $types, true)) {
            throw new \RuntimeException("Nodo {$id} non trovato nella copia del DB Drupal");
        }

        return $record;
    }

    // -------------------------------------------------------------------------

    private function db(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * @param  list<string>  $types
     * @return array<string, string>
     */
    private function changedList(array $types): array
    {
        return $this->db()->table('node_field_data')
            ->whereIn('type', $types)->where('default_langcode', 1)
            ->orderBy('nid')
            ->pluck('changed', 'nid')
            ->mapWithKeys(fn ($changed, $nid) => [(string) $nid => DrupalValues::dateTime((int) $changed)])
            ->all();
    }

    /**
     * La riga della traduzione originale (`default_langcode = 1`).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function original(array $record): array
    {
        return $record['translations'][$record['langcode']];
    }

    /**
     * I valori di un campo in una lingua, la originale se non indicata, in
     * ordine di delta.
     *
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function items(array $record, string $field, ?string $langcode = null): array
    {
        return array_values($record['fields'][$field][$langcode ?? $record['langcode']] ?? []);
    }

    /**
     * I valori di un campo in tutte le lingue, in ordine di delta e poi di
     * lingua: l'ordine in cui MySQL li dava alle query senza filtro di lingua.
     *
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function allItems(array $record, string $field): array
    {
        $rows = [];
        foreach ($record['fields'][$field] ?? [] as $langcode => $items) {
            foreach ($items as $delta => $item) {
                $rows[] = [$delta, (string) $langcode, $item];
            }
        }
        usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($rows, 2);
    }

    /**
     * Le lingue come le esponevano le API, nell'ordine in cui le
     * percorrevano: chiave della risposta => langcode della traduzione.
     *
     * La chiave `it` porta sempre la traduzione originale, anche quando è
     * `en` o `und`; le altre traduzioni compaiono con la propria lingua,
     * tranne l'italiana di un nodo nato in un'altra lingua, che le API
     * ignoravano (sentiero 3636: `name.it` è il titolo inglese).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function languages(array $record): array
    {
        $keys = ['it' => $record['langcode']];
        foreach (array_keys($record['translations']) as $langcode) {
            $langcode = (string) $langcode;
            if (! in_array($langcode, ['it', 'und'], true)) {
                $keys[$langcode] = $langcode;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function titles(array $record): array
    {
        return $this->byLanguage($record, fn (string $langcode) => $record['translations'][$langcode]['title'] ?? null) ?? [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>|null
     */
    private function bodyByLang(array $record, string $column): ?array
    {
        return $this->byLanguage($record, function (string $langcode) use ($record, $column) {
            $items = $this->items($record, 'body', $langcode);

            return $items === [] ? null : end($items)[$column];
        });
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>|null
     */
    private function byLanguage(array $record, \Closure $value): ?array
    {
        $result = [];
        foreach ($this->languages($record) as $key => $langcode) {
            $v = $value($langcode);
            if ($v !== null && $v !== '') {
                $result[$key] = (string) $v;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * Campi di testo multipli e tradotti (info utili, roadbook, come
     * arrivare). Le API accumulavano le lingue senza azzerare la lista: la
     * seconda lingua riporta i valori della prima seguiti dai propri
     * (sentiero 164: `roadbook.en` = 5 voci italiane + 5 inglesi). Si replica.
     *
     * Una lingua senza valori propri riporta comunque quelli accumulati
     * (sentiero 1266: `roadbook.en` con il solo testo italiano).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, list<string>>|null
     */
    private function accumulatedTexts(array $record, string $field): ?array
    {
        $result = [];
        $accumulated = [];
        foreach ($this->languages($record) as $key => $langcode) {
            foreach ($this->items($record, $field, $langcode) as $item) {
                $accumulated[] = (string) $item['value'];
            }
            if ($accumulated !== []) {
                $result[$key] = $accumulated;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * Il primo valore di un campo nella lingua originale del nodo.
     *
     * @param  array<string, mixed>  $record
     */
    private function single(array $record, string $field, string $column = 'value'): ?string
    {
        $value = $this->items($record, $field)[0][$column] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Riferimenti multipli (nodi o termini), in ordine di delta, della
     * lingua originale: negli itinerari zona geografica e operatori sono
     * tradotti, e le API leggevano solo l'originale (itinerario 1105).
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function references(array $record, string $field): array
    {
        return array_map(fn ($item) => (string) $item['target_id'], $this->items($record, $field));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string|list<string>>|null
     */
    private function enti(array $record): ?array
    {
        $result = [];
        foreach (self::ENTE_FIELDS as $field => $multiple) {
            $values = $this->references($record, "field_{$field}");
            if ($values !== []) {
                $result[$field] = $multiple ? $values : $values[0];
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * I link appiattiti come facevano le API: uri e titolo, uno dopo l'altro,
     * con il titolo spezzato su «, » e gli spazi lasciati come sono. Le API
     * li leggevano in tutte le lingue.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function links(array $record): array
    {
        $result = [];
        foreach ($this->allItems($record, 'field_collegamenti') as $item) {
            $titleParts = explode(', ', (string) $item['title']);

            foreach ([$item['uri'], ...$titleParts] as $value) {
                if ($value !== null && $value !== '') {
                    $result[] = (string) $value;
                }
            }
        }

        return $result;
    }

    /**
     * Media di tipo documento (GPX, allegati) → URL dei file, nella lingua
     * originale del nodo: le API non esponevano le traduzioni dei media.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function documentUrls(array $record, string $field): array
    {
        $urls = [];
        foreach ($this->mediaItems($record, $field, 'field_media_document') as [$media, $file]) {
            $url = DrupalValues::fileUrl((string) $file['entity']['uri']);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{url: string, autore: string, credits: string}>
     */
    private function images(array $record, string $field): array
    {
        $images = [];
        foreach ($this->mediaItems($record, $field, 'field_media_image') as [$media, $file]) {
            $url = DrupalValues::fileUrl((string) $file['entity']['uri']);
            if ($url !== null) {
                $images[] = [
                    'url' => $url,
                    'autore' => (string) ($this->items($media, 'field_autore')[0]['value'] ?? ''),
                    'credits' => (string) ($this->items($media, 'field_credits')[0]['value'] ?? ''),
                ];
            }
        }

        return $images;
    }

    /**
     * I file dei media richiamati da un campo, nella lingua di ogni media.
     * Un media o un file che mancano nella copia si saltano, come facevano
     * le join delle API.
     *
     * @param  array<string, mixed>  $record
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function mediaItems(array $record, string $field, string $fileField): array
    {
        $result = [];
        foreach ($this->items($record, $field) as $item) {
            $media = $item['entity'] ?? null;
            if ($media === null) {
                continue;
            }
            foreach ($this->items($media, $fileField) as $file) {
                if (isset($file['entity']['uri'])) {
                    $result[] = [$media, $file];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function videos(array $record): array
    {
        $videos = [];
        foreach ($this->items($record, 'field_video_media') as $item) {
            $media = $item['entity'] ?? null;
            foreach ($media === null ? [] : $this->items($media, 'field_media_oembed_video') as $video) {
                $videos[] = (string) $video['value'];
            }
        }

        return $videos;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function dataRilievo(array $record): ?string
    {
        $value = $this->single($record, 'field_data_rilievo');

        return $value === null ? null : DrupalValues::dateTime((int) $value);
    }

    /**
     * Il GeoJSON del percorso, usato dall'import solo se manca il GPX.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function trackGeometry(array $record): ?array
    {
        $geojson = $record['fields']['field_percorso'][$record['langcode']][0]['value'] ?? null;

        $decoded = is_string($geojson) ? json_decode($geojson, true) : null;

        // Il campo salva una FeatureCollection, le API ne davano la geometria.
        if (is_array($decoded) && ($decoded['type'] ?? null) === 'FeatureCollection') {
            $decoded = $decoded['features'][0]['geometry'] ?? null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function url(array $record): string
    {
        // L'alias italiano, come il sito: anche per i nodi nati in inglese
        // (sentiero 3661). Se manca, quello della lingua originale; a parità,
        // il più recente.
        $alias = collect($record['aliases'])
            ->filter(fn ($a) => (int) $a['status'] === 1 && in_array($a['langcode'], ['it', $record['langcode']], true))
            ->sortBy([fn ($a, $b) => ($b['langcode'] === 'it') <=> ($a['langcode'] === 'it'), fn ($a, $b) => $b['id'] <=> $a['id']])
            ->first();

        $path = $alias['alias'] ?? "/node/{$record['id']}";

        // Le API davano l'alias codificato segmento per segmento
        // (`san-nicol%C3%B2-…`, `pantaleo-%E2%80%93-…`).
        return self::SITE_URL.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Le API omettevano le chiavi vuote.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $properties): array
    {
        return array_filter($properties, fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}
