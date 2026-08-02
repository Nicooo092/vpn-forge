<?php

namespace App\Jobs\Maintenance;

use App\Enums\NotificationEvent;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A periodic disk-space check. The DNS traffic log is write-heavy and the
 * single most likely thing to fill the disk, so a heads-up before it does
 * matters. Fires at most once a day (cache-deduped) and re-arms once space
 * recovers.
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
}
