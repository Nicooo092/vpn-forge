<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $toSuspend = $service->serviceUsers()
            ->where('status', ServiceUserStatus::Active)
            ->get()
            ->map(function (ServiceUser $user): ?ServiceUser {
                $reason = match (true) {
                    $user->isExpired() => 'expired',
                    $user->isOverDataLimit() => 'data limit reached',
                    default => null,
                };

                if ($reason === null) {
                    return null;
                }

                $user->forceFill([
                    'status' => ServiceUserStatus::Suspended,
                    'suspended_reason' => $reason,
                ])->save();

                return $user;
            })
            ->filter();

        if ($toSuspend->isEmpty()) {
            return;
        }

        // One re-apply for the whole batch rather than one per user: for
        // WireGuard this rewrites the peer list, for OpenVPN it writes the
        // client-config-dir entries and cuts the live sessions.
        $service->driver()->applyServiceConfig($service);
    }
}
