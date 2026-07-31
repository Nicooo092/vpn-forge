<?php

namespace App\Services\Vpn\WireGuard;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\ClientConfigFile;
use App\Services\Vpn\DnsmasqManager;
use App\Services\Vpn\PeerStatus;
use App\Services\Vpn\VpnProtocolDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Every command here runs in-process inside the privileged queue worker
 * (systemd AmbientCapabilities=CAP_NET_ADMIN) -- none of it shells out to
 * `sudo`, since the worker process itself already holds the capability it
 * needs, and ambient capabilities are inherited by the child processes it
 * execs (wg, iptables).
 *
 * Deliberately NOT `wg-quick` for any of that: wg-quick's up/down/strip/save
 * subcommands all call an internal auto_su() that unconditionally re-execs
 * itself via `sudo` whenever $UID != 0, regardless of whether the caller
 * already holds every capability the operation actually needs -- and
 * NoNewPrivileges=yes on the worker's systemd unit (needed so nothing else
 * can gain privilege it wasn't explicitly granted) means that sudo call can
 * never succeed. So the handful of things wg-quick would otherwise do --
 * create the interface, load the config, add the address, apply the NAT
 * rules -- are done directly here instead. wg-quick@<iface>.service is
 * still enabled (see provisionService()) purely so systemd -- running as
 * real root at boot, never through this worker -- can bring the interface
 * back up after a reboot; that path never hits auto_su's sudo call in the
 * first place, since $UID is already 0 there.
 */
class WireGuardDriver implements VpnProtocolDriver
{
    private const CONFIG_DIR = '/etc/wireguard';

    public function provisionService(Service $service): void
    {
        $confPath = $this->confPath($service);

        if (! File::exists($confPath)) {
            [$privateKey, $publicKey] = $this->generateKeypair();

            $config = $service->config ?? [];
            $config['server_public_key'] = $publicKey;
            $service->config = $config;
            $service->save();

            $this->writeConfig($service, $privateKey);

            Process::run(['ip', 'link', 'add', $service->interface_name, 'type', 'wireguard'])->throw();
            $this->syncConfFromDisk($service);
            Process::run(['ip', 'address', 'add', $this->gatewayAddress($service).'/'.$this->prefixLength($service), 'dev', $service->interface_name])->throw();

            $mtu = (string) ($config['mtu'] ?? 1420);
            Process::run(['ip', 'link', 'set', 'mtu', $mtu, 'up', 'dev', $service->interface_name])->throw();

            $this->applyNatRules($service, add: true);

            // Enabled (not started -- the interface is already up, done
            // manually above) purely so a reboot brings it back: systemd
            // runs wg-quick as root directly, so auto_su's sudo call is a
            // no-op there and this is the one context where wg-quick itself
            // is fine to rely on.
            Process::run(['systemctl', 'enable', "wg-quick@{$service->interface_name}"])->throw();
        }

        $service->status = ServiceStatus::Active;
        $service->save();
    }

    public function applyServiceConfig(Service $service): void
    {
        $privateKey = $this->readServerPrivateKey($service);

        if ($privateKey === null) {
            throw new RuntimeException(
                "No existing WireGuard config found for {$service->interface_name}; provisionService() must run first."
            );
        }

        $this->writeConfig($service, $privateKey);
        $this->syncConfFromDisk($service);
    }

    public function addUser(Service $service, ServiceUser $user): void
    {
        [$privateKey, $publicKey] = $this->generateKeypair();

        $user->wg_private_key = $privateKey;
        $user->wg_public_key = $publicKey;
        $user->wg_preshared_key = $this->generatePresharedKey();
        $user->save();

        // WireGuard peers only take effect once synced into the running
        // interface -- unlike OpenVPN's driver, which needs no such step.
        // Kept here (not in the Job layer) so callers never have to branch
        // on protocol to know whether a follow-up apply is needed.
        $this->applyServiceConfig($service);
    }

