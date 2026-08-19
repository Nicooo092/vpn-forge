<?php

namespace App\Services\Vpn;

use App\Models\Service;
use App\Services\Blocklist\BlocklistCompiler;
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

    /**
     * @var list<string>
     */
    public const DEFAULT_UPSTREAMS = ['1.1.1.1', '1.0.0.1'];

    public function provision(Service $service): void
    {
        File::ensureDirectoryExists(self::CONFIG_DIR, 0755);
        File::ensureDirectoryExists(self::LOG_DIR, 0755);

        // The resolver's config references the shared blocklist file via
        // addn-hosts; make sure it exists (empty is fine) so dnsmasq never
        // fails to start over a missing file, even before any list is fetched.
        app(BlocklistCompiler::class)->ensureFile();

        $logPath = $this->logPath($service);

        // Truncate rather than append across re-provisions, so a
        // re-applied service doesn't inherit a stale log file with the
        // wrong ownership/permissions.
        if (! File::exists($logPath)) {
            File::put($logPath, '');
        }

        File::put($this->configPath($service), $this->buildConfig($service));

        $unit = "vpnforge-dnsmasq@{$service->interface_name}";
        Process::run(['systemctl', 'enable', '--now', $unit])->throw();
        Process::run(['systemctl', 'restart', $unit])->throw();
    }

    public function deprovision(Service $service): void
    {
        $unit = "vpnforge-dnsmasq@{$service->interface_name}";
        // Best effort: the unit may already be stopped. Process::run() has
        // already executed the command -- calling ->run() on its result would
        // be a fatal "undefined method" and abort the whole teardown.
        Process::run(['systemctl', 'disable', '--now', $unit]);
        File::delete($this->configPath($service));
    }

    /**
     * Kept separate from provision() so the generated directives can be
     * asserted on without writing to /etc or restarting a unit.
     */
    public function buildConfig(Service $service): string
    {
        // dnsmasq reads this file line by line, so any value that reaches it
        // with a newline in the middle injects an arbitrary directive
        // (DNS spoofing via address=/bank/, arbitrary file writes via
        // log-facility=). NetworkInput keeps only genuine IPs / hostnames,
        // which by construction contain no newline, space or slash.
        NetworkInput::assertInterfaceName($service->interface_name);

        $gatewayIp = $this->gatewayAddress($service);
        $logPath = $this->logPath($service);

        // Only valid resolver IPs survive. An empty list -- the field was
        // cleared, or everything in it was rejected -- falls back to the
        // defaults rather than leaving dnsmasq with no upstream at all, which
        // combined with no-resolv would make it fail every lookup.
        $upstreams = collect(NetworkInput::filterUpstreams($service->config['dns_upstreams'] ?? []));

        if ($upstreams->isEmpty()) {
            $upstreams = collect(self::DEFAULT_UPSTREAMS);
        }

        $upstreams = $upstreams
            ->map(fn (string $server) => "server={$server}")
            ->implode("\n");

        // address=/example.com/ with no address answers NXDOMAIN for the name
        // and everything under it, so one entry covers the subdomains a site
        // actually loads from. Only real hostnames survive the filter.
        $blocked = collect(NetworkInput::filterBlockedDomains($service->config['blocked_domains'] ?? []))
            ->map(fn (string $domain) => "address=/{$domain}/")
            ->implode("\n");

        // Subscription blocklists are compiled to one shared hosts file and
        // pulled in with addn-hosts, unless this service opts out. A fixed
        // path whose contents change, so refreshing a list never rewrites this
        // config -- it just re-reads the file. no-hosts above only disables
        // /etc/hosts, not addn-hosts.
        $blocklist = ($service->config['use_blocklists'] ?? true)
            ? 'addn-hosts='.BlocklistCompiler::FILE
            : '';

        return <<<CONF
        interface={$service->interface_name}
        bind-interfaces
        except-interface=lo
        listen-address={$gatewayIp}
        no-dhcp-interface={$service->interface_name}
        no-resolv
        no-hosts
        {$upstreams}
        {$blocked}
        {$blocklist}
        log-queries=extra
        log-facility={$logPath}

        CONF;
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
