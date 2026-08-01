<?php

namespace App\Services\Vpn\OpenVpn;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\ClientConfigFile;
use App\Services\Vpn\NetworkInput;
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

    // Ubuntu 24.04's packaged openvpn-server@.service sets
    // WorkingDirectory=/etc/openvpn/server and passes a relative
    // --config %i.conf -- the actual server.conf openvpn reads on start
    // MUST live here (a fixed, distro-defined path), even though every file
    // it references (CA/cert/key/CRL) stays under this service's own
    // per-service directory in BASE_DIR.
    private const UNIT_CONFIG_DIR = '/etc/openvpn/server';

    public function provisionService(Service $service): void
    {
        // interface_name becomes a directory, a systemd instance name and a
        // config path, so a "../.." would escape the service directory.
        NetworkInput::assertInterfaceName($service->interface_name);

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
        $this->syncClientConfigDir($service);

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

    public function rotateUserKeys(Service $service, ServiceUser $user): void
    {
        $dir = $this->serviceDir($service);
        $previousCn = $user->openvpn_common_name;

        // Revoke first: the old certificate has to stop being accepted, and
        // easy-rsa will not build a certificate for a common name already in
        // its index -- which is why the reissued one carries a suffix.
        if ($previousCn !== null) {
            Process::path($dir)->env(['EASYRSA_BATCH' => '1'])->run(['./easyrsa', 'revoke', $previousCn])->throw();
            Process::path($dir)->env(['EASYRSA_BATCH' => '1'])->run(['./easyrsa', 'gen-crl'])->throw();
            File::copy("{$dir}/pki/crl.pem", "{$dir}/crl.pem");
        }

        $user->key_rotations = $user->key_rotations + 1;
        $newCn = $this->safeCommonName($service, $user);

        Process::path($dir)->env(['EASYRSA_BATCH' => '1'])
            ->run(['./easyrsa', 'build-client-full', $newCn, 'nopass'])
            ->throw();

        $user->openvpn_common_name = $newCn;
        $user->certificate_serial = $this->readCertSerial($dir, $newCn);
        $user->issued_at = now();
        $user->save();

        // A suspended user keeps their client-config-dir entry, which is
        // named after the common name that just changed.
        $this->syncClientConfigDir($service);

        // OpenVPN only re-reads the CRL when a connection renegotiates, so
        // the old certificate would otherwise keep working for up to an
        // hour. Cut the session now.
        try {
            $this->managementCommand($service, "kill {$previousCn}");
        } catch (RuntimeException) {
            // Nothing connected, or the server is not running.
        }
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

        File::delete($this->unitConfigPath($service));
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
        $base = "svc{$service->id}-user{$user->id}";

        // Unsuffixed for the first certificate, so names issued before key
        // rotation existed keep matching the files already on disk.
        return $user->key_rotations > 0
            ? "{$base}-r{$user->key_rotations}"
            : $base;
    }

    private function readCertSerial(string $dir, string $commonName): ?string
    {
        $indexPath = "{$dir}/pki/index.txt";

        if (! File::exists($indexPath)) {
            return null;
        }

        foreach (explode("\n", File::get($indexPath)) as $line) {
            if (str_contains($line, "/CN={$commonName}") && str_starts_with($line, 'V')) {
                // A single tab per column, not \t+ -- the revocation-date
                // column is legitimately empty for a valid cert (two
                // adjacent tabs), and collapsing runs of tabs would eat
                // that empty field and shift every column after it.
                $columns = explode("\t", $line);

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

    private function unitConfigPath(Service $service): string
    {
        return self::UNIT_CONFIG_DIR."/{$service->interface_name}.conf";
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

    /**
     * OpenVPN's keepalive takes two numbers -- a ping interval and a restart
     * timeout -- and refuses to start at all on anything else, taking the
     * whole service down.
     *
     * It used to share the `keepalive` config key with WireGuard, whose value
     * is a single number. An OpenVPN service created while that key held a
     * WireGuard-shaped 25 produced `keepalive 25`, and the server died in a
     * restart loop. The key is separate now, but a stored value is still
     * checked rather than trusted: it is free text, and one malformed entry
     * costs the entire service.
     *
     * @param  array<string, mixed>  $config
     */
    private function keepalive(array $config): string
    {
        $value = trim((string) ($config['openvpn_keepalive'] ?? $config['keepalive'] ?? ''));

        return preg_match('/^\d+\s+\d+$/', $value) === 1 ? $value : '10 60';
    }

    /**
     * Kept apart from writing it so the directives can be asserted on without
     * touching /etc or restarting anything. Every value here reaches OpenVPN
     * verbatim, and OpenVPN refuses to start on a single malformed line --
     * there is no partial success to fall back on.
     */
    public function buildServerConfig(Service $service): string
    {
        $dir = $this->serviceDir($service);
        $config = $service->config ?? [];

        // Same reasoning as the WireGuard driver: validate at the sink so no
        // write path can slip a metacharacter into the generated config, the
        // NAT rule interface, or a path.
        NetworkInput::assertInterfaceName($service->interface_name);
        NetworkInput::assertSubnetCidr($service->subnet_cidr);
        NetworkInput::assertEgressInterface($config['egress_interface'] ?? 'eth0');

        // These land verbatim in server.conf, which openvpn-server@ reads as
        // ROOT. OpenVPN's config is line-oriented and has directives (up,
        // script-security, client-connect) that run commands, so a newline in
        // any of them is root code execution. Validate every one at the sink,
        // the same discipline the interface fields above already follow.
        $dataCiphers = NetworkInput::assertCipherList($config['data_ciphers'] ?? 'AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305');
        $keepalive = $this->keepalive($config);
        $authDigest = NetworkInput::assertDigest($config['auth_digest'] ?? 'SHA256');
        $tlsVersionMin = NetworkInput::assertTlsVersion($config['tls_version_min'] ?? '1.2');
        $verb = (int) ($config['verb'] ?? 3);
        $redirectGateway = ($config['redirect_gateway'] ?? true) ? "push \"redirect-gateway def1 bypass-dhcp\"\n" : '';
        $pushRoutes = collect(NetworkInput::filterPushRoutes($config['push_routes'] ?? []))
            ->map(fn (string $route) => "push \"route {$route}\"")
            ->implode("\n");
        $egressInterface = $config['egress_interface'] ?? 'eth0';

        // Without this, OpenVPN clients keep using whatever resolver they had
        // before connecting, so this service's dnsmasq instance -- which is
        // provisioned for every service regardless of protocol -- never sees a
        // single query and DNS-based traffic logging silently captures
        // nothing. WireGuard clients get the equivalent through the DNS= line
        // in their generated config.
        $pushDns = ($config['push_dns'] ?? true)
            ? "push \"dhcp-option DNS {$this->gatewayAddress($service)}\"\n"
            : '';

        $maxClients = isset($config['max_clients']) && $config['max_clients'] !== null && $config['max_clients'] !== ''
            ? 'max-clients '.(int) $config['max_clients']."\n"
            : '';

        $duplicateCn = ($config['duplicate_cn'] ?? false) ? "duplicate-cn\n" : '';

        $contents = <<<CONF
        port {$service->listen_port}
        proto {$service->transport->value}
        dev {$service->interface_name}
        topology subnet
        server {$this->networkAddress($service)} {$this->netmask($service)}
        ca {$dir}/pki/ca.crt
        cert {$dir}/pki/issued/server.crt
        key {$dir}/pki/private/server.key
        dh none
        tls-crypt {$dir}/ta.key
        crl-verify {$dir}/crl.pem
        client-config-dir {$dir}/ccd
        data-ciphers {$dataCiphers}
        data-ciphers-fallback AES-256-GCM
        auth {$authDigest}
        tls-version-min {$tlsVersionMin}
        keepalive {$keepalive}
        {$maxClients}{$duplicateCn}{$redirectGateway}{$pushDns}{$pushRoutes}
        persist-key
        persist-tun
        management {$this->managementSocketPath($service)} unix
        verb {$verb}

        CONF;

        return $contents;
    }

    private function writeServerConfig(Service $service): void
    {
        $config = $service->config ?? [];
        $egressInterface = $config['egress_interface'] ?? 'eth0';

        // The config names this directory, and OpenVPN refuses to start if it
        // is missing -- so create it even when nobody is suspended.
        File::ensureDirectoryExists($this->clientConfigDir($service), 0750);

        // Not {$dir}/server.conf -- see the UNIT_CONFIG_DIR doc comment.
        // (Ubuntu's unit also supplies its own --status flag on the CLI, so
        // a `status` directive here would just be redundant.)
        File::put($this->unitConfigPath($service), $this->buildServerConfig($service));

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

    /**
     * A suspended user has to be blocked without destroying anything, so that
     * turning them back on is a switch rather than issuing a new certificate.
     * Revocation is the opposite -- it burns the serial permanently -- so it
     * is reserved for actually removing someone.
     *
     * OpenVPN's client-config-dir does exactly this: a file named after the
     * common name containing `disable` refuses that client at connection
     * time, and deleting the file lets them straight back in. WireGuard needs
     * no equivalent, since writeConfig() simply stops rendering a peer that
     * is not Active.
     */
    private function syncClientConfigDir(Service $service): void
    {
        $ccdDir = $this->clientConfigDir($service);
        File::ensureDirectoryExists($ccdDir, 0750);

        foreach ($service->serviceUsers()->whereNotNull('openvpn_common_name')->get() as $user) {
            $path = "{$ccdDir}/{$user->openvpn_common_name}";

            if ($user->status === ServiceUserStatus::Suspended) {
                File::put($path, "disable\n");

                continue;
            }

            File::delete($path);
        }

        // Anyone already connected keeps their session until it renegotiates,
        // so cut it now rather than leaving a suspended user online.
        $this->disconnectSuspended($service);
    }

    private function disconnectSuspended(Service $service): void
    {
        $suspended = $service->serviceUsers()
            ->where('status', ServiceUserStatus::Suspended)
            ->whereNotNull('openvpn_common_name')
            ->pluck('openvpn_common_name');

        foreach ($suspended as $commonName) {
            try {
                $this->managementCommand($service, "kill {$commonName}");
            } catch (RuntimeException) {
                // The management socket is only there while the server is
                // running; nothing to disconnect if it is not.
            }
        }
    }

    private function clientConfigDir(Service $service): string
    {
        return $this->serviceDir($service).'/ccd';
    }

    /**
     * The .1 of the subnet: with `topology subnet` OpenVPN takes this address
     * for the server end of the tunnel, and it is also what DnsmasqManager
     * binds this service's resolver to -- the two have to agree or the pushed
     * DNS server points at nothing.
     */
    private function gatewayAddress(Service $service): string
    {
        $parts = explode('.', $this->networkAddress($service));
        $parts[3] = '1';

        return implode('.', $parts);
    }

    private function netmask(Service $service): string
    {
        $prefix = (int) (explode('/', $service->subnet_cidr)[1] ?? 24);

        return long2ip(-1 << (32 - $prefix));
    }
}
