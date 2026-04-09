<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ente;
use App\Models\TaxonomyWarning;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;

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

        // Kill all Horizon processes and clear all queues before reset
        exec('pkill -9 -f "artisan horizon" 2>/dev/null');
        sleep(2);
        $this->call('queue:clear', ['--queue' => 'default']);
        $this->call('queue:clear', ['--queue' => 'aws']);
        $this->call('queue:clear', ['--queue' => 'pbf']);
        $this->call('queue:clear', ['--queue' => 'dem']);
        $this->call('queue:clear', ['--queue' => 'layers']);

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

        // 2. Delete EcPois — bypass observer entirely with raw DB deletes
        $poiIds = EcPoi::where('app_id', $appId)->pluck('id');
        DB::table('ec_poi_ec_track')->whereIn('ec_poi_id', $poiIds)->delete();
        DB::table('ec_poi_related_pois')->whereIn('ec_poi_id', $poiIds)->orWhereIn('related_poi_id', $poiIds)->delete();
        DB::table('taxonomy_activityables')->where('taxonomy_activityable_type', EcPoi::class)->whereIn('taxonomy_activityable_id', $poiIds)->delete();
        DB::table('taxonomy_poi_typeables')->where('taxonomy_poi_typeable_type', EcPoi::class)->whereIn('taxonomy_poi_typeable_id', $poiIds)->delete();
        $poiCount = $poiIds->count();
        DB::table('ec_pois')->whereIn('id', $poiIds)->delete();
        $this->info("Eliminati {$poiCount} EcPoi.");

        // 3. Delete Taxonomies imported from Sardegna Sentieri (identified by source_id in properties)
        $poiTypeCount = TaxonomyPoiType::whereNotNull('properties->source_id')->count();
        DB::table('taxonomy_poi_typeables')->whereIn('taxonomy_poi_type_id', TaxonomyPoiType::whereNotNull('properties->source_id')->pluck('id'))->delete();
        TaxonomyPoiType::whereNotNull('properties->source_id')->delete();
        $this->info("Eliminati {$poiTypeCount} TaxonomyPoiType.");

        $activityIds = TaxonomyActivity::where(function ($q) {
            $q->whereNotNull('properties->source_id')
                ->orWhere('identifier', 'like', 'name:%')
                ->orWhere('identifier', 'like', 'sardegnasentieri:%');
        })->pluck('id');
        $activityCount = $activityIds->count();
        DB::table('taxonomy_activityables')->whereIn('taxonomy_activity_id', $activityIds)->delete();
        TaxonomyActivity::whereIn('id', $activityIds)->delete();
        $this->info("Eliminati {$activityCount} TaxonomyActivity.");

        $warningCount = TaxonomyWarning::whereNotNull('properties->source_id')->count();
        DB::table('taxonomy_warningables')->whereIn('taxonomy_warning_id', TaxonomyWarning::whereNotNull('properties->source_id')->pluck('id'))->delete();
        TaxonomyWarning::whereNotNull('properties->source_id')->delete();
        $this->info("Eliminati {$warningCount} TaxonomyWarning.");

        // 4. Delete Enti (and enteables via cascade)
        DB::table('enteables')->delete();
        $enteCount = Ente::count();
        Ente::query()->each(fn (Ente $e) => $e->delete());
        $this->info("Eliminati {$enteCount} Ente.");

        // 5. Flush Horizon Redis data (pending/recent/failed jobs and all job hashes)
        $prefix = config('database.redis.options.prefix', '');
        $horizonPrefix = $prefix ? "{$prefix}horizon:*" : 'horizon:*';
        $cursor = null;
        $deleted = 0;
        do {
            [$cursor, $keys] = Redis::scan($cursor ?? 0, ['match' => $horizonPrefix, 'count' => 500]);
            if (! empty($keys)) {
                Redis::del($keys);
                $deleted += count($keys);
            }
        } while ($cursor != 0);
        $this->info("Horizon Redis svuotato ({$deleted} chiavi rimosse).");

        // 6. Reset sequences
        DB::statement('ALTER SEQUENCE ec_tracks_id_seq RESTART WITH 1');
        DB::statement('ALTER SEQUENCE ec_pois_id_seq RESTART WITH 1');
        DB::statement('ALTER SEQUENCE entes_id_seq RESTART WITH 1');
        $this->info('Sequence ID azzerate.');

        $this->info('Reset completato. Eseguire "sardegnasentieri:import --force" per reimportare.');

        return self::SUCCESS;
    }
}
