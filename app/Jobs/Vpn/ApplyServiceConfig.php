<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Services\Vpn\DnsmasqManager;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyServiceConfig implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public Service $service)
    {
        $this->onQueue('system');
    }

    public function handle(DnsmasqManager $dnsmasq, TrafficShaper $shaper): void
    {
        try {
            $this->service->driver()->applyServiceConfig($this->service);

            // The resolver's own settings -- upstream servers, blocked
            // domains -- live in the service config too, and nothing here
            // used to re-render them, so editing either changed the stored
            // value and nothing else until the service was re-provisioned.
            $dnsmasq->provision($this->service);

            $this->service->forceFill([
                'status' => ServiceStatus::Active,
                'last_error' => null,
            ])->save();

            // A service-level change (DNS, AllowedIPs, endpoint host, ...)
            // can change what each user's client config should contain --
            // re-cache every still-valid user's config rather than leaving
            // stale copies around from before this change.
            $this->service->serviceUsers()
                ->where('status', ServiceUserStatus::Active)
                ->get()
                ->each(fn ($user) => $user->refreshRenderedClientConfig());

            // Bring per-user speed limits into line with the current user set.
            // With no user limited this is a no-op teardown, so an unshaped
            // service is never touched.
            $shaper->apply($this->service);
        } catch (Throwable $e) {
            $this->service->forceFill([
                'status' => ServiceStatus::Error,
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
