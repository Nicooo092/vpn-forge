<?php

namespace App\Jobs\Vpn;

use App\Enums\QuotaAction;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Enforces time-of-day access windows: suspend a user when the clock leaves
 * their allowed window, resume them when it comes back inside. Runs every
 * minute so the cut-off is punctual.
 *
 * Kept apart from EnforceUserLimits because this one is two-directional (it
 * resumes on its own), while expiry and the traffic allowance are one-way
 * (raised or pushed back by hand). It owns exactly one suspension reason and
 * only ever touches users carrying it, so a manual suspension, an expiry or an
 * exhausted allowance is never overridden or silently undone.
 */
class EnforceAccessWindows implements ShouldQueue
{
    use Queueable;

    public const REASON = 'outside access hours';

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        foreach (Service::where('status', ServiceStatus::Active)->get() as $service) {
            try {
                $this->enforce($service);
            } catch (Throwable $e) {
                $service->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }
    }

    private function enforce(Service $service): void
    {
        $changed = false;

        foreach ($service->serviceUsers()->get() as $user) {
            // A user with no schedule is treated as always "within" -- so one
            // that was left suspended by this job and then had its schedule
            // removed still gets resumed below, rather than stranded. Without a
            // schedule the suspend branch can never fire (within is true).
            $within = $user->hasAccessSchedule() ? $user->isWithinAccessWindow() : true;

            if ($user->status === ServiceUserStatus::Active && ! $within) {
                // Clock left the window: suspend. Deliberately no notification
                // -- a scheduled window closing is routine and would alert
                // every evening for every scheduled device. Clear any throttle
                // flag so the row reflects the real reason it is suspended
                // ('outside access hours'), not a stale 'throttled' badge;
                // EnforceUserLimits re-applies the throttle if they come back
                // over the allowance once the window reopens.
                $user->forceFill([
                    'status' => ServiceUserStatus::Suspended,
                    'suspended_reason' => self::REASON,
                    'quota_throttled' => false,
                ])->save();
                $changed = true;

                continue;
            }

            // Only ever resume a user this job suspended -- never a manual
            // suspension, an expiry or an exhausted allowance.
            if ($user->status === ServiceUserStatus::Suspended
                && $user->suspended_reason === self::REASON
                && $within) {
                if ($this->blockedByOtherLimit($user)) {
                    // Window is open, but another limit still holds them back;
                    // hand the reason over rather than resuming, and leave them
                    // suspended for EnforceUserLimits / a manual resume to clear.
                    $user->forceFill([
                        'suspended_reason' => $user->isExpired() ? 'expired' : 'data limit reached',
                    ])->save();

                    continue;
                }

                $user->forceFill([
                    'status' => ServiceUserStatus::Active,
                    'suspended_reason' => null,
                ])->save();
                $changed = true;
            }
        }

        // A single re-apply for the whole batch: rewrites the peer list
        // (WireGuard) or the client-config-dir + live sessions (OpenVPN).
        if ($changed) {
            $service->driver()->applyServiceConfig($service);

            // The driver's applyServiceConfig does NOT touch tc shaping (only
            // the ApplyServiceConfig job does). A resumed user therefore came
            // back uncapped -- and TrafficShaper::plan() only builds classes
            // for Active users, so a limited user suspended when the shaper was
            // last rebuilt (e.g. at boot, in RestoreAfterBoot) has no class at
            // all until this runs. Re-apply it so a per-user speed limit
            // survives a suspend/resume cycle.
            app(TrafficShaper::class)->apply($service);
        }
    }

    private function blockedByOtherLimit(ServiceUser $user): bool
    {
        // Expiry is always a hard cut-off. An exhausted data allowance only
        // keeps them suspended when their quota action is Suspend -- a
        // Throttle-action user is meant to come back connected-but-slow, so do
        // NOT treat over-limit as a blocker for them: resume them here and let
        // EnforceUserLimits re-throttle, rather than stranding them suspended
        // (nothing else would ever revive a throttle-action user with no reset).
        if ($user->isExpired()) {
            return true;
        }

        $throttles = $user->quota_action === QuotaAction::Throttle
            && $user->quota_throttle_kbps !== null;

        return $user->isOverDataLimit() && ! $throttles;
    }
}
