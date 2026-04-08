<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        // 1. Delete EcTracks first — bypass observers by using raw DB deletes
        $trackIds = EcTrack::where('app_id', $appId)->pluck('id');
        DB::table('taxonomy_activityables')->where('taxonomy_activityable_type', EcTrack::class)->whereIn('taxonomy_activityable_id', $trackIds)->delete();
        DB::table('ec_poi_ec_track')->whereIn('ec_track_id', $trackIds)->delete();
        $tracks = EcTrack::where('app_id', $appId)->get();
        foreach ($tracks as $track) {
            $track->delete();
        }
        $this->info("Eliminati {$tracks->count()} EcTrack.");

        // 2. Delete EcPois — detach all track relations first to bypass observer
        $poiIds = EcPoi::where('app_id', $appId)->pluck('id');
        DB::table('ec_poi_ec_track')->whereIn('ec_poi_id', $poiIds)->delete();
        $pois = EcPoi::where('app_id', $appId)->get();
        foreach ($pois as $poi) {
            $poi->taxonomyPoiTypes()->detach();
            $poi->delete();
        }
        $this->info("Eliminati {$pois->count()} EcPoi.");

        // 3. Delete Enti (and enteables via cascade)
        DB::table('enteables')->delete();
        $enteCount = Ente::count();
        Ente::query()->each(fn (Ente $e) => $e->delete());
        $this->info("Eliminati {$enteCount} Ente.");

        // 4. Reset sequences
        DB::statement('ALTER SEQUENCE ec_tracks_id_seq RESTART WITH 1');
        DB::statement('ALTER SEQUENCE ec_pois_id_seq RESTART WITH 1');
        DB::statement('ALTER SEQUENCE entes_id_seq RESTART WITH 1');
        $this->info('Sequence ID azzerate.');

        $this->info('Reset completato. Eseguire "sardegnasentieri:import --force" per reimportare.');

        return self::SUCCESS;
    }
}
