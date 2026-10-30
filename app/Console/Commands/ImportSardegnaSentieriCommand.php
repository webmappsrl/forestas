<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Import\ImportRegistroCatastaleJob;
use App\Jobs\Import\ImportSardegnaSentieriEnteJob;
use App\Jobs\Import\ImportSardegnaSentieriPoiJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Models\Ente;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\Source\DrupalDumpLock;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

class ImportSardegnaSentieriCommand extends Command
{
    /**
     * Quota di job falliti oltre la quale le anomalie non si ricalcolano.
     */
    private const NORMALIZE_FAILURE_THRESHOLD = 0.02;

    /**
     * Oltre quest'età il lock della copia è di una ricarica fallita (oc:8900).
     */
    private const STALE_LOCK_HOURS = 2;

    /** Tipi morph con cui tracce e POI compaiono nelle pivot: nome del model del progetto e del package. */
    private const TRACK_AND_POI_MORPH_TYPES = [
        'App\\Models\\EcTrack',
        'Wm\\WmPackage\\Models\\EcTrack',
        'App\\Models\\EcPoi',
        'Wm\\WmPackage\\Models\\EcPoi',
    ];

    /**
     * Se le anomalie vadano ricalcolate dato l'esito del batch di import.
     *
     * Sopra la soglia il ricalcolo non parte: le anomalie sono relazioni fra
     * tracciati, e su un archivio gravemente incompleto non ne escono di meno,
     * ne escono di sbagliate. Sotto, parte comunque: qualche timeout della
     * sorgente e' la norma sotto il carico di un reset, e rinunciare per
     * quello lascerebbe la lista vuota — cioe' il difetto che oc:8607
     * corregge (oc:8607).
     */
    public static function shouldNormalize(int $failedJobs, int $totalJobs): bool
    {
        if ($failedJobs <= 0) {
            return true;
        }

        return $failedJobs / max($totalJobs, 1) <= self::NORMALIZE_FAILURE_THRESHOLD;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sardegnasentieri:import
                            {--force : Reimport all ignoring updated_at}
                            {--only= : Import only pois or tracks}
                            {--reset : Truncate all imported data before importing (implies --force)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import POIs and Tracks from Sardegna Sentieri (copia del DB Drupal)';

    /**
     * Execute the console command.
     */
    public function handle(
        SardegnaSentieriSource $source,
        SardegnaSentieriImportService $importService
    ): int {
        $runId = strtoupper(Str::random(8));

        // Prima di tutto, anche del `--reset`: una copia a metà ricarica
        // sembra una fonte con pochi nodi (oc:8900).
        if (DrupalDumpLock::isHeld()) {
            $since = DrupalDumpLock::heldSince()?->format('Y-m-d H:i:s') ?? '?';

            // Una ricarica dura minuti: un lock più vecchio è di una ricarica
            // fallita, e l'import resterebbe fermo per sempre in silenzio.
            if (DrupalDumpLock::heldSince()?->lt(now()->subHours(self::STALE_LOCK_HOURS))) {
                $this->error("Copia del DB Drupal bloccata dal {$since} (ricarica fallita?): import saltato. Vedi docs/howto/copia-database-drupal.md.");
                Log::channel('import')->error("[sardegnasentieri:{$runId}] Copia del DB Drupal bloccata dal {$since} (ricarica fallita?): import saltato.");

                return self::FAILURE;
            }

            $this->warn("Copia del DB Drupal in ricarica dal {$since}: import saltato.");
            Log::channel('import')->warning("[sardegnasentieri:{$runId}] Copia del DB Drupal in ricarica dal {$since}: import saltato.");

            return self::SUCCESS;
        }

        if (! $this->ensurePrerequisites()) {
            Log::channel('import')->error("[sardegnasentieri:{$runId}] Prerequisites not met, import aborted.");

            return self::FAILURE;
        }

        // Anche nell'import orario: le liste di una copia vuota farebbero
        // marcare ogni record come sparito alla fonte (oc:8900).
        if (! $this->sourceIsReachable($source, $runId)) {
            return self::FAILURE;
        }

        if ($this->option('reset')) {
            // Il backup PRIMA del troncamento, non dopo: `--reset` cancella e
            // solo allora scarica, quindi ogni imprevisto dopo la
            // cancellazione lascia la piattaforma vuota.

            if (! $this->backupBeforeReset($runId)) {
                return self::FAILURE;
            }

            $this->info('Resetting all imported data...');
            $this->resetData();
            $this->info('Reset completed.');
        }

        $this->info('Importing taxonomies...');
        $importService->importAll();
        $this->info('Taxonomies imported.');

        // Gli enti sempre, anche con `--only`: i job di POI e sentieri
        // collegano solo enti gia' importati (oc:8513).
        [$entiDispatched, $entiRemoved, $enteJobs] = $this->importEnti($source);

        $only = $this->option('only');
        $poisDispatched = [];
        $tracksDispatched = [];
        $poisRemoved = 0;
        $tracksRemoved = 0;
        $jobs = [];

        // Import POIs
        if (! $only || $only === 'pois') {
            [$poisDispatched, $poisRemoved, $poiJobs] = $this->importPois($source);
            $jobs = [...$jobs, ...$poiJobs];
        }

        // Import Tracks
        if (! $only || $only === 'tracks') {
            [$tracksDispatched, $tracksRemoved, $trackJobs] = $this->importTracks($source);
            $jobs = [...$jobs, ...$trackJobs];
        }

        if ($jobs === []) {
            $this->info('Nessun job da importare.');

            if ($enteJobs !== []) {
                $this->entiBatch($enteJobs)->dispatch();
            }

            // Nell'import orario (niente `--reset`, niente `--only`) il
            // registro catastale si riallinea a ogni giro, anche quando da
            // Sardegna Sentieri non arriva nulla di nuovo: il foglio Google
            // cambia per conto suo, e senza job non ci sarebbe un batch alla
            // cui fine agganciarlo (oc:8539). Con `--reset` resta tutto come
            // prima: un archivio troncato e non ripopolato non va specchiato.
            if (! $this->option('reset') && $only === null) {
                ImportRegistroCatastaleJob::dispatch();

                Log::channel('import')->info("[sardegnasentieri:{$runId}] Nessun job da importare, registro catastale accodato.");
            }
        } elseif (! $this->option('reset')) {
            // Import incrementale: nulla e' stato troncato, quindi nessun
            // normalize. Il registro catastale pero' va comunque
            // riallineato a fine batch (oc:8539), quindi i job restano
            // raggruppati come nel ramo `--reset`. `withNormalize` qui
            // significa solo «non e' un `--only` parziale»: il normalize
            // vero e proprio resta escluso dal ramo `! $reset` piu' sotto.
            $this->dispatchImportBatch($jobs, $runId, withNormalize: $only === null, reset: false, enteJobs: $enteJobs);
        } else {
            // Con `--only=pois` i tracciati sono stati troncati e non
            // reimportati: l'archivio e' vuoto per volonta' di chi ha lanciato
            // il comando, non per un guasto, e ricalcolare le anomalie li'
            // dentro scriverebbe «catasto vuoto» come stato legittimo.
            $this->dispatchImportBatch($jobs, $runId, withNormalize: $only !== 'pois', reset: true, enteJobs: $enteJobs);
        }

        $this->info('Import completed.');

        Log::channel('import')->info("[sardegnasentieri:{$runId}] Run completed.", [
            'enti_dispatched' => count($entiDispatched),
            'enti_removed' => $entiRemoved,
            'pois_dispatched' => count($poisDispatched),
            'poi_ids' => $poisDispatched,
            'pois_removed' => $poisRemoved,
            'tracks_dispatched' => count($tracksDispatched),
            'track_ids' => $tracksDispatched,
            'tracks_removed' => $tracksRemoved,
        ]);

        return self::SUCCESS;
    }

    /**
     * Dopo un `--reset` le anomalie del Catasto sono state portate via dal
     * troncamento, e a ricalcolarle e' `trail-registry-normalize`. Il momento
     * giusto per chiamarlo e' quando i job hanno finito di scrivere: il
     * comando ritorna molto prima di loro, e un normalize lanciato allora
     * leggerebbe un archivio ancora vuoto — che e' il difetto corretto da
     * oc:8607.
     *
     * Qualche job che cade e' la norma — la sorgente va in timeout sotto il
     * carico di un reset — quindi il ricalcolo tollera una quota di
     * fallimenti e si ferma solo oltre quella.
     *
     * Il registro catastale (oc:8539) si riallinea nello stesso punto e per
     * lo stesso motivo delle anomalie: legge lo stato dei tracciati, quindi
     * deve partire a batch concluso, mai alla fine del comando. Con
     * `--reset` parte solo se il normalize e' andato a buon fine — un
     * archivio troncato e ancora incompleto produrrebbe anomalie del
     * registro false; nell'import incrementale (`$reset` false) invece parte
     * sempre, perche' li' non e' stato troncato nulla da ricalcolare prima.
     * Con `--only` (catasto parziale, `$withNormalize` false) non parte ne'
     * l'uno ne' l'altro.
     *
     * Le aree catastali (oc:8880) invece si ricalcolano sempre, prima di ogni
     * guardia: l'import salva con `saveQuietly()`, quindi nessun observer
     * scrive `properties.taxonomy_where`, e dopo un reset le tracce
     * resterebbero senza aree. Il sync e' quello dell'action Nova
     * `SyncEcTaxonomyWhereAction`, seguito in catena dal reindex
     * (`RegenerateTaxonomyWhereOutputsJob`), come fa `wm:resync-taxonomy-where`.
     *
     * Con enti da importare il batch di POI e sentieri parte in catena dopo
     * il loro: dopo un `--reset` un sentiero il cui gestore non e' ancora
     * importato perderebbe il collegamento, e al giro dopo, non cambiato,
     * non verrebbe riletto (oc:8513).
     *
     * @param  list<ImportSardegnaSentieriPoiJob|ImportSardegnaSentieriTrackJob>  $jobs
     * @param  list<ImportSardegnaSentieriEnteJob>  $enteJobs
     */
    private function dispatchImportBatch(array $jobs, string $runId, bool $withNormalize, bool $reset, array $enteJobs = []): void
    {
        // `Bus::batch()` riassegna la coda a ogni job che raggruppa: quella
        // dichiarata nel loro costruttore viene sovrascritta con `null`, cioe'
        // `default`, e l'isolamento della coda di import sparirebbe proprio
        // nel caso — il reset — per cui serve (oc:8607).
        $batch = Bus::batch($jobs)
            ->name('sardegnasentieri import')
            ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId, $withNormalize, $reset) {
                // Prima delle guardie: il sync ricalcola le aree dalla
                // geometria e si puo' ripetere senza danni, anche su un
                // archivio incompleto — le guardie servono ad anomalie e
                // registro. Sulla coda dell'import, perche' su `default`
                // finirebbe dietro le conversioni immagine (oc:8607).
                // Il ricalcolo dei layer sta fra sync e reindex: scrive
                // `properties.layers` in modo sincrono, e il reindex deve
                // leggere le tracce gia' aggiornate (oc:8827).
                Bus::chain([
                    new SyncTaxonomyWhereJob,
                    new RecalculateSardegnaSentieriLayersJob,
                    new RegenerateTaxonomyWhereOutputsJob(SardegnaSentieriImportService::IMPORT_APP_ID),
                ])->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)->dispatch();

                Log::channel('import')->info("[sardegnasentieri:{$runId}] Sync aree catastali, layer e reindex accodati.");

                if (! $withNormalize) {
                    Log::channel('import')->info("[sardegnasentieri:{$runId}] Import parziale (--only): anomalie e registro catastale non aggiornati.");

                    return;
                }

                if (! $reset) {
                    // Incrementale: nulla e' stato troncato, non c'e' niente
                    // da ricalcolare, ma il registro catastale va comunque
                    // riallineato.
                    ImportRegistroCatastaleJob::dispatch();

                    Log::channel('import')->info("[sardegnasentieri:{$runId}] Import incrementale completato, registro catastale accodato.");

                    return;
                }

                $failed = $batch->failedJobs;
                $total = max($batch->totalJobs, 1);

                if (! self::shouldNormalize($failed, $total)) {
                    Log::channel('import')->error(
                        "[sardegnasentieri:{$runId}] Job falliti {$failed}/{$total}: oltre la soglia, anomalie e registro catastale NON aggiornati."
                    );

                    return;
                }

                Artisan::call('wm-package:trail-registry-normalize', ['--force' => true]);

                Log::channel('import')->info(
                    "[sardegnasentieri:{$runId}] Anomalie ricalcolate (job falliti: {$failed}/{$total})."
                );

                ImportRegistroCatastaleJob::dispatch();
            });