    public function rotateUserKeys(Service $service, ServiceUser $user): void
    {
        [$privateKey, $publicKey] = $this->generateKeypair();

        $user->wg_private_key = $privateKey;
        $user->wg_public_key = $publicKey;
        $user->wg_preshared_key = $this->generatePresharedKey();
        $user->key_rotations = $user->key_rotations + 1;
        $user->issued_at = now();
        $user->save();

        // Syncing the interface drops the old public key from the peer list,
        // so the previous config stops authenticating immediately rather
        // than lingering until its next handshake.
        $this->applyServiceConfig($service);
    }

    public function removeUser(Service $service, ServiceUser $user): void
    {
        // No certificate/key revocation step exists for WireGuard -- simply
        // re-syncing the interface is enough, since a revoked user's
        // [Peer] block won't be rendered by writeConfig() any more.
        $this->applyServiceConfig($service);
    }

    public function buildClientConfig(Service $service, ServiceUser $user): ClientConfigFile
    {
        $config = $service->config ?? [];

        // Point the client at this service's own dnsmasq instance (the .1 of
        // the tunnel subnet) rather than straight at a public resolver.
        // dnsmasq is provisioned for every service and forwards on to the
        // configured upstreams, so resolution is unchanged -- but sending the
        // queries through it is the entire basis of DNS traffic logging. With
        // a public resolver here the client never talks to dnsmasq at all and
        // the capture agent sees nothing, which is exactly how this shipped.
        // Same trap as the resolver's upstream list: an empty array is not
        // caught by `?? default`, and rendering `DNS = ` with nothing after
        // it leaves the client with no resolver at all.
        $customDns = collect($config['dns'] ?? [])
            ->map(fn ($server) => trim((string) $server))
            ->filter()
            ->values();

        $dns = ($config['push_dns'] ?? true)
            ? $this->gatewayAddress($service)
            : implode(', ', $customDns->isEmpty() ? DnsmasqManager::DEFAULT_UPSTREAMS : $customDns->all());

        $mtu = $config['mtu'] ?? 1420;
        $allowedIps = $config['client_allowed_ips'] ?? '0.0.0.0/0, ::/0';
        $keepalive = $config['keepalive'] ?? 25;
        $endpointHost = $config['endpoint_host'] ?? 'CHANGE-ME.example.com';
        $serverPublicKey = $config['server_public_key'] ?? '';

        $contents = <<<CONF
        [Interface]
        PrivateKey = {$user->wg_private_key}
        Address = {$user->tunnel_ip}/32
        DNS = {$dns}
        MTU = {$mtu}

        [Peer]
        PublicKey = {$serverPublicKey}
        PresharedKey = {$user->wg_preshared_key}
        Endpoint = {$endpointHost}:{$service->listen_port}
        AllowedIPs = {$allowedIps}
        PersistentKeepalive = {$keepalive}

        CONF;

        return new ClientConfigFile("{$service->interface_name}-{$user->name}.conf", $contents);
    }

    public function pollStatus(Service $service): array
    {
        $output = Process::run(['wg', 'show', $service->interface_name, 'dump'])->throw()->output();

        $lines = array_values(array_filter(explode("\n", trim($output))));

        // The first line describes the interface itself
        // (private-key public-key listen-port fwmark) -- not a peer.
        array_shift($lines);

        $statuses = [];

        foreach ($lines as $line) {
            // public-key preshared-key endpoint allowed-ips latest-handshake rx tx keepalive
            $fields = array_pad(explode("\t", $line), 8, null);
            [, , $endpoint, $allowedIps, $latestHandshake, $rx, $tx] = $fields;

            $tunnelIp = explode('/', explode(',', (string) $allowedIps)[0] ?: '')[0] ?? '';

            if ($tunnelIp === '') {
                continue;
            }

            $handshakeAt = ((int) $latestHandshake) > 0
                ? CarbonImmutable::createFromTimestamp((int) $latestHandshake)
                : null;

            // WireGuard has no real connect/disconnect event -- a peer
            // counts as "connected" if it handshook within roughly 3x the
            // usual keepalive window.
            $connected = $handshakeAt !== null
                && $handshakeAt->greaterThan(CarbonImmutable::now()->subMinutes(3));

            $peerIp = $endpoint && $endpoint !== '(none)'
                ? explode(':', (string) $endpoint)[0]
                : null;

            $statuses[$tunnelIp] = new PeerStatus(
                tunnelIp: $tunnelIp,
                connected: $connected,
                lastHandshakeAt: $handshakeAt,
                peerIp: $peerIp,
                bytesIn: (int) $rx,
                bytesOut: (int) $tx,
            );
        }

        return $statuses;
    }

