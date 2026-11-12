<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Dto\Import\SardegnaSentieriImageManifest;
use App\Enums\TipoEnte;
use App\Jobs\Import\ResolveSardegnaSentieriRelationsJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\Source\SardegnaSentieriApiSource;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Wm\WmPackage\Nova\Fields\ImageCredits;

/**
 * Confronta ciò che l'import legge dalle API di Drupal con ciò che legge
 * dalla copia del DB, in sola lettura (oc:8900).
 *
 * È la prova che il cambio di fonte non cambia il risultato: si lancia con
 * la copia raggiungibile (in locale, il tunnel verso UAT) e deve uscire con
 * 0. Le risposte delle API si salvano su file e si riusano ai giri successivi
 * (`--refresh-api` per riscaricarle): sono l'unica parte lenta, e fra un giro
 * e l'altro cambiano le query sulla copia, non le API. Vive finché vivono le API: va rimosso, con SardegnaSentieriApiSource,
 * alla dismissione di Drupal.
 *
 * Con `--only=shard` (e senza `--only`) confronta anche i dati che le API non
 * davano e che la copia porta allo shard (oc:8932): non piu' API contro copia,
 * ma copia contro database dello shard, dopo un import completo. Copre
 * collegamenti esterni, allegati e video, didascalie, dati degli enti,
 * data di creazione dei POI e legami fra contenuti.
 */
class CompareSardegnaSentieriSourceCommand extends Command
{
    protected $signature = 'sardegnasentieri:compare-source
                            {--only= : pois, tracks, taxonomies, enti o shard}
                            {--ids= : solo questi nid, separati da virgola}
                            {--refresh-api : riscarica dalle API invece di usare le risposte salvate}';

    protected $description = 'Confronta i dati di Sardegna Sentieri fra le API di Drupal e la copia del DB';

    /**
     * Campi dei DTO che l'import legge ma non usa (oc:8641): una differenza
     * lì non cambia ciò che viene scritto.
     */
    private const IGNORED_FIELDS = ['lunghezza', 'dislivello_totale', 'durata', 'ele_min', 'ele_max'];

    /**
     * Oltre questa quota di nodi saltati il confronto non prova nulla: un
     * `changed` scritto male li renderebbe diversi tutti, e verrebbero
     * saltati invece che segnalati. Di norma è qualche nodo su settecento.
     */
    private const MAX_SKIPPED_RATIO = 0.05;

    /**
     * Campi dei DTO che le API non davano e che la copia porta con questo
     * ticket (oc:8932): nel confronto API contro copia sarebbero tutte
     * differenze, e si confrontano con lo shard (`compareShard()`).
     */
    private const SHARD_ONLY_FIELDS = ['attachmentFiles', 'uploadedVideos', 'collegamenti', 'created_at', 'punti_di_interesse', 'sentieri_collegati', 'itinerari_correlati'];

    private const TRACK_MODELS = [EcTrack::class, \Wm\WmPackage\Models\EcTrack::class];

    private const POI_MODELS = [EcPoi::class, \Wm\WmPackage\Models\EcPoi::class];

    private const ENTE_MODELS = [Ente::class];

    private const VOCABULARIES = ['tipologia_poi', 'servizi', 'tipologia_sentieri', 'tipologia_di_avvertenze'];

    /**
     * Le sole parti del nodo ente che il service legge.
     */
    private const ENTE_PATHS = [
        'title.0.value', 'body.0.value', 'field_contatti.0.value', 'field_pagina_web.0.uri',
        'field_tipo_ente.0.target_id', 'field_immagine_principale.0.url',
        'field_geolocalizzazione.0.lat', 'field_geolocalizzazione.0.lon',
    ];

    private int $differences = 0;

    /** @var array<string, true> */
    private array $enti = [];

    private string $cacheDir;

    /** @var list<string>|null */
    private ?array $ids = null;

