<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rolls each user's allowance window forward on its configured cadence
 * (weekly/monthly), so usage counts from zero again without any history being
 * deleted -- only quota_started_at moves, and dataUsedBytes() sums from there.
 *
 * Runs on the `system` queue because clearing a quota suspension rewrites the
 * interface config and lifting a quota throttle rebuilds the tc classes; both
 * need the privileged worker. The maths of when a reset is due lives on the
 * model (ServiceUser::dueQuotaReset), so it is unit-testable without root.
 */
class ResetUserQuotas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        foreach (Service::where('status', ServiceStatus::Active)->get() as $service) {
            try {
                $this->reset($service);
            } catch (Throwable $e) {
                $service->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }
    }

    private function reset(Service $service): void
    {
        // A resumed suspension rewrites the peer list (config); a lifted throttle
        // only rebuilds the tc classes (shaping). Track them apart so a throttle
        // reset does not force a full service re-apply.
        $configChanged = false;
        $shapingChanged = false;

        foreach ($service->serviceUsers()->whereNotNull('quota_started_at')->get() as $user) {
            $newStart = $user->dueQuotaReset();

            if ($newStart === null) {
                continue;
            }

            $attributes = ['quota_started_at' => $newStart];

            // Drop a throttle the exhausted allowance had applied -- the fresh
            // window starts empty, so they are no longer over.
            if ($user->quota_throttled) {
                $attributes['quota_throttled'] = false;
                $shapingChanged = true;
            }

            // Resume a user this allowance suspended, but only when nothing else
            // still holds them back -- otherwise EnforceUserLimits /
            // EnforceAccessWindows would just cut them off again within the
            // minute. When another limit does hold, hand the reason over rather
            // than resuming, mirroring EnforceAccessWindows.
            if ($user->status === ServiceUserStatus::Suspended
                && $user->suspended_reason === 'data limit reached') {
                if (! $user->isExpired() && $user->isWithinAccessWindow()) {
                    $attributes['status'] = ServiceUserStatus::Active;
                    $attributes['suspended_reason'] = null;
                    $configChanged = true;
                } else {
                    $attributes['suspended_reason'] = $user->isExpired()
                        ? 'expired'
                        : EnforceAccessWindows::REASON;
                }
            }

            $user->forceFill($attributes)->save();
        }

        if ($configChanged) {
            $service->driver()->applyServiceConfig($service);
            app(TrafficShaper::class)->apply($service);
        } elseif ($shapingChanged) {
            app(TrafficShaper::class)->apply($service);
        }
    }
}