    public function removeService(Service $service): void
    {
        $confPath = $this->confPath($service);

        if (File::exists($confPath)) {
            // Disable only (not --now): there is nothing for systemd to
            // stop, since the interface was brought up directly rather
            // than via `systemctl start wg-quick@...` -- this just prevents
            // it from being brought back up on the next boot.
            Process::run(['systemctl', 'disable', "wg-quick@{$service->interface_name}"])->run();
            $this->applyNatRules($service, add: false);
            Process::run(['ip', 'link', 'delete', $service->interface_name])->run();
            File::delete($confPath);
        }
    }

    /**
     * @return array{0: string, 1: string} [privateKey, publicKey]
     */
    private function generateKeypair(): array
    {
        $privateKey = trim(Process::run(['wg', 'genkey'])->throw()->output());
        $publicKey = trim(Process::input($privateKey)->run(['wg', 'pubkey'])->throw()->output());

        return [$privateKey, $publicKey];
    }

    private function generatePresharedKey(): string
    {
        return trim(Process::run(['wg', 'genpsk'])->throw()->output());
    }

    private function confPath(Service $service): string
    {
        return self::CONFIG_DIR."/{$service->interface_name}.conf";
    }

    private function readServerPrivateKey(Service $service): ?string
    {
        $path = $this->confPath($service);

        if (! File::exists($path)) {
            return null;
        }

        foreach (explode("\n", File::get($path)) as $line) {
            if (str_starts_with(trim($line), 'PrivateKey')) {
                return trim(explode('=', $line, 2)[1]);
            }
        }

        return null;
    }

    /**
     * Re-reads the just-written config from disk, strips the wg-quick-only
     * directives (Address/MTU/PostUp/PreDown/... -- kept in the file itself
     * for wg-quick's own benefit at boot, but not understood by plain
     * `wg(8)`), and hands the result to `wg syncconf` -- the non-wg-quick
     * equivalent of `wg-quick strip <conf> | wg syncconf <iface> -`.
     */
    private function syncConfFromDisk(Service $service): void
    {
        $stripped = $this->stripWgQuickDirectives(File::get($this->confPath($service)));

        $tmpPath = tempnam(sys_get_temp_dir(), 'wgsync');
        File::put($tmpPath, $stripped);

        try {
            Process::run(['wg', 'syncconf', $service->interface_name, $tmpPath])->throw();
        } finally {
            File::delete($tmpPath);
        }
    }

    /**
     * Mirrors wg-quick's cmd_strip: drop every line whose key is one of
     * wg-quick's own directives (case-insensitive), keep everything else
     * (section headers, comments, and every field plain `wg(8)` itself
     * understands) untouched.
     */
    private function stripWgQuickDirectives(string $contents): string
    {
        $wgQuickOnlyKeys = ['address', 'dns', 'mtu', 'table', 'preup', 'predown', 'postup', 'postdown', 'saveconfig'];

        $lines = array_filter(explode("\n", $contents), function (string $line) use ($wgQuickOnlyKeys) {
            $key = strtolower(trim(explode('=', $line, 2)[0] ?? ''));

            return ! in_array($key, $wgQuickOnlyKeys, true);
        });

        return implode("\n", $lines);
    }

