<?php

namespace App\Console\Commands\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * WireGuard's NAT rules are reapplied automatically on every boot -- they
 * live in the config's own PostUp lines, which wg-quick@<iface>.service
 * (run by systemd as real root, independent of this worker) interprets
 * itself on every start. OpenVPN's config format has no PostUp equivalent,
 * so its NAT rule is only ever applied by OpenVpnDriver::writeServerConfig()
 * at provision/apply time -- with nothing to reapply it, a reboot leaves an
 * OpenVPN service running but unable to route its clients' traffic anywhere.
 * Run once at worker startup (see vpnforge-worker.service.tpl's
 * ExecStartPre) to close that gap.
 *
 * tc-based per-user speed limits are the other thing a reboot forgets -- they
 * live only in the kernel, on interfaces (WireGuard included) this command does
 * not otherwise touch -- so they are re-applied here for every active service.
 */
#[Signature('vpn:restore-after-boot')]
#[Description('Re-apply state that does not survive a reboot on its own (OpenVPN NAT rules, per-user speed limits)')]
class RestoreAfterBoot extends Command
{
    public function handle(TrafficShaper $shaper): int
    {
        Service::query()
            ->where('protocol', VpnProtocol::OpenVpn)
            ->where('status', ServiceStatus::Active)
            ->get()
            ->each(function (Service $service) {
                try {
                    $service->driver()->applyServiceConfig($service);
                    $this->info("Restored service {$service->id} ({$service->interface_name}).");
                } catch (Throwable $e) {
                    // Never let one problem service block the others, or
                    // block the worker itself from starting (this command
                    // runs as an ExecStartPre on vpnforge-worker.service).
                    $this->error("Failed to restore service {$service->id}: {$e->getMessage()}");
                }
            });

        // Both protocols: re-apply speed limits. Tolerant of an interface that
        // is not up yet at this point in boot (a WireGuard tunnel systemd is
        // still bringing back) -- that service simply gets its limits on its
        // next config change rather than blocking the worker from starting.
        Service::query()
            ->where('status', ServiceStatus::Active)
            ->get()
            ->each(function (Service $service) use ($shaper) {
                try {
                    $shaper->apply($service);
                } catch (Throwable $e) {
                    $this->error("Could not re-apply speed limits for service {$service->id}: {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
