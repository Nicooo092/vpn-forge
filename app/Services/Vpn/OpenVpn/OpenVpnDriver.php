<?php

namespace App\Services\Vpn\OpenVpn;

use App\Enums\ServiceStatus;
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
 * One fully separate easy-rsa PKI per service (not one shared CA) --
 * preserves the same per-service isolation WireGuard gets from per-service
 * keys, and makes deleting a service a clean directory removal.
 *
 * Like WireGuardDriver, every command here runs in-process inside the
 * privileged queue worker (CAP_NET_ADMIN via systemd AmbientCapabilities) --
 * no `sudo` involved.
 *
 * NOTE: exact easy-rsa/OpenVPN CLI spelling and the management-interface
 * command names below were written against documented conventions and are
 * flagged in the project plan as "verify at implementation time" -- they
 * need to be checked against the OpenVPN/easy-rsa versions Ubuntu 24.04
 * actually packages during the live install test, and adjusted if the
 * packaged version differs.
 */
class OpenVpnDriver implements VpnProtocolDriver
{
    private const BASE_DIR = '/etc/openvpn/vpnforge';

    private const EASYRSA_SOURCE = '/usr/share/easy-rsa';

    public function provisionService(Service $service): void
    {
        $dir = $this->serviceDir($service);
        $pkiDir = "{$dir}/pki";

        if (! File::isDirectory($pkiDir)) {
            File::ensureDirectoryExists($dir, 0750);
            $this->copyEasyRsaInto($dir);

            $env = ['EASYRSA_BATCH' => '1'];

            Process::path($dir)->run(['./easyrsa', 'init-pki'])->throw();
            Process::path($dir)->env($env)->run(['./easyrsa', 'build-ca', 'nopass'])->throw();
            Process::path($dir)->env($env)->run(['./easyrsa', 'build-server-full', 'server', 'nopass'])->throw();
            Process::path($dir)->run(['openvpn', '--genkey', 'secret', "{$dir}/ta.key"])->throw();
            Process::path($dir)->env($env)->run(['./easyrsa', 'gen-crl'])->throw();
            File::copy("{$pkiDir}/crl.pem", "{$dir}/crl.pem");

            $this->writeServerConfig($service);

            Process::run(['systemctl', 'enable', '--now', "openvpn-server@{$this->unitName($service)}"])->throw();
        }

        $service->status = ServiceStatus::Active;
        $service->save();
    }

    public function applyServiceConfig(Service $service): void
    {
        $this->writeServerConfig($service);

        // Unlike WireGuard, OpenVPN has no equivalent of `wg syncconf` for
        // server-level settings (cipher, keepalive, push routes, ...) --
        // those are only read at startup, so changing them means a real
        // restart, briefly dropping any connected clients. Adding/removing
        // individual users does NOT require this (see addUser/removeUser).
        Process::run(['systemctl', 'restart', "openvpn-server@{$this->unitName($service)}"])->throw();
    }

    public function addUser(Service $service, ServiceUser $user): void
    {
        $dir = $this->serviceDir($service);
        $safeCn = $this->safeCommonName($service, $user);

        Process::path($dir)->env(['EASYRSA_BATCH' => '1'])
            ->run(['./easyrsa', 'build-client-full', $safeCn, 'nopass'])
            ->throw();

        $user->openvpn_common_name = $safeCn;
        $user->certificate_serial = $this->readCertSerial($dir, $safeCn);
        $user->issued_at = now();
        $user->save();

        // No server reload needed: OpenVPN validates client certs against
        // ca.crt + the CRL dynamically, per connection.
    }

    public function removeUser(Service $service, ServiceUser $user): void
    {
        $dir = $this->serviceDir($service);
        $cn = $user->openvpn_common_name;

        if ($cn === null) {
            return;
        }

        Process::path($dir)->env(['EASYRSA_BATCH' => '1'])->run(['./easyrsa', 'revoke', $cn])->throw();
        Process::path($dir)->env(['EASYRSA_BATCH' => '1'])->run(['./easyrsa', 'gen-crl'])->throw();
        File::copy("{$dir}/pki/crl.pem", "{$dir}/crl.pem");

        $user->revoked_at = now();
        $user->save();

        // The CRL is only re-checked by OpenVPN on new connections / TLS
        // renegotiation (default reneg-sec 3600), so an already-connected
        // client could otherwise stay connected up to an hour after
        // revocation -- kick it immediately via the management socket
        // instead of waiting.
        try {
            $this->managementCommand($service, "kill {$cn}");
        } catch (RuntimeException) {
            // Management socket unreachable (e.g. server not running) or
            // the client wasn't connected -- nothing more to do either way.
        }
    }

