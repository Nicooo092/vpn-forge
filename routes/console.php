<?php

use App\Jobs\Blocklist\RefreshBlocklists;
use App\Jobs\Maintenance\CheckSystemHealth;
use App\Jobs\Vpn\EnforceAccessWindows;
use App\Jobs\Vpn\EnforceDeviceLimits;
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

// Access windows and device caps run every minute, so a cut-off is punctual.
// Both only rewrite anything on an actual transition (a window closing, an
// excess connection appearing), so the frequency is cheap.
Schedule::job(new EnforceAccessWindows)->everyMinute()->onOneServer();
Schedule::job(new EnforceDeviceLimits)->everyMinute()->onOneServer();

Schedule::command('logs:prune')->daily();

// Disk-space heads-up (deduped to at most once a day inside the job).
Schedule::job(new CheckSystemHealth)->hourly()->onOneServer();

// Keep the subscribed blocklists current. Off-peak, and only does anything if
// there are enabled subscriptions.
Schedule::job(new RefreshBlocklists)->dailyAt('04:30')->onOneServer();

// Scheduled "sites visités" PDF exports. Cadence is config-driven (env
// VPNFORGE_EXPORT_SCHEDULE) so an operator can turn it off or change it
// without touching code; 'off' registers nothing at all.
$exportCadence = config('vpnforge.exports.schedule', 'weekly');

if ($exportCadence !== 'off') {
    $export = Schedule::command('vpnforge:export-report')->onOneServer();

    match ($exportCadence) {
        'daily' => $export->dailyAt('06:00'),
        'monthly' => $export->monthlyOn(1, '06:00'),
        default => $export->weeklyOn(1, '06:00'), // Monday 06:00
    };
}
