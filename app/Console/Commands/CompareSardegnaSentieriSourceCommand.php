<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TipoEnte;
use App\Services\Import\Source\SardegnaSentieriApiSource;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

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
 */
class CompareSardegnaSentieriSourceCommand extends Command
{
    protected $signature = 'sardegnasentieri:compare-source
                            {--only= : pois, tracks, taxonomies o enti}
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
            $apiData = Arr::except($apiData, self::IGNORED_FIELDS);
            $dbData = Arr::except($dbData, self::IGNORED_FIELDS);
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
