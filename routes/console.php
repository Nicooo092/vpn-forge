<?php

use App\Jobs\Vpn\PollAllServiceStatuses;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel's scheduler has no finer granularity than a minute -- this is the
// practical ceiling for how fresh connection/bandwidth stats can be without
// a bespoke long-running loop, which isn't worth the complexity here.
Schedule::job(new PollAllServiceStatuses)->everyMinute()->onOneServer();

Schedule::command('logs:prune')->daily();
