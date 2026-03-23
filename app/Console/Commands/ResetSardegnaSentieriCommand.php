<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;

class ResetSardegnaSentieriCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sardegnasentieri:reset
                            {--yes : Skip interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all imported Sardegna Sentieri data for clean reimport';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('yes') && ! $this->confirm(
            'Eliminare tutti i dati Sardegna Sentieri? Questa operazione è irreversibile.'
        )) {
            return self::SUCCESS;
        }

        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        if (! App::query()->whereKey($appId)->exists()) {
            $this->warn("App id {$appId} non trovata. Nothing to reset.");

            return self::SUCCESS;
        }

        // 1. Delete EcTracks first, to avoid POI observer blocks on linked tracks
        $tracks = EcTrack::where('app_id', $appId)->get();
        foreach ($tracks as $track) {
            $track->taxonomyActivities()->detach();
            $track->ecPois()->detach();
            // Note: EcMedia relations are handled via spatie media library
            $track->delete();
        }
        $this->info("Eliminati {$tracks->count()} EcTrack.");

        // 2. Delete EcPois
        $pois = EcPoi::where('app_id', $appId)->get();
        foreach ($pois as $poi) {
            $poi->taxonomyPoiTypes()->detach();
            // Note: EcMedia relations are handled via spatie media library
            $poi->delete();
        }
        $this->info("Eliminati {$pois->count()} EcPoi.");

        $this->info('Reset completato. Eseguire "sardegnasentieri:import --force" per reimportare.');

        return self::SUCCESS;
    }
}