    public function buildClientConfig(Service $service, ServiceUser $user): ClientConfigFile
    {
        $dir = $this->serviceDir($service);
        $config = $service->config ?? [];
        $endpointHost = $config['endpoint_host'] ?? 'CHANGE-ME.example.com';
        $dataCiphers = $config['data_ciphers'] ?? 'AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305';

        $ca = File::get("{$dir}/pki/ca.crt");
        $cert = $this->extractCertBlock(File::get("{$dir}/pki/issued/{$user->openvpn_common_name}.crt"));
        $key = File::get("{$dir}/pki/private/{$user->openvpn_common_name}.key");
        $taKey = File::get("{$dir}/ta.key");

        $contents = <<<OVPN
        client
        dev tun
        proto {$service->transport->value}
        remote {$endpointHost} {$service->listen_port}
        resolv-retry infinite
        nobind
        persist-key
        persist-tun
        remote-cert-tls server
        data-ciphers {$dataCiphers}
        auth SHA256
        verb 3

        <ca>
        {$ca}</ca>
        <cert>
        {$cert}
        </cert>
        <key>
        {$key}</key>
        <tls-crypt>
        {$taKey}</tls-crypt>

        OVPN;

        return new ClientConfigFile("{$service->interface_name}-{$user->name}.ovpn", $contents);
    }

    public function pollStatus(Service $service): array
    {
        try {
            $response = $this->managementCommand($service, 'status 2');
        } catch (RuntimeException) {
            return [];
        }

        // status 2 emits comma-separated CLIENT_LIST lines:
        // CLIENT_LIST,<common_name>,<real_address>,<virtual_address>,...,<bytes_received>,<bytes_sent>,<connected_since>,...
        $statuses = [];

        foreach (explode("\n", $response) as $line) {
            if (! str_starts_with($line, 'CLIENT_LIST,')) {
                continue;
            }

            $fields = str_getcsv($line);
            $commonName = $fields[1] ?? null;
            $realAddress = $fields[2] ?? null;
            $tunnelIp = $fields[3] ?? null;
            $bytesReceived = (int) ($fields[4] ?? 0);
            $bytesSent = (int) ($fields[5] ?? 0);

            if ($tunnelIp === null || $tunnelIp === '') {
                continue;
            }

            $peerIp = $realAddress ? explode(':', $realAddress)[0] : null;

            $statuses[$tunnelIp] = new PeerStatus(
                tunnelIp: $tunnelIp,
                connected: true,
                lastHandshakeAt: CarbonImmutable::now(),
                peerIp: $peerIp,
                bytesIn: $bytesReceived,
                bytesOut: $bytesSent,
            );
        }

        return $statuses;
    }

    public function removeService(Service $service): void
    {
        $dir = $this->serviceDir($service);

        Process::run(['systemctl', 'disable', '--now', "openvpn-server@{$this->unitName($service)}"])->run();

        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    private function copyEasyRsaInto(string $dir): void
    {
        if (! File::isDirectory(self::EASYRSA_SOURCE)) {
            throw new RuntimeException('easy-rsa not found at '.self::EASYRSA_SOURCE.' -- is the easy-rsa package installed?');
        }

        File::copyDirectory(self::EASYRSA_SOURCE, $dir);
        chmod("{$dir}/easyrsa", 0750);
    }

    private function safeCommonName(Service $service, ServiceUser $user): string
    {
        // Decoupled from the admin-facing display name, which may contain
        // characters easy-rsa's CN validation rejects.
        return "svc{$service->id}-user{$user->id}";
    }

    private function readCertSerial(string $dir, string $commonName): ?string
    {
        $indexPath = "{$dir}/pki/index.txt";

        if (! File::exists($indexPath)) {
            return null;
        }

        foreach (explode("\n", File::get($indexPath)) as $line) {
            if (str_contains($line, "/CN={$commonName}") && str_starts_with($line, 'V')) {
                $columns = preg_split('/\t+/', $line);

                return $columns[3] ?? null; // serial column
            }
        }

        return null;
    }

    private function extractCertBlock(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----/s', $pem, $matches)) {
            return $matches[0];
        }

        return $pem;
    }

