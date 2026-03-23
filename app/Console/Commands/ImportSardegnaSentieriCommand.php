<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Clients\SardegnaSentieriClient;
use App\Jobs\Import\ImportSardegnaSentieriPoiJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Services\Import\SardegnaSentieriImportService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
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
        $this->ensurePrerequisites();

        $this->info('Importing taxonomies...');
        $importService->importAll();
        $this->info('Taxonomies imported.');

        $only = $this->option('only');

        // Import POIs
        if (!$only || $only === 'pois') {
            $this->importPois($client);
        }

        // Import Tracks
        if (!$only || $only === 'tracks') {
            $this->importTracks($client);
        }

        $this->info('Import completed.');

        return self::SUCCESS;
    }

    /**
     * Import POIs from API
     */
    private function importPois(SardegnaSentieriClient $client): void
    {
        $this->info('Fetching POI list...');
        $poiList = $client->getPoiList();
        $this->info('Found ' . count($poiList) . ' POIs.');

        $poiCount = 0;
        $force = $this->option('force');
        $apiIds = array_map('strval', array_keys($poiList));

        foreach ($poiList as $id => $apiTimestamp) {
            // Skip if already up to date (unless force)
            if (!$force) {
                $existing = EcPoi::whereRaw(
                    "(properties->>'out_source_feature_id' = ? OR properties->>'sardegnasentieri_id' = ?)",
                    [(string) $id, (string) $id]
                )
                    ->selectRaw("properties->'forestas'->>'updated_at' as updated_at")
                    ->value('updated_at');

                if ($existing === $apiTimestamp) {
                    continue;
                }
            }

            ImportSardegnaSentieriPoiJob::dispatch((int) $id);
            $poiCount++;
        }

        $this->info("Dispatched {$poiCount} POI import jobs.");

        // Mark POIs no longer present in the API
        $this->markRemovedPois($apiIds);
    }

    /**
     * Mark POIs that are no longer in the API source as deleted_from_source.
     *
     * @param string[] $apiIds
     */
    private function markRemovedPois(array $apiIds): void
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null) {
            return;
        }

        $removed = EcPoi::where('app_id', $appId)
            ->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'")
            ->get()
            ->filter(function (EcPoi $poi) use ($apiIds): bool {
                $sourceId = $poi->properties['out_source_feature_id']
                    ?? $poi->properties['sardegnasentieri_id']
                    ?? null;

                return $sourceId !== null && !in_array((string) $sourceId, $apiIds, true);
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
    }

    /**
     * Import Tracks from API
     */
    private function importTracks(SardegnaSentieriClient $client): void
    {
        $this->info('Fetching track list...');
        $trackList = $client->getTrackList();
        $this->info('Found ' . count($trackList) . ' tracks.');

        $trackCount = 0;
        $force = $this->option('force');
        $apiIds = array_map('strval', array_keys($trackList));

        foreach ($trackList as $id => $apiTimestamp) {
            // Skip if already up to date (unless force)
            if (!$force) {
                $existing = EcTrack::whereRaw("properties->>'sardegnasentieri_id' = ?", [$id])
                    ->selectRaw("properties->'forestas'->>'updated_at' as updated_at")
                    ->value('updated_at');

                if ($existing === $apiTimestamp) {
                    continue;
                }
            }

            ImportSardegnaSentieriTrackJob::dispatch((int) $id);
            $trackCount++;
        }

        $this->info("Dispatched {$trackCount} Track import jobs.");

        // Mark tracks no longer present in the API
        $this->markRemovedTracks($apiIds);
    }

    /**
     * Mark Tracks that are no longer in the API source as deleted_from_source.
     *
     * @param string[] $apiIds
     */
    private function markRemovedTracks(array $apiIds): void
    {
        $appId = $this->getAppIdForSardegnaSentieri();
        if ($appId === null) {
            return;
        }

        $removed = EcTrack::where('app_id', $appId)
            ->whereRaw("properties->>'sardegnasentieri_id' IS NOT NULL")
            ->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'")
            ->get()
            ->filter(function (EcTrack $track) use ($apiIds): bool {
                $sourceId = $track->properties['sardegnasentieri_id'] ?? null;

                return $sourceId !== null && !in_array((string) $sourceId, $apiIds, true);
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
    }

    private function getAppIdForSardegnaSentieri(): ?int
    {
        return App::where('sku', 'it.webmapp.sardegnasentieri')->value('id');
    }

    /**
     * Ensure minimum entities needed by import exist.
     */
    private function ensurePrerequisites(): void
    {
        $editorRole = Role::firstOrCreate([
            'name' => 'Editor',
            'guard_name' => 'web',
        ]);

        $user = User::firstOrCreate(
            ['email' => 'forestas@webmapp.it'],
            [
                'name' => 'forestas',
                'password' => Hash::make(Str::random(32)),
            ]
        );

        if (!$user->hasRole('Editor')) {
            $user->assignRole($editorRole);
        }

        App::withoutEvents(function () use ($user) {
            App::firstOrCreate(
                ['sku' => 'it.webmapp.sardegnasentieri'],
                [
                    'name' => 'Sardegna Sentieri',
                    'customer_name' => 'forestas',
                    'user_id' => $user->id,
                ]
            );
        });
    }
}
