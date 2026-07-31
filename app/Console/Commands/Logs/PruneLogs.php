<?php

namespace App\Console\Commands\Logs;

use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\TrafficLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('logs:prune')]
#[Description('Delete traffic, connection and bandwidth log rows past the 90-day retention limit')]
class PruneLogs extends Command
{
    // Deliberately not configurable higher than this via a flag -- the
    // user set 90 days as a hard ceiling, not a default.
    private const RETENTION_DAYS = 90;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $traffic = TrafficLog::where('occurred_at', '<', $cutoff)->delete();
        $connections = ConnectionLog::where('connected_at', '<', $cutoff)->delete();
        $bandwidth = BandwidthSample::where('sampled_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Pruned %d traffic log rows, %d connection log rows, %d bandwidth sample rows older than %s.',
            $traffic,
            $connections,
            $bandwidth,
            $cutoff->toDateString(),
        ));

        return self::SUCCESS;
    }
}