    public function handle(SardegnaSentieriApiSource $api, SardegnaSentieriDatabaseSource $db): int
    {
        $only = $this->option('only');
        $this->cacheDir = (string) config('forestas.sardegnasentieri.compare_cache_path');
        if ($this->option('refresh-api')) {
            File::deleteDirectory($this->cacheDir);
        }
        File::ensureDirectoryExists($this->cacheDir);
        if ($this->option('ids')) {
            $this->ids = array_map('trim', explode(',', (string) $this->option('ids')));
        }

        if ($only === null || $only === 'taxonomies') {
            foreach (self::VOCABULARIES as $vocabulary) {
                $this->compareValues("taxonomy {$vocabulary}", $this->cached("taxonomy-{$vocabulary}", fn () => $api->getTaxonomy($vocabulary)), $db->getTaxonomy($vocabulary));
            }
        }

        if ($only === null || $only === 'tracks' || $only === 'enti') {
            $db->preload($this->selected($db->getTrackList()));
            $this->compareNodes('track', $this->cached('track-list', fn () => $api->getTrackList()), $db->getTrackList(),
                fn (int $id) => $this->cached("track-{$id}", fn () => $api->getTrackDetail($id)), fn (int $id) => $db->getTrackDetail($id));
        }

        if ($only === null || $only === 'pois' || $only === 'enti') {
            $db->preload($this->selected($db->getPoiList()));
            $this->compareNodes('poi', $this->cached('poi-list', fn () => $api->getPoiList()), $db->getPoiList(),
                fn (int $id) => $this->cached("poi-{$id}", fn () => $api->getPoiDetail($id)), fn (int $id) => $db->getPoiDetail($id));
        }

        if ($only === null || $only === 'enti') {
            $db->preload(array_map('intval', array_keys($this->enti)));
            $this->compareEnti($api, $db);
        }

        if ($only === null || $only === 'shard') {
            $this->compareShard($db);
        }

        $this->line($this->differences === 0
            ? 'Nessuna differenza.'
            : "{$this->differences} differenze.");

        return $this->differences === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Gli id della lista da confrontare, tenuto conto di `--ids`.
     *
     * @param  array<string, string>  $list
     * @return list<int>
     */
    private function selected(array $list): array
    {
        $ids = array_map('strval', array_keys($list));
        if ($this->ids !== null) {
            $ids = array_intersect($ids, $this->ids);
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * @param  array<string, string>  $apiList
     * @param  array<string, string>  $dbList
     */
    private function compareNodes(string $kind, array $apiList, array $dbList, \Closure $apiDetail, \Closure $dbDetail): void
    {
        $skipped = 0;
        $compared = 0;

        foreach (array_keys($apiList + $dbList) as $nid) {
            if ($this->ids !== null && ! in_array((string) $nid, $this->ids, true)) {
                continue;
            }

            if (! isset($apiList[$nid], $dbList[$nid])) {
                $this->report("{$kind} {$nid} lista", isset($apiList[$nid]) ? 'presente' : 'assente', isset($dbList[$nid]) ? 'presente' : 'assente');

                continue;
            }

            // La copia è il dump di stamattina: un nodo modificato dopo
            // differisce per una ragione legittima.
            $compared++;
            if ($apiList[$nid] !== $dbList[$nid]) {
                $skipped++;

                continue;
            }

            try {
                $apiData = $this->toArray($apiDetail((int) $nid));
                $dbData = $this->toArray($dbDetail((int) $nid));
            } catch (\Throwable $e) {
                $this->report("{$kind} {$nid} lettura", 'ok', $e->getMessage());

                continue;
            }

            $this->collectEnti($apiData);
            $apiData = $this->withoutShardOnly(Arr::except($apiData, self::IGNORED_FIELDS));
            $dbData = $this->withoutShardOnly(Arr::except($dbData, self::IGNORED_FIELDS));
            // La gerarchia di field_frubilita la dà solo la copia: le API non l'avevano (oc:8910).
            Arr::forget($dbData, 'taxonomies.fruibilita_padri');
            Arr::forget($apiData, 'taxonomies.fruibilita_padri');
            foreach (array_keys($apiData + $dbData) as $field) {
                $this->compareValues("{$kind} {$nid} {$field}", $apiData[$field] ?? null, $dbData[$field] ?? null);
            }
        }

        $this->line("{$kind}: {$skipped} nodi saltati (changed diverso)");

        if ($compared > 0 && $skipped / $compared > self::MAX_SKIPPED_RATIO) {
            $this->report("{$kind} troppi nodi saltati", 'changed uguale', "{$skipped} su {$compared} diversi");
        }
    }

    private function compareEnti(SardegnaSentieriApiSource $api, SardegnaSentieriDatabaseSource $db): void
    {
        foreach (array_keys($this->enti) as $id) {
            try {
                $apiNode = $this->cached("ente-{$id}", fn () => $api->getNodeDetail((int) $id));
                $dbNode = $db->getNodeDetail((int) $id);
            } catch (\Throwable $e) {
                $this->report("ente {$id} lettura", 'ok', $e->getMessage());

                continue;
            }

            foreach (self::ENTE_PATHS as $path) {
                $this->compareValues("ente {$id} {$path}", data_get($apiNode, $path), data_get($dbNode, $path));
            }

            $tipoEnte = data_get($apiNode, 'field_tipo_ente.0.target_id');
            if ($tipoEnte !== null && TipoEnte::fromDrupalId((int) $tipoEnte) === null) {
                $this->compareValues("termine {$tipoEnte} name",
                    data_get($this->cached("term-{$tipoEnte}", fn () => $api->getTaxonomyTerm((int) $tipoEnte)), 'name.0.value'),
                    data_get($db->getTaxonomyTerm((int) $tipoEnte), 'name.0.value'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutShardOnly(array $data): array
    {
        // La didascalia delle foto la dava solo la copia: sta nello shard.
        foreach (['immaginePrincipale', 'galleria', 'galleriaImmagini'] as $field) {
            if (is_array($data[$field] ?? null)) {
                $data[$field] = $this->withoutCaption($data[$field]);
            }
        }

        return Arr::except($data, self::SHARD_ONLY_FIELDS);
    }

    /**
     * @param  array<mixed>  $images  una immagine o una lista di immagini
     * @return array<mixed>
     */
    private function withoutCaption(array $images): array
    {
        unset($images['caption']);
        foreach ($images as $key => $value) {
            if (is_array($value)) {
                $images[$key] = $this->withoutCaption($value);
            }
        }

        return $images;
    }

    // -------------------------------------------------------------------------
    // Copia del DB Drupal contro database dello shard (oc:8932)
    // -------------------------------------------------------------------------

    private function compareShard(SardegnaSentieriDatabaseSource $db): void
    {
        $trackList = $db->getTrackList();
        $poiList = $db->getPoiList();
        $enteList = $db->getEnteList();
        $db->preload(array_map('intval', array_keys($trackList + $poiList + $enteList)));

        $tracks = $this->shardRows('ec_tracks', "properties->>'sardegnasentieri_id'", self::TRACK_MODELS);
        $pois = $this->shardRows('ec_pois', "COALESCE(properties->>'out_source_feature_id', properties->>'sardegnasentieri_id')", self::POI_MODELS);
        $enti = $this->shardRows('entes', 'sardegnasentieri_id', self::ENTE_MODELS, 'pagina_web_title, slug, drupal_created_at');

        /** @var array<string, true> $trackPoiPairs */
        $trackPoiPairs = [];
        /** @var array<string, true> $poiPoiPairs */
        $poiPoiPairs = [];
        $counts = ['related_url' => [0, 0], 'attachments' => [0, 0], 'videos' => [0, 0], 'caption' => [0, 0], 'immagini_enti' => [0, 0]];

        foreach (array_keys($trackList) as $nid) {
            try {
                $detail = $db->getTrackDetail((int) $nid);
            } catch (\Throwable $e) {
                $this->report("track {$nid} lettura", 'ok', $e->getMessage());

                continue;
            }

            foreach (array_filter([$detail->partenza, $detail->arrivo, ...$detail->poi_correlati, ...$detail->punti_di_interesse], fn ($v) => $v !== null && $v !== '') as $poiNid) {
                $trackPoiPairs[(string) $nid.':'.$poiNid] = true;
            }

            $this->compareShardNode('track', (string) $nid, $tracks, $detail->collegamenti, $detail->attachmentFiles, $detail->uploadedVideos,
                SardegnaSentieriImageManifest::fromApiTrackResponse($detail), $counts);
        }

        foreach (array_keys($poiList) as $nid) {
            try {
                $detail = $db->getPoiDetail((int) $nid);
            } catch (\Throwable $e) {
                $this->report("poi {$nid} lettura", 'ok', $e->getMessage());

                continue;
            }

            foreach ([...$detail->sentieri_collegati, ...$detail->itinerari_correlati] as $trackNid) {
                $trackPoiPairs[$trackNid.':'.$nid] = true;
            }
            // Reciproci, e senza i POI che citano se stessi (2516, 2701, 2901).
            foreach ($detail->poi_correlati as $relatedNid) {
                if ((string) $relatedNid !== (string) $nid) {
                    $poiPoiPairs[$nid.':'.$relatedNid] = true;
                    $poiPoiPairs[$relatedNid.':'.$nid] = true;
                }
            }

            $this->compareShardNode('poi', (string) $nid, $pois, $detail->collegamenti, $detail->attachmentFiles, $detail->uploadedVideos,
                SardegnaSentieriImageManifest::fromApiPoiResponse($detail), $counts);

            // La data di creazione dei POI: `properties.forestas.created_at`.
            if ($this->selectedNid((string) $nid) && isset($pois[(string) $nid])) {
                $this->compareShardValue("poi {$nid} created_at", $detail->created_at, $pois[(string) $nid]['created_at']);
            }
        }

        foreach (array_keys($enteList) as $nid) {
            $this->compareShardEnte($db, (string) $nid, $enti, $counts);
        }

        $this->compareShardLinks($tracks, $pois, $trackList, $poiList, $trackPoiPairs, $poiPoiPairs);

        foreach ($counts as $kind => [$source, $shard]) {
            $this->line("shard {$kind}: copia={$source} shard={$shard}");
        }
    }

    /**
     * I confronti per nodo uguali per percorsi e punti di interesse.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<array{uri: string, title: string}>  $links
     * @param  list<array{url: string, title: string}>  $attachments
     * @param  list<array{url: string, title: string}>  $videos
     * @param  list<array{url: string, caption: string}>  $images
     * @param  array<string, array{0: int, 1: int}>  $counts
     */
    private function compareShardNode(string $kind, string $nid, array $rows, array $links, array $attachments, array $videos, array $images, array &$counts): void
    {
        if (! $this->selectedNid($nid)) {
            return;
        }

        if (! isset($rows[$nid])) {
            $this->reportShard("{$kind} {$nid}", 'presente', 'assente');

            return;
        }

        $row = $rows[$nid];
        $this->compareShardUrls("{$kind} {$nid} related_url", array_column($links, 'uri'), $row['related_url'], $counts['related_url']);
        $this->compareShardUrls("{$kind} {$nid} attachments", array_column($attachments, 'url'), $row['media']['attachments'] ?? [], $counts['attachments']);
        $this->compareShardUrls("{$kind} {$nid} videos", array_column($videos, 'url'), $row['media']['videos'] ?? [], $counts['videos']);
        $this->compareShardCaptions("{$kind} {$nid}", $images, $row['media']['default'] ?? [], $counts['caption']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $enti
     * @param  array<string, array{0: int, 1: int}>  $counts
     */
    private function compareShardEnte(SardegnaSentieriDatabaseSource $db, string $nid, array $enti, array &$counts): void
    {
        if (! $this->selectedNid($nid)) {
            return;
        }

        try {
            $node = $db->getNodeDetail((int) $nid);
        } catch (\Throwable $e) {
            $this->report("ente {$nid} lettura", 'ok', $e->getMessage());

            return;
        }

        if (! isset($enti[$nid])) {
            $this->reportShard("ente {$nid}", 'presente', 'assente');

            return;
        }

        $row = $enti[$nid];
        $images = array_map(fn (array $image) => ['url' => (string) $image['url'], 'caption' => (string) ($image['caption'] ?? '')], $node['immagini'] ?? []);

        $this->compareShardUrls("ente {$nid} related_url", array_column($node['collegamenti'] ?? [], 'uri'), $row['related_url'], $counts['related_url']);
        $this->compareShardUrls("ente {$nid} immagini", array_column($images, 'url'), $row['media']['default'] ?? [], $counts['immagini_enti']);
        $this->compareShardCaptions("ente {$nid}", $images, $row['media']['default'] ?? [], $counts['caption']);

        $title = (string) data_get($node, 'title.0.value', '');
        $this->compareShardValue("ente {$nid} pagina_web_title", $node['pagina_web_title'] ?? null, $row['pagina_web_title']);
        $this->compareShardValue("ente {$nid} slug", $node['slug'] ?? Str::slug($title), $row['slug']);
        $this->compareShardValue("ente {$nid} drupal_created_at", $node['created'] ?? null,
            $row['drupal_created_at'] === null ? null : date('Y-m-d H:i:s', (int) strtotime((string) $row['drupal_created_at'])));
    }

    /**
     * Gli indirizzi (o i file) di Drupal contro quelli dello shard, come
     * insiemi: l'ordine e le etichette non contano qui.
     *
     * @param  list<string>  $source
     * @param  list<array{url: string, caption: string}>|list<string>  $shard
     * @param  array{0: int, 1: int}  $count
     */
    private function compareShardUrls(string $label, array $source, array $shard, array &$count): void
    {
        $shard = array_map(fn ($item) => is_array($item) ? $item['url'] : (string) $item, $shard);
        $source = array_values(array_unique(array_map('strval', $source)));
        $shard = array_values(array_unique($shard));
        $count[0] += count($source);
        $count[1] += count($shard);

        $missing = array_values(array_diff($source, $shard));
        $extra = array_values(array_diff($shard, $source));
        if ($missing !== [] || $extra !== []) {
            $this->reportShard($label, count($source).($missing === [] ? '' : ' mancano: '.implode(', ', $missing)), count($shard).($extra === [] ? '' : ' in piu: '.implode(', ', $extra)));
        }
    }

    /**
     * Le didascalie delle foto che lo shard ha gia' scaricato: una foto
     * assente non si giudica qui (non e' un dato di questo ticket).
     *
     * @param  list<array{url: string, caption: string}>  $images
     * @param  list<array{url: string, caption: string}>  $shardMedia
     * @param  array{0: int, 1: int}  $count
     */
    private function compareShardCaptions(string $label, array $images, array $shardMedia, array &$count): void
    {
        $shardCaptions = array_column($shardMedia, 'caption', 'url');

        foreach ($images as $image) {
            if ($image['caption'] === '' || ! array_key_exists($image['url'], $shardCaptions)) {
                continue;
            }
            $count[0]++;
            $shardCaption = (string) $shardCaptions[$image['url']];
            $count[1] += $shardCaption === '' ? 0 : 1;
            if ($shardCaption !== $image['caption']) {
                $this->compareShardValue("{$label} didascalia {$image['url']}", $image['caption'], $shardCaption);
            }
        }
    }

    /**
     * I legami fra contenuti: coppie percorso-POI (`ec_poi_ec_track`) e
     * POI-POI (`ec_poi_related_pois`), da Drupal contro lo shard. Le liste di
     * Drupal (scritte sul percorso e sul POI) sono unite e reciproche. Una coppia
     * con un nodo mai importato non si giudica; un legame a piu' di 10 km, misurati
     * sulla geometria, lo shard lo scarta di proposito.
     *
     * @param  array<string, array<string, mixed>>  $tracks
     * @param  array<string, array<string, mixed>>  $pois
     * @param  array<string, string>  $trackList
     * @param  array<string, string>  $poiList
     * @param  array<string, true>  $trackPoiPairs  "nid percorso:nid POI"
     * @param  array<string, true>  $poiPoiPairs  "nid POI:nid POI"
     */
    private function compareShardLinks(array $tracks, array $pois, array $trackList, array $poiList, array $trackPoiPairs, array $poiPoiPairs): void
    {
        $trackNids = array_column($tracks, 'nid', 'id');
        $poiNids = array_column($pois, 'nid', 'id');

        $shardTrackPoi = [];
        foreach (DB::table('ec_poi_ec_track')->get(['ec_track_id', 'ec_poi_id']) as $row) {
            if (isset($trackNids[$row->ec_track_id], $poiNids[$row->ec_poi_id])) {
                $shardTrackPoi[$trackNids[$row->ec_track_id].':'.$poiNids[$row->ec_poi_id]] = true;
            }
        }
        $shardPoiPoi = [];
        foreach (DB::table('ec_poi_related_pois')->get(['ec_poi_id', 'related_poi_id']) as $row) {
            if (isset($poiNids[$row->ec_poi_id], $poiNids[$row->related_poi_id])) {
                $shardPoiPoi[$poiNids[$row->ec_poi_id].':'.$poiNids[$row->related_poi_id]] = true;
            }
        }

        $expectedTrackPoi = 0;
        foreach ($trackPoiPairs as $pair => $_) {
            [$trackNid, $poiNid] = explode(':', (string) $pair);
            if (! isset($tracks[$trackNid], $pois[$poiNid])) {
                continue;
            }
            if (! $this->selectedNid($trackNid) && ! $this->selectedNid($poiNid)) {
                continue;
            }
            if (isset($shardTrackPoi[$pair])) {
                $expectedTrackPoi++;

                continue;
            }
            if ($this->isTooFar((int) $tracks[$trackNid]['id'], (int) $pois[$poiNid]['id'])) {
                continue;
            }
            $expectedTrackPoi++;
            $this->reportShard("legame track {$trackNid} poi {$poiNid}", 'presente', 'assente');
        }
        foreach ($shardTrackPoi as $pair => $_) {
            [$trackNid, $poiNid] = explode(':', (string) $pair);
            if (! isset($trackPoiPairs[$pair]) && ($this->selectedNid($trackNid) || $this->selectedNid($poiNid)) && isset($trackList[$trackNid], $poiList[$poiNid])) {
                $this->reportShard("legame track {$trackNid} poi {$poiNid}", 'assente', 'presente');
            }
        }

        $expectedPoiPoi = 0;
        foreach ($poiPoiPairs as $pair => $_) {
            [$fromNid, $toNid] = explode(':', (string) $pair);
            if (! isset($pois[$fromNid], $pois[$toNid]) || (! $this->selectedNid($fromNid) && ! $this->selectedNid($toNid))) {
                continue;
            }
            $expectedPoiPoi++;
            if (! isset($shardPoiPoi[$pair])) {
                $this->reportShard("legame poi {$fromNid} poi {$toNid}", 'presente', 'assente');
            }
        }
        foreach ($shardPoiPoi as $pair => $_) {
            [$fromNid, $toNid] = explode(':', (string) $pair);
            if (! isset($poiPoiPairs[$pair]) && isset($poiList[$fromNid], $poiList[$toNid]) && ($this->selectedNid($fromNid) || $this->selectedNid($toNid))) {
                $this->reportShard("legame poi {$fromNid} poi {$toNid}", 'assente', 'presente');
            }
        }

        $this->line("shard legami track-poi: copia={$expectedTrackPoi} shard=".count($shardTrackPoi));
        $this->line("shard legami poi-poi: copia={$expectedPoiPoi} shard=".count($shardPoiPoi));
    }

    /**
     * Vero se la coppia sta oltre la soglia del job che scrive i legami: la
     * costante e' la sua, per non divergere. Una coppia con una geometria
     * assente non e' misurabile e il job la lascia in vigore (non la scarta),
     * quindi qui si considera valida: attesa, e segnalata se manca.
     */
    private function isTooFar(int $trackId, int $poiId): bool
    {
        $distance = DB::scalar(
            'SELECT ST_Distance(t.geometry::geography, p.geometry::geography) FROM ec_tracks t, ec_pois p WHERE t.id = ? AND p.id = ?',
            [$trackId, $poiId],
        );

        return $distance !== null && (float) $distance > ResolveSardegnaSentieriRelationsJob::MAX_DISTANCE_METERS;
    }

    private function selectedNid(string $nid): bool
    {
        return $this->ids === null || in_array($nid, $this->ids, true);
    }

    /**
     * Le righe dello shard con i loro media, per nid di Drupal.
     *
     * @param  list<class-string>  $models  le classi con cui i media si agganciano al modello
     * @return array<string, array<string, mixed>>
     */
    private function shardRows(string $table, string $nidExpression, array $models, string $extraColumns = ''): array
    {
        $extra = $extraColumns === '' ? '' : ", {$extraColumns}";
        $created = $table === 'ec_pois' ? ", properties->'forestas'->>'created_at' AS created_at" : '';

        $rows = [];
        foreach (DB::select("SELECT id, {$nidExpression} AS nid, properties->'related_url' AS related_url{$created}{$extra} FROM {$table}") as $row) {
            if ($row->nid === null) {
                continue;
            }
            $related = $row->related_url === null ? [] : (json_decode((string) $row->related_url, true) ?: []);
            $rows[(string) $row->nid] = [
                'created_at' => null,
                'pagina_web_title' => null,
                'slug' => null,
                'drupal_created_at' => null,
                ...(array) $row,
                'related_url' => array_values(is_array($related) ? $related : []),
                'media' => [],
            ];
        }

        $byId = [];
        foreach ($rows as $nid => $row) {
            $byId[(int) $row['id']] = $nid;
        }

        $media = DB::table('media')->whereIn('model_type', $models)
            ->whereIn('collection_name', ['default', 'attachments', 'videos'])
            ->orderBy('order_column')
            ->get(['model_id', 'collection_name', 'custom_properties']);
        foreach ($media as $item) {
            $nid = $byId[(int) $item->model_id] ?? null;
            if ($nid === null) {
                continue;
            }
            $props = json_decode((string) $item->custom_properties, true) ?: [];
            if (($props['sardegnasentieri_import'] ?? false) !== true) {
                continue;
            }
            $rows[$nid]['media'][$item->collection_name][] = [
                'url' => (string) ($props['sardegnasentieri_source_url'] ?? ''),
                'caption' => (string) ($props[ImageCredits::CAPTION] ?? ''),
            ];
        }

        foreach ($rows as $nid => $row) {
            $rows[$nid]['nid'] = $nid;
        }

        return $rows;
    }

    private function compareShardValue(string $label, mixed $source, mixed $shard): void
    {
        if ($this->scalar($source) !== $this->scalar($shard)) {
            $this->reportShard($label, $this->scalar($source), $this->scalar($shard));
        }
    }

    private function reportShard(string $label, string $source, string $shard): void
    {
        $this->differences++;
        $this->line(sprintf('%s: copia=%s shard=%s', $label, mb_strimwidth($source, 0, 160, '…'), mb_strimwidth($shard, 0, 160, '…')));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function collectEnti(array $data): void
    {
        $ids = array_merge(
            [$data['complesso_forestale'] ?? null],
            Arr::flatten((array) ($data['ente_istituzione_societa'] ?? [])),
        );

        foreach (array_filter($ids, fn ($id) => $id !== null && $id !== '') as $id) {
            $this->enti[(string) $id] = true;
        }
    }

    private function compareValues(string $label, mixed $api, mixed $db): void
    {
        if (is_array($api) && is_array($db)) {
            foreach (array_keys($api + $db) as $key) {
                $this->compareValues("{$label}.{$key}", $api[$key] ?? null, $db[$key] ?? null);
            }

            return;
        }

        // Le API danno numeri e stringhe in modo non uniforme: si confronta
        // il valore, non il tipo.
        if ($this->scalar($api) !== $this->scalar($db)) {
            $this->report($label, $this->scalar($api), $this->scalar($db));
        }
    }

    private function scalar(mixed $value): string
    {
        // Una stringa vuota e un valore assente danno lo stesso risultato
        // nel service (le API davano `arrivo: ""` dove la copia non ha nulla,
        // sentieri 2351 e 2481).
        return match (true) {
            $value === null, $value === '' => '∅',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            is_float($value) => rtrim(rtrim(sprintf('%.9F', $value), '0'), '.'),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function report(string $label, string $api, string $db): void
    {
        $this->differences++;
        $this->line(sprintf('%s: api=%s copia=%s', $label, mb_strimwidth($api, 0, 160, '…'), mb_strimwidth($db, 0, 160, '…')));
    }

    /**
     * La risposta delle API salvata al giro precedente, o scaricata ora.
     */
    private function cached(string $key, \Closure $fetch): mixed
    {
        $file = "{$this->cacheDir}/{$key}.ser";
        if (File::exists($file)) {
            return unserialize(File::get($file));
        }

        $value = $fetch();
        File::put($file, serialize($value));

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $dto): array
    {
        return json_decode(json_encode($dto, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
