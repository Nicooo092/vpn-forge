<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Carries a panel lockdown out on the network. Runs on the `system` queue
 * because suspending a user rewrites the interface config, which only the
 * privileged worker may do -- the web page merely dispatches this.
 *
 * Engaging suspends every active user on every service; lifting restores only
 * the users lockdown itself suspended (matched by the reason it stamps), so a
 * user that is expired, out of allowance, outside its window or suspended by
 * hand is never silently switched back on. It owns exactly one suspension
 * reason -- the same discipline EnforceAccessWindows follows -- so it never
 * collides with the other enforcement jobs.
 */
class ApplyLockdown implements ShouldQueue
{
    use Queueable;

    public const REASON = 'panel lockdown';

    public int $tries = 1;

    public function __construct(public bool $engage)
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        // Every service, not only the Active ones: a lockdown is meant to be
        // total, and the DB status must reflect it everywhere even if one
        // service's driver apply fails below.
        foreach (Service::all() as $service) {
            try {
                $changed = $this->engage
                    ? $this->suspend($service)
                    : $this->restore($service);

                if (! $changed) {
                    continue;
                }

                // One re-apply for the whole batch: rewrites the peer list
                // (WireGuard) or the client-config-dir + live sessions
                // (OpenVPN).
                $service->driver()->applyServiceConfig($service);

                // applyServiceConfig does not touch tc shaping; keep per-user
                // classes in step with the now-current active set.
                app(TrafficShaper::class)->apply($service);
            } catch (Throwable $e) {
                // One broken service must not stop the lockdown reaching the
                // rest -- record it and carry on, exactly as the other
                // enforcement jobs do.
                $service->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }
    }

    private function suspend(Service $service): bool
    {
        $changed = false;

        foreach ($service->serviceUsers()->where('status', ServiceUserStatus::Active)->get() as $user) {
            $user->forceFill([
                'status' => ServiceUserStatus::Suspended,
                'suspended_reason' => self::REASON,
            ])->save();

            $changed = true;
        }

        return $changed;
    }

    private function restore(Service $service): bool
    {
        $changed = false;

        $users = $service->serviceUsers()
            ->where('status', ServiceUserStatus::Suspended)
            ->where('suspended_reason', self::REASON)
            ->get();

        foreach ($users as $user) {
            // Lockdown may have masked another limit that came due while it was
            // engaged. Rather than let such a user straight back in for the next
            // enforcement run to cut off again, hand the reason over and leave
            // them suspended -- the same handover EnforceAccessWindows performs.
            $reason = match (true) {
                $user->isExpired() => 'expired',
                $user->isOverDataLimit() => 'data limit reached',
                ! $user->isWithinAccessWindow() => EnforceAccessWindows::REASON,
                default => null,
            };

            if ($reason !== null) {
                $user->forceFill(['suspended_reason' => $reason])->save();

                continue;
            }

            $user->forceFill([
                'status' => ServiceUserStatus::Active,
                'suspended_reason' => null,
            ])->save();

            $changed = true;
        }

        return $changed;
    }
}