    private function serviceDir(Service $service): string
    {
        return self::BASE_DIR."/{$service->interface_name}";
    }

    private function unitName(Service $service): string
    {
        return $service->interface_name;
    }

    private function managementSocketPath(Service $service): string
    {
        return "/run/openvpn/{$service->interface_name}.sock";
    }

    /**
     * Sends one command to the running server's management interface over
     * its Unix socket and returns the raw response body (without the
     * trailing "END" marker most commands terminate with).
     */
    private function managementCommand(Service $service, string $command): string
    {
        $socketPath = $this->managementSocketPath($service);

        if (! File::exists($socketPath)) {
            throw new RuntimeException("OpenVPN management socket not found for {$service->interface_name}");
        }

        $socket = @stream_socket_client("unix://{$socketPath}", timeout: 5);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the OpenVPN management socket for {$service->interface_name}");
        }

        try {
            fwrite($socket, $command."\n");
            $response = '';
            $start = microtime(true);

            while (! feof($socket) && (microtime(true) - $start) < 5) {
                $chunk = fgets($socket);

                if ($chunk === false) {
                    break;
                }

                $response .= $chunk;

                if (str_starts_with($chunk, 'END') || str_starts_with($chunk, 'SUCCESS:') || str_starts_with($chunk, 'ERROR:')) {
                    break;
                }
            }

            return $response;
        } finally {
            fclose($socket);
        }
    }

    private function writeServerConfig(Service $service): void
    {
        $dir = $this->serviceDir($service);
        $config = $service->config ?? [];

        $dataCiphers = $config['data_ciphers'] ?? 'AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305';
        $keepalive = $config['keepalive'] ?? '10 60';
        $redirectGateway = ($config['redirect_gateway'] ?? true) ? "push \"redirect-gateway def1 bypass-dhcp\"\n" : '';
        $pushRoutes = collect($config['push_routes'] ?? [])
            ->map(fn (string $route) => "push \"route {$route}\"")
            ->implode("\n");
        $egressInterface = $config['egress_interface'] ?? 'eth0';

        $contents = <<<CONF
        port {$service->listen_port}
        proto {$service->transport->value}
        dev {$service->interface_name}
        topology subnet
        server {$this->networkAddress($service)} {$this->netmask($service)}
        ca {$dir}/pki/ca.crt
        cert {$dir}/pki/issued/server.crt
        key {$dir}/pki/private/server.key
        tls-crypt {$dir}/ta.key
        crl-verify {$dir}/crl.pem
        data-ciphers {$dataCiphers}
        data-ciphers-fallback AES-256-GCM
        auth SHA256
        tls-version-min 1.2
        keepalive {$keepalive}
        {$redirectGateway}{$pushRoutes}
        persist-key
        persist-tun
        management {$this->managementSocketPath($service)} unix
        status {$dir}/status.log 10
        verb 3

        CONF;

        File::put("{$dir}/server.conf", $contents);

        // NAT for this service's subnet, mirroring the WireGuard driver's
        // PostUp/PreDown -- OpenVPN's own config format has no equivalent
        // directive, so this is applied once here rather than per-boot.
        // `-C` only checks whether the rule already exists (e.g. on a
        // re-applied config); a non-zero exit means it's missing.
        $ruleExists = Process::run(['iptables', '-t', 'nat', '-C', 'POSTROUTING', '-s', $service->subnet_cidr, '-o', $egressInterface, '-j', 'MASQUERADE'])->successful();

        if (! $ruleExists) {
            Process::run(['iptables', '-t', 'nat', '-A', 'POSTROUTING', '-s', $service->subnet_cidr, '-o', $egressInterface, '-j', 'MASQUERADE'])->throw();
        }
    }

    private function networkAddress(Service $service): string
    {
        return explode('/', $service->subnet_cidr)[0];
    }

    private function netmask(Service $service): string
    {
        $prefix = (int) (explode('/', $service->subnet_cidr)[1] ?? 24);

        return long2ip(-1 << (32 - $prefix));
    }
}
