<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Clients\SardegnaSentieriClient;
use App\Jobs\Import\ImportSardegnaSentieriPoiJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Models\User;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

class ImportSardegnaSentieriCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sardegnasentieri:import
                            {--force : Reimport all ignoring updated_at}
                            {--only= : Import only pois or tracks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import POIs and Tracks from Sardegna Sentieri API';

    /**
     * Execute the console command.
     */
    public function handle(
        SardegnaSentieriClient $client,
        SardegnaSentieriImportService $importService
    ): int {
        $runId = strtoupper(Str::random(8));

        if (! $this->ensurePrerequisites()) {
            Log::channel('import')->error("[sardegnasentieri:{$runId}] Prerequisites not met, import aborted.");

            return self::FAILURE;
        }

        $this->info('Importing taxonomies...');
        $importService->importAll();
        $this->info('Taxonomies imported.');

        $only = $this->option('only');
        $poisDispatched = [];
        $tracksDispatched = [];
        $poisRemoved = 0;
        $tracksRemoved = 0;

        // Import POIs
        if (! $only || $only === 'pois') {
            [$poisDispatched, $poisRemoved] = $this->importPois($client);
        }

        // Import Tracks
        if (! $only || $only === 'tracks') {
            [$tracksDispatched, $tracksRemoved] = $this->importTracks($client);
        }

        $this->info('Import completed.');

        Log::channel('import')->info("[sardegnasentieri:{$runId}] Run completed.", [
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
     * Import POIs from API
     *
     * @return array{0: list<int>, 1: int}
     */
    private function importPois(SardegnaSentieriClient $client): array
    {
        $this->info('Fetching POI list...');
        $poiList = $client->getPoiList();
        $this->info('Found '.count($poiList).' POIs.');

        $force = $this->option('force');
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
                    ->selectRaw("properties->'forestas'->>'updated_at' as forestas_updated_at")
                    ->value('forestas_updated_at');

                if ($existing === $apiTimestamp) {
                    continue;
                }
            }

            $candidateIds[] = (int) $id;
        }

        $dispatched = [];
        foreach ($this->sortPoiDispatchOrder($candidateIds, $poiList) as $id) {
            ImportSardegnaSentieriPoiJob::dispatch($id);
            $dispatched[] = $id;
        }

        $this->info('Dispatched '.count($dispatched).' POI import jobs.');

        $removed = $this->markRemovedPois($apiIds);

        return [$dispatched, $removed];
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
     * @return array{0: list<int>, 1: int}
     */
    private function importTracks(SardegnaSentieriClient $client): array
    {
        $this->info('Fetching track list...');
        $trackList = $client->getTrackList();
        $this->info('Found '.count($trackList).' tracks.');

        $force = $this->option('force');
        $apiIds = array_map('strval', array_keys($trackList));
        $appId = $this->getAppIdForSardegnaSentieri();

        $candidateIds = [];
        foreach ($trackList as $id => $apiTimestamp) {
            if (! $force && $appId !== null) {
                $existing = EcTrack::query()
                    ->where('app_id', $appId)
                    ->whereRaw("properties->>'sardegnasentieri_id' = ?", [$id])
                    ->selectRaw("properties->'forestas'->>'updated_at' as forestas_updated_at")
                    ->value('forestas_updated_at');

                if ($existing === $apiTimestamp) {
                    continue;
                }
            }

            $candidateIds[] = (int) $id;
        }

        $dispatched = [];
        foreach ($this->sortTrackDispatchOrder($candidateIds, $trackList) as $id) {
            ImportSardegnaSentieriTrackJob::dispatch($id);
            $dispatched[] = $id;
        }

        $this->info('Dispatched '.count($dispatched).' Track import jobs.');

        $removed = $this->markRemovedTracks($apiIds);

        return [$dispatched, $removed];
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
