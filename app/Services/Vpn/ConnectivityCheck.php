<?php

namespace App\Services\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Models\Service;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Answers "why can nobody connect to this?" with the checks that can be made
 * from the server itself.
 *
 * It deliberately does not claim to test reachability from the internet: the
 * usual culprit is a cloud provider's security group, which is invisible from
 * inside the machine. What it can do is rule out everything on this side, so
 * that a clean run points squarely at the firewall -- which is far more
 * useful than a bare "cannot connect".
 */
class ConnectivityCheck
{
    /**
     * `ok` is null where the answer could not be determined rather than
     * false, so an unreadable check never reads as a failure.
     *
     * @return array<int, array{label: string, ok: ?bool, detail: string}>
     */
    public function run(Service $service): array
    {
        return array_values(array_filter([
            $this->serviceStatus($service),
            $this->interfaceUp($service),
            $this->portListening($service),
            $this->ipForwarding(),
            $this->natRule($service),
            $this->resolver($service),
            $this->endpointResolves($service),
        ]));
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function serviceStatus(Service $service): array
    {
        return [
            'label' => 'Service provisioned',
            'ok' => $service->status === ServiceStatus::Active,
            'detail' => $service->status === ServiceStatus::Active
                ? 'Provisioning completed.'
                : 'Status is '.$service->status->getLabel().'. '.($service->last_error ?? 'Use Retry provisioning.'),
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function interfaceUp(Service $service): array
    {
        $output = $this->capture(['ip', '-br', 'addr', 'show', $service->interface_name]);
        $exists = $output !== null && trim($output) !== '';

        return [
            'label' => "Interface {$service->interface_name} exists",
            'ok' => $exists,
            'detail' => $exists
                ? trim(preg_replace('/\s+/', ' ', $output))
                : 'The kernel has no such interface. The tunnel was never brought up, or it did not survive a reboot.',
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function portListening(Service $service): array
    {
        // -u for UDP, -t for TCP: a WireGuard socket never shows up in the
        // TCP table, and looking in the wrong one reads as "nothing there".
        $flag = $service->transport === Transport::Tcp ? '-lnt' : '-lnu';
        $output = $this->capture(['ss', $flag]) ?? '';

        $listening = str_contains($output, ":{$service->listen_port} ");

        return [
            'label' => "Listening on {$service->listen_port}/{$service->transport->value}",
            'ok' => $listening,
            'detail' => $listening
                ? 'A socket is bound to that port.'
                : 'Nothing is bound to that port. The server process is not running, or it is using a different port than the panel records.',
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function ipForwarding(): array
    {
        $enabled = trim($this->capture(['sysctl', '-n', 'net.ipv4.ip_forward']) ?? '') === '1';

        return [
            'label' => 'IP forwarding enabled',
            'ok' => $enabled,
            'detail' => $enabled
                ? 'net.ipv4.ip_forward is 1.'
                : 'net.ipv4.ip_forward is 0, so clients can reach the tunnel but nothing beyond it.',
        ];
    }

    /**
     * @return array{label: string, ok: ?bool, detail: string}
     */
    private function natRule(Service $service): array
    {
        $egress = $service->config['egress_interface'] ?? 'eth0';
        $output = $this->capture(['iptables', '-t', 'nat', '-S', 'POSTROUTING']);

        // Reading the NAT table needs CAP_NET_ADMIN, which the web process
        // deliberately does not have. Report that honestly instead of turning
        // "could not look" into "the rule is missing" -- a check that invents
        // failures is worse than no check.
        if ($output === null) {
            return [
                'label' => 'NAT rule present',
                'ok' => null,
                'detail' => 'Cannot read the NAT table from the web process, which holds no network privileges by design. Check by hand with: sudo iptables -t nat -S POSTROUTING',
            ];
        }

        $present = str_contains($output, $service->subnet_cidr) && str_contains($output, $egress);

        return [
            'label' => 'NAT rule present',
            'ok' => $present,
            'detail' => $present
                ? "Traffic from {$service->subnet_cidr} is masqueraded out of {$egress}."
                : "No MASQUERADE rule for {$service->subnet_cidr} out of {$egress}. Clients connect but reach nothing beyond the tunnel. Check the egress interface against `ip route show default`.",
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function resolver(Service $service): array
    {
        $unit = "vpnforge-dnsmasq@{$service->interface_name}";
        $active = trim($this->capture(['systemctl', 'is-active', $unit]) ?? '') === 'active';

        return [
            'label' => 'DNS resolver running',
            'ok' => $active,
            'detail' => $active
                ? "{$unit} is active."
                : "{$unit} is not running. Clients pointed at this service's resolver will fail to resolve anything, and no traffic logs will be recorded.",
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}|null
     */
    private function endpointResolves(Service $service): ?array
    {
        $host = $service->config['endpoint_host'] ?? null;

        if ($host === null || filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // A literal address has nothing to resolve.
        }

        $resolved = gethostbyname($host);
        $ok = $resolved !== $host;

        return [
            'label' => 'Endpoint hostname resolves',
            'ok' => $ok,
            'detail' => $ok
                ? "{$host} resolves to {$resolved}."
                : "{$host} does not resolve. Clients will not find the server at all.",
        ];
    }

    private function capture(array $command): ?string
    {
        try {
            $result = Process::run($command);

            return $result->successful() ? $result->output() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
