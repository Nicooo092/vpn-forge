<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Vpn\DnsmasqManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs on the `system` queue, consumed only by the privileged
 * vpnforge-worker process (CAP_NET_ADMIN via systemd). The unprivileged web
 * process only ever dispatches this -- it never touches the network stack
 * itself.
 */
class ProvisionService implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public Service $service)
    {
        $this->onQueue('system');
    }

    public function handle(DnsmasqManager $dnsmasq): void
    {
        try {
            $this->service->driver()->provisionService($this->service);
            $dnsmasq->provision($this->service);

            $this->service->forceFill([
                'status' => ServiceStatus::Active,
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $this->service->forceFill([
                'status' => ServiceStatus::Error,
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
