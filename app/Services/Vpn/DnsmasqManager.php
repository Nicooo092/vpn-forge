<?php

namespace App\Services\Vpn;

use App\Models\Service;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * One dnsmasq instance per service, bound only to that service's
 * tunnel-side gateway address, giving the capture agent DNS-level domain
 * visibility (including for HTTPS sites, since it's based on the query,
 * not on decrypting traffic). Deliberately protocol-agnostic -- both
 * WireGuardDriver and OpenVpnDriver call this the same way from their
 * provisionService(), rather than each reimplementing it.
 */
class DnsmasqManager
{
    private const CONFIG_DIR = '/etc/vpnforge/dnsmasq';

    private const LOG_DIR = '/var/log/vpnforge';

    public function provision(Service $service): void
    {
        File::ensureDirectoryExists(self::CONFIG_DIR, 0755);
        File::ensureDirectoryExists(self::LOG_DIR, 0755);

        $gatewayIp = $this->gatewayAddress($service);
        $logPath = $this->logPath($service);

        $upstreams = collect($service->config['dns_upstreams'] ?? ['1.1.1.1', '1.0.0.1'])
            ->filter()
            ->map(fn (string $server) => "server={$server}")
            ->implode("\n");

        // Truncate rather than append across re-provisions, so a
        // re-applied service doesn't inherit a stale log file with the
        // wrong ownership/permissions.
        if (! File::exists($logPath)) {
            File::put($logPath, '');
        }

        $contents = <<<CONF
        interface={$service->interface_name}
        bind-interfaces
        except-interface=lo
        listen-address={$gatewayIp}
        no-dhcp-interface={$service->interface_name}
        no-resolv
        no-hosts
        {$upstreams}
        log-queries=extra
        log-facility={$logPath}

        CONF;

        File::put($this->configPath($service), $contents);

        $unit = "vpnforge-dnsmasq@{$service->interface_name}";
        Process::run(['systemctl', 'enable', '--now', $unit])->throw();
        Process::run(['systemctl', 'restart', $unit])->throw();
    }

    public function deprovision(Service $service): void
    {
        $unit = "vpnforge-dnsmasq@{$service->interface_name}";
        Process::run(['systemctl', 'disable', '--now', $unit])->run();
        File::delete($this->configPath($service));
    }

    public function configPath(Service $service): string
    {
        return self::CONFIG_DIR."/{$service->interface_name}.conf";
    }

    public function logPath(Service $service): string
    {
        return self::LOG_DIR."/dns-{$service->interface_name}.log";
    }

    private function gatewayAddress(Service $service): string
    {
        $network = explode('/', $service->subnet_cidr)[0];
        $parts = explode('.', $network);
        $parts[3] = '1';

        return implode('.', $parts);
    }
}