        if ($enteJobs === []) {
            $batch->dispatch();
        } else {
            Bus::chain([$this->entiBatch($enteJobs), $batch])
                ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)
                ->dispatch();
        }

        $this->info('Dispatched '.count($jobs).' import jobs in un batch.');
    }

    /**
     * Il batch degli enti, sulla coda dell'import come quello di POI e
     * sentieri (oc:8607). I fallimenti non fermano la catena: un ente
     * mancante costa solo il suo collegamento.
     *
     * @param  list<ImportSardegnaSentieriEnteJob>  $enteJobs
     */
    private function entiBatch(array $enteJobs): PendingBatch
    {
        $this->info('Dispatched '.count($enteJobs).' ente jobs in un batch.');

        return Bus::batch($enteJobs)
            ->name('sardegnasentieri enti')
            ->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)
            ->allowFailures();
    }

    /**
     * Gli enti nuovi o cambiati, come per POI e sentieri: il confronto e' sul
     * `changed` di Drupal salvato in `properties.forestas.updated_at`
     * (oc:8513). Un ente marcato sparito e tornato in Drupal si riaccoda
     * anche con lo stesso `changed`, o resterebbe marcato per sempre.
     *
     * @return array{0: list<int>, 1: int, 2: list<ImportSardegnaSentieriEnteJob>}
     */
    private function importEnti(SardegnaSentieriSource $source): array
    {
        $this->info('Fetching ente list...');
        $enteList = $source->getEnteList();
        $this->info('Found '.count($enteList).' enti.');

        $force = $this->option('force') || $this->option('reset');
        $local = Ente::query()->get(['sardegnasentieri_id', 'properties'])
            ->mapWithKeys(fn (Ente $ente) => [(string) $ente->sardegnasentieri_id => $ente->properties['forestas'] ?? []]);

        $dispatched = [];
        $jobs = [];
        foreach ($enteList as $id => $changed) {
            $saved = $local[(string) $id] ?? null;
            $unchanged = $saved !== null
                && ($saved['updated_at'] ?? null) === $changed
                && ($saved['deleted_from_source'] ?? false) !== true;

            if (! $force && $unchanged) {
                continue;
            }

            $jobs[] = new ImportSardegnaSentieriEnteJob((int) $id, $changed);
            $dispatched[] = (int) $id;
        }

        $this->info('Prepared '.count($dispatched).' ente import jobs.');

        $removed = $this->markRemovedEnti(array_map('strval', array_keys($enteList)));

        return [$dispatched, $removed, $jobs];
    }

    /**
     * Gli enti non piu' in Drupal si marcano, non si cancellano, come POI e
     * sentieri (oc:8513).
     *
     * @param  list<string>  $sourceIds
     */
    private function markRemovedEnti(array $sourceIds): int
    {
        // Una lista vuota con sentieri e POI presenti (il controllo iniziale li
        // ha gia' chiesti) e' una copia a meta' o una query rotta, non tutti
        // gli enti spariti insieme: `whereNotIn` con [] li marcherebbe tutti.
        if ($sourceIds === []) {
            $this->warn('Lista degli enti vuota: nessun ente marcato come sparito.');

            return 0;
        }

        $removed = Ente::query()
            ->whereNotIn('sardegnasentieri_id', $sourceIds)
            ->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'")
            ->get();

        foreach ($removed as $ente) {
            $properties = is_array($ente->properties) ? $ente->properties : [];
            $properties['forestas']['deleted_from_source'] = true;
            $ente->properties = $properties;
            $ente->saveQuietly();
        }

        if ($removed->count() > 0) {
            $this->warn("Marked {$removed->count()} enti as deleted_from_source.");
        }

        return $removed->count();
    }

    /**
     * Import POIs from API
     *
     * I job non partono qui: li raccoglie `handle()`, che dopo un `--reset`
     * deve poterli raggruppare in un batch (oc:8607).
     *
     * @return array{0: list<int>, 1: int, 2: list<ImportSardegnaSentieriPoiJob>}
     */
    private function importPois(SardegnaSentieriSource $source): array
    {
        $this->info('Fetching POI list...');
        $poiList = $source->getPoiList();
        $this->info('Found '.count($poiList).' POIs.');

        $force = $this->option('force') || $this->option('reset');
        $apiIds = array_map('strval', array_keys($poiList));
        $appId = $this->getAppIdForSardegnaSentieri();

        $candidateIds = [];
        foreach ($poiList as $id => $apiTimestamp) {
            if (! $force && $appId !== null) {
                $existing = EcPoi::query()
                    ->where('app_id', $appId)
                    ->whereRaw(
                        "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                        [(string) $id, (string) $id]
                    )
                    ->selectRaw("properties->'forestas'->>'updated_at' as forestas_updated_at, properties->'forestas'->>'enti_mancanti' as enti_mancanti")
                    ->toBase()
                    ->first();

                // Un nodo che ha saltato un ente mancante si rilegge anche
                // con lo stesso changed (oc:8513).
                if ($existing !== null && $existing->forestas_updated_at === $apiTimestamp && $existing->enti_mancanti !== 'true') {
                    continue;
                }
            }

            $candidateIds[] = (int) $id;
        }

        $dispatched = [];
        $jobs = [];
        foreach ($this->sortPoiDispatchOrder($candidateIds, $poiList) as $id) {
            $jobs[] = new ImportSardegnaSentieriPoiJob($id);
            $dispatched[] = $id;
        }

        $this->info('Prepared '.count($dispatched).' POI import jobs.');

        $removed = $this->markRemovedPois($apiIds);

        return [$dispatched, $removed, $jobs];
    }

    /**
     * Mark POIs that are no longer in the API source as deleted_from_source.
     *
     * @param  string[]  $apiIds
     */
    private function markRemovedPois(array $apiIds): int
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null) {
            return 0;
        }

        $removed = EcPoi::where('app_id', $appId)
            ->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'")
            ->get()
            ->filter(function (EcPoi $poi) use ($apiIds): bool {
                $sourceId = $poi->properties['out_source_feature_id']
                    ?? $poi->properties['sardegnasentieri_id']
                    ?? null;

                return $sourceId !== null && ! in_array((string) $sourceId, $apiIds, true);
            });

        foreach ($removed as $poi) {
            $properties = is_array($poi->properties) ? $poi->properties : [];
            $properties['forestas']['deleted_from_source'] = true;
            $poi->properties = $properties;
            $poi->saveQuietly();
        }

        if ($removed->count() > 0) {
            $this->warn("Marked {$removed->count()} POIs as deleted_from_source.");
        }

        return $removed->count();
    }

    /**
     * Import Tracks from API
     *
     * I job non partono qui: li raccoglie `handle()`, che dopo un `--reset`
     * deve poterli raggruppare in un batch (oc:8607).
     *
     * @return array{0: list<int>, 1: int, 2: list<ImportSardegnaSentieriTrackJob>}
     */
    private function importTracks(SardegnaSentieriSource $source): array
    {
        $this->info('Fetching track list...');
        $trackList = $source->getTrackList();
        $this->info('Found '.count($trackList).' tracks.');

        $force = $this->option('force') || $this->option('reset');
        $apiIds = array_map('strval', array_keys($trackList));
        $appId = $this->getAppIdForSardegnaSentieri();

        $candidateIds = [];
        foreach ($trackList as $id => $apiTimestamp) {
            if (! $force && $appId !== null) {
                $existing = EcTrack::query()
                    ->where('app_id', $appId)
                    ->whereRaw("properties->>'sardegnasentieri_id' = ?", [$id])
                    ->selectRaw("properties->'forestas'->>'updated_at' as forestas_updated_at, properties->'forestas'->>'enti_mancanti' as enti_mancanti")
                    ->toBase()
                    ->first();

                // Un nodo che ha saltato un ente mancante si rilegge anche
                // con lo stesso changed (oc:8513).
                if ($existing !== null && $existing->forestas_updated_at === $apiTimestamp && $existing->enti_mancanti !== 'true') {
                    continue;
                }
            }

            $candidateIds[] = (int) $id;
        }

        $dispatched = [];
        $jobs = [];
        foreach ($this->sortTrackDispatchOrder($candidateIds, $trackList) as $id) {
            $jobs[] = new ImportSardegnaSentieriTrackJob($id);
            $dispatched[] = $id;
        }

        $this->info('Prepared '.count($dispatched).' Track import jobs.');

        $removed = $this->markRemovedTracks($apiIds);

        return [$dispatched, $removed, $jobs];
    }

    /**
     * Mark Tracks that are no longer in the API source as deleted_from_source.
     *
     * @param  string[]  $apiIds
     */
    private function markRemovedTracks(array $apiIds): int
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null) {
            return 0;
        }

        $removed = EcTrack::where('app_id', $appId)
            ->whereRaw("properties->>'sardegnasentieri_id' IS NOT NULL")
            ->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'")
            ->get()
            ->filter(function (EcTrack $track) use ($apiIds): bool {
                $sourceId = $track->properties['sardegnasentieri_id'] ?? null;

                return $sourceId !== null && ! in_array((string) $sourceId, $apiIds, true);
            });

        foreach ($removed as $track) {
            $properties = is_array($track->properties) ? $track->properties : [];
            $properties['forestas']['deleted_from_source'] = true;
            $track->properties = $properties;
            $track->saveQuietly();
        }

        if ($removed->count() > 0) {
            $this->warn("Marked {$removed->count()} Tracks as deleted_from_source.");
        }

        return $removed->count();
    }

    private function getAppIdForSardegnaSentieri(): ?int
    {
        $id = SardegnaSentieriImportService::IMPORT_APP_ID;

        return App::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Dispatch order: new rows first (by API updated_at desc), then existing by created_at desc
     * so Nova/index (typically newest first) gets body + media sooner.
     *
     * @param  list<int>  $candidateIds
     * @param  array<string, string>  $poiList
     * @return list<int>
     */
    private function sortPoiDispatchOrder(array $candidateIds, array $poiList): array
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null || $candidateIds === []) {
            return $candidateIds;
        }

        $existingBySourceId = $this->loadEcPoisBySourceIds($appId, $candidateIds);

        usort($candidateIds, function (int $a, int $b) use ($existingBySourceId, $poiList): int {
            $sa = (string) $a;
            $sb = (string) $b;
            $existsA = isset($existingBySourceId[$sa]);
            $existsB = isset($existingBySourceId[$sb]);
            if ($existsA !== $existsB) {
                return $existsA ? 1 : -1;
            }
            if (! $existsA) {
                return strcmp($poiList[$sb] ?? '', $poiList[$sa] ?? '');
            }

            return $existingBySourceId[$sb]->created_at <=> $existingBySourceId[$sa]->created_at;
        });

        return $candidateIds;
    }

    /**
     * @param  list<int>  $candidateIds
     * @param  array<string, string>  $trackList
     * @return list<int>
     */
    private function sortTrackDispatchOrder(array $candidateIds, array $trackList): array
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null || $candidateIds === []) {
            return $candidateIds;
        }

        $existingBySourceId = $this->loadEcTracksBySourceIds($appId, $candidateIds);

        usort($candidateIds, function (int $a, int $b) use ($existingBySourceId, $trackList): int {
            $sa = (string) $a;
            $sb = (string) $b;
            $existsA = isset($existingBySourceId[$sa]);
            $existsB = isset($existingBySourceId[$sb]);
            if ($existsA !== $existsB) {
                return $existsA ? 1 : -1;
            }
            if (! $existsA) {
                return strcmp($trackList[$sb] ?? '', $trackList[$sa] ?? '');
            }

            return $existingBySourceId[$sb]->created_at <=> $existingBySourceId[$sa]->created_at;
        });

        return $candidateIds;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, EcPoi>
     */
    private function loadEcPoisBySourceIds(int $appId, array $ids): array
    {
        $stringIds = array_map('strval', $ids);
        $query = EcPoi::query()->where('app_id', $appId);

        $query->where(function ($q) use ($stringIds) {
            foreach ($stringIds as $sid) {
                $q->orWhereRaw(
                    "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                    [$sid, $sid]
                );
            }
        });

        $map = [];
        foreach ($query->get() as $poi) {
            $key = (string) ($poi->properties['out_source_feature_id'] ?? $poi->properties['sardegnasentieri_id'] ?? '');
            if ($key !== '') {
                $map[$key] = $poi;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, EcTrack>
     */
    private function loadEcTracksBySourceIds(int $appId, array $ids): array
    {
        $stringIds = array_map('strval', $ids);
        $query = EcTrack::query()->where('app_id', $appId);

        $query->where(function ($q) use ($stringIds) {
            foreach ($stringIds as $sid) {
                $q->orWhereRaw("properties->>'sardegnasentieri_id' = ?", [$sid]);
            }
        });

        $map = [];
        foreach ($query->get() as $track) {
            $key = (string) ($track->properties['sardegnasentieri_id'] ?? '');
            if ($key !== '') {
                $map[$key] = $track;
            }
        }

        return $map;
    }

    /**
     * Ensure minimum entities needed by import exist.
     */
    /**
     * La fonte risponde prima di cancellare.
     *
     * Il 10/09/2026 sul collaudo Drupal ha risposto «Temporarily
     * Unavailable» subito dopo il troncamento: 763 tracciati e 970 punti
     * cancellati e non riscaricabili, recuperati copiandoli da un altro
     * ambiente. Questo controllo costa una chiamata e lo impedisce.
     */
    private function sourceIsReachable(SardegnaSentieriSource $source, string $runId): bool
    {
        $this->info('Checking source availability...');

        if ($source->isAvailable()) {
            $this->info('Source is reachable.');

            return true;
        }

        $this->error('La copia del DB Drupal non risponde o è vuota: import annullato, nessun dato e\' stato cancellato.');
        $this->line('Riprovare quando la copia è accesa e caricata (docs/howto/copia-database-drupal.md).');

        Log::channel('import')->error(
            "[sardegnasentieri:{$runId}] Source unreachable or empty, import aborted before any change."
        );

        return false;
    }

    /**
     * Un backup del database prima di cancellare.
     *
     * Il controllo sulla sorgente copre il caso previsto — la fonte giu' —
     * ma non quello imprevisto: un troncamento andato a buon fine e un
     * import che fallisce a meta'. Il backup e' la rete sotto entrambi.
     *
     * Un fallimento del backup ferma tutto: procedere senza sarebbe
     * esattamente la situazione da cui questo codice nasce.
     */
    private function backupBeforeReset(string $runId): bool
    {
        $this->info('Backing up the database before reset...');

        $exit = $this->call('wm:backup-run', ['--only-db' => true, '--disable-notifications' => true]);

        if ($exit === self::SUCCESS) {
            $this->info('Backup completed.');

            return true;
        }

        $this->error('Backup fallito: import annullato, nessun dato e\' stato cancellato.');

        Log::channel('import')->error(
            "[sardegnasentieri:{$runId}] Backup failed with exit code {$exit}, --reset aborted before truncate."
        );

        return false;
    }

    private function resetData(): void
    {
        // Truncate in order: tables with FKs first, then referenced tables.
        // CASCADE handles junction tables automatically.

        // Le domande di iscrizione non referenziano ec_tracks, quindi il
        // CASCADE qui sotto non le tocca: senza questa riga resterebbero in
        // piedi mentre i codici che avevano riservato spariscono insieme a
        // trail_registry_codes (oc:8607).
        DB::statement('TRUNCATE TABLE trail_applications RESTART IDENTITY CASCADE');
        $this->info('Truncated trail_applications.');

        DB::statement('TRUNCATE TABLE ec_tracks RESTART IDENTITY CASCADE');
        $this->info('Truncated ec_tracks.');

        DB::statement('TRUNCATE TABLE ec_pois RESTART IDENTITY CASCADE');
        $this->info('Truncated ec_pois.');

        DB::statement('TRUNCATE TABLE taxonomy_poi_types RESTART IDENTITY CASCADE');
        $this->info('Truncated taxonomy_poi_types.');

        // Le attività non si troncano più: i layer sono configurati per
        // attività, e il TRUNCATE ... CASCADE portava via anche i loro legami
        // in taxonomy_activityables, lasciando i filtri di EC Tracce vuoti
        // ogni mattina. L'import le ritrova per `identifier` (oc:8827).
        // Le due pivot (legami con attività e con layer) non hanno chiavi
        // esterne verso tracce e POI, quindi il CASCADE non le raggiunge: dopo
        // RESTART IDENTITY le righe vecchie punterebbero ai record nuovi con lo
        // stesso id. Si cancellano a mano solo le righe di tracce e POI; quelle
        // dei layer restano.
        DB::table('taxonomy_activityables')
            ->whereIn('taxonomy_activityable_type', self::TRACK_AND_POI_MORPH_TYPES)
            ->delete();
        DB::table('layerables')
            ->whereIn('layerable_type', self::TRACK_AND_POI_MORPH_TYPES)
            ->delete();
        $this->info('Cleared track and POI links from taxonomy_activityables and layerables.');

        // I collegamenti agli enti non hanno chiavi esterne verso POI e
        // sentieri: il CASCADE non li raggiunge, e dopo RESTART IDENTITY le
        // righe vecchie punterebbero ai sentieri nuovi con lo stesso id. Gli
        // enti invece restano: il reset li riscrive tutti (oc:8513).
        DB::statement('TRUNCATE TABLE enteables RESTART IDENTITY');
        $this->info('Truncated enteables.');
    }

    private function ensurePrerequisites(): bool
    {
        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        if (! App::query()->whereKey($appId)->exists()) {
            $this->error("L'app con id {$appId} non esiste. Crea l'app in Nova/DB (Forestas usa una sola app, id 1).");

            return false;
        }

        $editorRole = Role::firstOrCreate([
            'name' => 'Editor',
            'guard_name' => 'web',
        ]);

        $user = User::firstOrCreate(
            ['email' => 'forestas@webmapp.it'],
            [
                'name' => 'Sardegna Sentieri',
                'password' => Hash::make(Str::random(32)),
            ]
        );

        if (! $user->hasRole('Editor')) {
            $user->assignRole($editorRole);
        }

        return true;
    }
}
