<?php

namespace App\Jobs\Vpn;

use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Turns the expiry date and the traffic allowance into something that
 * actually happens. Runs on the `system` queue because suspending a user
 * means rewriting the interface config, which needs the privileged worker.
 *
 * Suspension, not revocation: both of these are recoverable situations --
 * an allowance is raised, a date is pushed back -- and revoking an OpenVPN
 * certificate is not undoable.
 */
class EnforceUserLimits implements ShouldQueue
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
                $this->enforce($service);
            } catch (Throwable $e) {
                $service->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }
    }

    private function enforce(Service $service): void
    {
        $suspended = false;

        foreach ($service->serviceUsers()->where('status', ServiceUserStatus::Active)->get() as $user) {
            $reason = match (true) {
                $user->isExpired() => 'expired',
                $user->isOverDataLimit() => 'data limit reached',
                default => null,
            };

            if ($reason === null) {
                $this->maybeWarnApproachingLimit($user);

                continue;
            }

            $user->forceFill([
                'status' => ServiceUserStatus::Suspended,
                'suspended_reason' => $reason,
            ])->save();

            app(Notifier::class)->event(
                NotificationEvent::UserSuspended,
                "{$user->name} suspended",
                "Reason: {$reason} (service {$service->name}).",
            );

            $suspended = true;
        }

        if (! $suspended) {
            return;
        }

        // One re-apply for the whole batch rather than one per user: for
        // WireGuard this rewrites the peer list, for OpenVPN it writes the
        // client-config-dir entries and cuts the live sessions.
        $service->driver()->applyServiceConfig($service);
    }

    /**
     * A one-off heads-up when a user crosses 90% of their allowance, so it can
     * be raised before they are cut off. De-duplicated per quota window via the
     * cache -- moving quota_started_at (a reset) changes the key and re-arms it,
     * so no new column is needed to remember "already warned".
     */
    private function maybeWarnApproachingLimit(ServiceUser $user): void
    {
        $ratio = $user->dataUsageRatio();

        if ($ratio === null || $ratio < 0.9 || $ratio >= 1.0) {
            return;
        }

        $key = "quota-warned:{$user->id}:".($user->quota_started_at?->getTimestamp() ?? 0);

        if (Cache::add($key, true, now()->addDays(120))) {
            app(Notifier::class)->event(
                NotificationEvent::QuotaWarning,
                "{$user->name} near their data allowance",
                round($ratio * 100).'% used on service '.$user->service->name.'.',
            );
        }
    }
}
