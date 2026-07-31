<?php

namespace App\Services\Vpn\WireGuard;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\ClientConfigFile;
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
 * execs (wg, wg-quick, iptables).
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

            Process::run(['wg-quick', 'up', $service->interface_name])->throw();
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

        // wg-quick-only keys (Address/PostUp/PreDown/...) have to be
        // stripped before handing the file to `wg syncconf`, which only
        // understands the [Interface]/[Peer] fields the kernel module
        // itself knows about. PostUp/PreDown therefore only take effect on
        // a full wg-quick up/down cycle, not here -- that's expected.
        $stripped = Process::run(['wg-quick', 'strip', $this->confPath($service)])->throw()->output();

        $tmpPath = tempnam(sys_get_temp_dir(), 'wgsync');
        File::put($tmpPath, $stripped);

        try {
            Process::run(['wg', 'syncconf', $service->interface_name, $tmpPath])->throw();
        } finally {
            File::delete($tmpPath);
        }
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
        $dns = implode(', ', $config['dns'] ?? ['1.1.1.1', '1.0.0.1']);
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
            Process::run(['systemctl', 'disable', '--now', "wg-quick@{$service->interface_name}"])->run();
            Process::run(['wg-quick', 'down', $service->interface_name])->run();
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
