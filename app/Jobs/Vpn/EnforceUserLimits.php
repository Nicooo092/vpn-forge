<?php

namespace App\Jobs\Vpn;

use App\Enums\NotificationEvent;
use App\Enums\QuotaAction;
use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\UserMailType;
use App\Jobs\Notifications\SendUserMail;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Notifications\Notifier;
use App\Services\Vpn\TrafficShaper;
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
        // A peer-list change (a suspension) needs the driver to rewrite the
        // interface config; a throttle going on or coming off only needs the tc
        // classes rebuilt. Track them apart so a throttle does not force a full
        // service re-apply.
        $configChanged = false;
        $shapingChanged = false;

        foreach ($service->serviceUsers()->where('status', ServiceUserStatus::Active)->get() as $user) {
            // Expiry is a separate limit from the allowance and is always a
            // cut-off -- throttling only ever applies to the data allowance.
            if ($user->isExpired()) {
                $this->suspend($user, 'expired', $service);
                $configChanged = true;

                continue;
            }

            // Fires for every still-valid user, whatever the throttle/cut-off
            // path below does -- a throttled or over-limit user still deserves
            // the expiry heads-up. (maybeWarnApproachingExpiry self-guards
            // against already-expired users.)
            $this->maybeWarnApproachingExpiry($user);

            if ($user->isOverDataLimit()) {
                // Throttle instead of suspending, when the user is set to it and
                // a throttle rate is configured; otherwise fall back to the
                // cut-off so a half-set throttle never leaves them uncapped.
                if ($user->quota_action === QuotaAction::Throttle && $user->quota_throttle_kbps !== null) {
                    if (! $user->quota_throttled) {
                        $user->forceFill(['quota_throttled' => true])->save();

                        app(Notifier::class)->event(
                            NotificationEvent::UserThrottled,
                            "{$user->name} throttled",
                            "Reason: data limit reached (service {$service->name}).",
                        );

                        $shapingChanged = true;
                    }

                    continue;
                }

                $this->suspend($user, 'data limit reached', $service);
                $configChanged = true;

                continue;
            }

            // Under the allowance again (it was raised, or the window reset):
            // lift a throttle the exhausted allowance had put on them so full
            // speed is restored on the shaping pass below.
            if ($user->quota_throttled) {
                $user->forceFill(['quota_throttled' => false])->save();
                $shapingChanged = true;
            }

            $this->maybeWarnApproachingLimit($user);
        }

        if ($configChanged) {
            // One re-apply for the whole batch rather than one per user: for
            // WireGuard this rewrites the peer list, for OpenVPN it writes the
            // client-config-dir entries and cuts the live sessions.
            $service->driver()->applyServiceConfig($service);

            // Keep tc shaping in step with the current active set -- the driver's
            // applyServiceConfig does not, and a suspension leaves a stale
            // per-user class behind otherwise.
            app(TrafficShaper::class)->apply($service);
        } elseif ($shapingChanged) {
            // No peer changed, but a throttle went on or came off -- re-cap the
            // tc classes without rewriting the interface config.
            app(TrafficShaper::class)->apply($service);
        }
    }

    private function suspend(ServiceUser $user, string $reason, Service $service): void
    {
        $user->forceFill([
            'status' => ServiceUserStatus::Suspended,
            'suspended_reason' => $reason,
        ])->save();

        app(Notifier::class)->event(
            NotificationEvent::UserSuspended,
            "{$user->name} suspended",
            "Reason: {$reason} (service {$service->name}).",
        );
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

    /**
     * The expiry twin of the quota warning: a one-off operator heads-up when a
     * user's expires_at falls inside the configured window
     * (VPNFORGE_EXPIRY_WARN_DAYS, default 3 days) but has not yet passed -- an
     * expired user is suspended above, not warned. De-duplicated per user per
     * expiry via the cache, keyed on the expiry timestamp so pushing the date
     * back re-arms the warning without a column to remember "already warned".
     */
    private function maybeWarnApproachingExpiry(ServiceUser $user): void
    {
        if ($user->expires_at === null || $user->isExpired()) {
            return;
        }

        $windowDays = (int) config('vpnforge.expiry.warn_days', 3);

        if ($windowDays <= 0 || $user->expires_at->isAfter(now()->addDays($windowDays))) {
            return;
        }

        $key = "expiry-warned:{$user->id}:".$user->expires_at->getTimestamp();

        if (Cache::add($key, true, now()->addDays(120))) {
            app(Notifier::class)->event(
                NotificationEvent::ExpiryApproaching,
                "{$user->name} is expiring soon",
                'Expires '.$user->expires_at->diffForHumans().' -- service '.$user->service->name.'.',
            );

            // Tell the user themselves, if they have an address and a real
            // mailer is set up. Gated and de-duplicated inside the job; keyed on
            // the same expiry timestamp so a pushed-back date re-arms it.
            SendUserMail::dispatch(
                $user,
                UserMailType::AccessExpiring,
                "user-mail:expiry:{$user->id}:".$user->expires_at->getTimestamp(),
            );
        }
    }
}
