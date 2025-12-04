<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->exec('certbot renew --quiet')
            ->dailyAt('02:00')
            ->description('Renew SSL certificates');

        $schedule->command('wm-osmfeatures:sync')
            ->dailyAt('02:00')
            ->description('Sync wm-osmfeatures');

        $schedule->command('wm-osmfeatures:import-sync')
            ->dailyAt('03:30')
            ->description('Import-sync wm-osmfeatures');

        $schedule->command('horizon:snapshot')
            ->hourlyAt(10)
            ->description('Take Horizon snapshot');

        $schedule->command('osm2cai:update-hiking-routes')
            ->dailyAt('05:00')
            ->description('Check osmfeatures for hiking routes updates');

        $schedule->command('osm2cai:calculate-region-hiking-routes-intersection')
            ->dailyAt('7:00')
            ->description('Calculate region hiking routes intersection');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
