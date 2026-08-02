<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Caps how many devices may use one config at once. OpenVPN only: a WireGuard
 * peer is a single key, single-session by design, so there is nothing to count
 * or trim there. OpenVPN only allows more than one connection per certificate
 * at all when duplicate-cn is on -- otherwise the second connection is refused
 * by the server itself and this never sees an excess.
 *
 * Runs every minute against the management interface. It keeps the oldest
 * connections and drops the newest ones over the limit, so an established
 * session is not the one cut when a new device pushes past the cap.
 */
class EnforceDeviceLimits implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $services = Service::query()
            ->where('status', ServiceStatus::Active)
            ->where('protocol', VpnProtocol::OpenVpn)
            ->get();

        foreach ($services as $service) {
            try {
                $this->enforce($service);
            } catch (Throwable) {
                // The management socket may be momentarily unavailable; the
                // next minute's run picks it up. Never mark the service errored
                // over a transient trim.
            }
        }
    }

    private function enforce(Service $service): void
    {
        $capped = $service->serviceUsers()
            ->whereNotNull('max_connections')
            ->whereNotNull('openvpn_common_name')
            ->get()
            ->keyBy('openvpn_common_name');

        if ($capped->isEmpty()) {
            return;
        }

        $driver = app(OpenVpnDriver::class);
        $connections = $driver->connectionsByCommonName($service);

        foreach ($capped as $commonName => $user) {
            $conns = $connections[$commonName] ?? [];
            $max = max(1, (int) $user->max_connections);

            if (count($conns) <= $max) {
                continue;
            }

            // Oldest first, keep $max, drop the newest excess.
            usort($conns, fn ($a, $b) => $a['connected_since'] <=> $b['connected_since']);

            foreach (array_slice($conns, $max) as $connection) {
                try {
                    $driver->killConnection($service, $connection['real_address']);
                } catch (Throwable) {
                    // Already gone, or socket wedged -- retried next tick.
                }
            }
        }
    }
}
