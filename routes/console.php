<?php

use App\Jobs\Vpn\EnforceUserLimits;
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

// Every five minutes rather than every minute: an expiry date or a traffic
// allowance is not worth rewriting interface configs over more often than
// that, and the poll above is what keeps the usage figures current anyway.
Schedule::job(new EnforceUserLimits)->everyFiveMinutes()->onOneServer();

Schedule::command('logs:prune')->daily();
