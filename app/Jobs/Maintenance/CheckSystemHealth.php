<?php

namespace App\Jobs\Maintenance;

use App\Enums\NotificationEvent;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Periodic health checks that are worth telling an operator about out of band:
 * the disk filling up (the DNS traffic log is write-heavy and the single most
 * likely thing to fill it), and background jobs failing (a failed provisioning
 * or backup does not retry on its own, and nothing else surfaces it). Both are
 * cache-deduped so a standing condition does not re-notify every run.
 */
class CheckSystemHealth implements ShouldQueue
{
    use Queueable;

    private const THRESHOLD_PERCENT = 85;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $this->checkDisk();
        $this->checkFailedJobs();
    }

    private function checkDisk(): void
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! $total || $free === false) {
            return;
        }

        $usedPercent = (int) round(($total - $free) / $total * 100);

        if ($usedPercent < self::THRESHOLD_PERCENT) {
            Cache::forget('disk-warned');

            return;
        }

        if (Cache::add('disk-warned', true, now()->addDay())) {
            app(Notifier::class)->event(
                NotificationEvent::DiskWarning,
                'Disk space low',
                "The VPN server disk is {$usedPercent}% full. The DNS traffic log is usually what grows -- shorten retention or turn logging off for some users if needed.",
            );
        }
    }

    /**
     * Alert when the failed-job count grows. Jobs on the `system` queue --
     * provisioning, backups, enforcement -- have tries=1..3 and do not retry
     * forever; a failure that lands in failed_jobs is stuck until someone acts.
     * The last-seen count is remembered so a steady backlog does not re-notify
     * hourly, and clearing failed_jobs re-arms the alert.
     */
    private function checkFailedJobs(): void
    {
        $count = (int) DB::table('failed_jobs')->count();
        $lastSeen = (int) Cache::get('failed-jobs-notified', 0);

        if ($count > $lastSeen) {
            app(Notifier::class)->event(
                NotificationEvent::JobsFailed,
                'A background job failed',
                "There are {$count} failed background job(s). A failed provisioning, backup or enforcement job does not retry on its own -- check the panel and `journalctl -u vpnforge-worker`.",
            );
        }

        Cache::put('failed-jobs-notified', $count, now()->addDays(30));
    }
}
