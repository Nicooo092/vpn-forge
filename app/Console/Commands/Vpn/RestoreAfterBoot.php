<?php

namespace App\Console\Commands\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\VpnProtocol;
use App\Models\Service;
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
 */
#[Signature('vpn:restore-after-boot')]
#[Description('Re-apply state that does not survive a reboot on its own (currently: OpenVPN NAT rules)')]
class RestoreAfterBoot extends Command
{
    public function handle(): int
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

        return self::SUCCESS;
    }
}