    /**
     * The same NAT rules wg-quick would otherwise apply via the config's
     * own PostUp/PreDown lines (see writeConfig()) -- applied directly here
     * since this driver no longer relies on wg-quick to interpret them for
     * on-demand actions (only the reboot path still does, via systemd).
     * `-C` checks whether a rule already exists so both directions are
     * idempotent: re-provisioning won't duplicate it, and tearing down an
     * already-torn-down service won't error trying to remove what's gone.
     */
    private function applyNatRules(Service $service, bool $add): void
    {
        $egressInterface = ($service->config ?? [])['egress_interface'] ?? 'eth0';

        // [table args, chain, rule-specification] -- the operation flag
        // (-C/-A/-D) has to sit directly before the chain name, so it's
        // spliced in separately below rather than folded into one flat
        // argv list per rule.
        $rules = [
            [['-t', 'nat'], 'POSTROUTING', ['-s', $service->subnet_cidr, '-o', $egressInterface, '-j', 'MASQUERADE']],
            [[], 'FORWARD', ['-i', $service->interface_name, '-j', 'ACCEPT']],
            [[], 'FORWARD', ['-o', $service->interface_name, '-j', 'ACCEPT']],
        ];

        foreach ($rules as [$tableArgs, $chain, $ruleArgs]) {
            $ruleExists = Process::run(['iptables', ...$tableArgs, '-C', $chain, ...$ruleArgs])->successful();

            if ($add && ! $ruleExists) {
                Process::run(['iptables', ...$tableArgs, '-A', $chain, ...$ruleArgs])->throw();
            } elseif (! $add && $ruleExists) {
                Process::run(['iptables', ...$tableArgs, '-D', $chain, ...$ruleArgs])->throw();
            }
        }
    }

    private function writeConfig(Service $service, string $privateKey): void
    {
        $config = $service->config ?? [];
        $mtu = $config['mtu'] ?? 1420;
        $egressInterface = $config['egress_interface'] ?? 'eth0';

        $lines = [
            '[Interface]',
            "PrivateKey = {$privateKey}",
            'Address = '.$this->gatewayAddress($service).'/'.$this->prefixLength($service),
            "ListenPort = {$service->listen_port}",
            "MTU = {$mtu}",
            "PostUp = iptables -t nat -A POSTROUTING -s {$service->subnet_cidr} -o {$egressInterface} -j MASQUERADE; iptables -A FORWARD -i {$service->interface_name} -j ACCEPT; iptables -A FORWARD -o {$service->interface_name} -j ACCEPT",
            "PreDown = iptables -t nat -D POSTROUTING -s {$service->subnet_cidr} -o {$egressInterface} -j MASQUERADE; iptables -D FORWARD -i {$service->interface_name} -j ACCEPT; iptables -D FORWARD -o {$service->interface_name} -j ACCEPT",
            '',
        ];

        $activeUsers = $service->serviceUsers()->where('status', ServiceUserStatus::Active)->get();

        foreach ($activeUsers as $user) {
            $lines[] = '[Peer]';
            $lines[] = "# {$user->name}";
            $lines[] = "PublicKey = {$user->wg_public_key}";
            $lines[] = "PresharedKey = {$user->wg_preshared_key}";
            $lines[] = "AllowedIPs = {$user->tunnel_ip}/32";
            $lines[] = '';
        }

        $contents = implode("\n", $lines);
        $confPath = $this->confPath($service);
        $tmpPath = "{$confPath}.new";

        File::put($tmpPath, $contents);
        chmod($tmpPath, 0600);
        rename($tmpPath, $confPath);
    }

    private function gatewayAddress(Service $service): string
    {
        // The first usable host address in the service's subnet is used as
        // the interface's own address.
        $network = explode('/', $service->subnet_cidr)[0];
        $parts = explode('.', $network);
        $parts[3] = '1';

        return implode('.', $parts);
    }

    private function prefixLength(Service $service): string
    {
        return explode('/', $service->subnet_cidr)[1] ?? '24';
    }
}
