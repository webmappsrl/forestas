<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync from Sardegna Sentieri API every 5 minutes (incremental via updated_at)
if (App::environment('staging')) {
    Schedule::command('sardegnasentieri:import --reset')->dailyAt('06:00');
} else {
    Schedule::command('sardegnasentieri:import')->hourly();
}
