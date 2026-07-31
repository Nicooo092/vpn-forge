<?php

namespace App\Jobs\Vpn;

use App\Models\Service;
use App\Services\Vpn\DnsmasqManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Full teardown + deletion. The web process can't do this itself (it lacks
 * CAP_NET_ADMIN), so ServicesTable's "Remove" action dispatches this instead
 * of using Eloquent's delete() directly -- the record is only actually
 * removed from the database once the underlying interface/NAT/systemd
 * teardown has completed.
 */
class RemoveService implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Service $service)
    {
        $this->onQueue('system');
    }

    public function handle(DnsmasqManager $dnsmasq): void
    {
        $this->service->driver()->removeService($this->service);
        $dnsmasq->deprovision($this->service);
        $this->service->delete();
    }
}
