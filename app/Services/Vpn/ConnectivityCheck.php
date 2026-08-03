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
            'label' => __('Service provisioned'),
            'ok' => $service->status === ServiceStatus::Active,
            'detail' => $service->status === ServiceStatus::Active
                ? __('Provisioning completed.')
                : __('Status is :status. :detail', [
                    'status' => $service->status->getLabel(),
                    'detail' => $service->last_error ?? __('Use Retry provisioning.'),
                ]),
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
            'label' => __('Interface :name exists', ['name' => $service->interface_name]),
            'ok' => $exists,
            'detail' => $exists
                ? trim(preg_replace('/\s+/', ' ', $output))
                : __('The kernel has no such interface. The tunnel was never brought up, or it did not survive a reboot.'),
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
            'label' => __('Listening on :port/:transport', [
                'port' => $service->listen_port,
                'transport' => $service->transport->value,
            ]),
            'ok' => $listening,
            'detail' => $listening
                ? __('A socket is bound to that port.')
                : __('Nothing is bound to that port. The server process is not running, or it is using a different port than the panel records.'),
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function ipForwarding(): array
    {
        $enabled = trim($this->capture(['sysctl', '-n', 'net.ipv4.ip_forward']) ?? '') === '1';

        return [
            'label' => __('IP forwarding enabled'),
            'ok' => $enabled,
            'detail' => $enabled
                ? __('net.ipv4.ip_forward is 1.')
                : __('net.ipv4.ip_forward is 0, so clients can reach the tunnel but nothing beyond it.'),
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
                'label' => __('NAT rule present'),
                'ok' => null,
                'detail' => __('Cannot read the NAT table from the web process, which holds no network privileges by design. Check by hand with: sudo iptables -t nat -S POSTROUTING'),
            ];
        }

        $present = str_contains($output, $service->subnet_cidr) && str_contains($output, $egress);

        return [
            'label' => __('NAT rule present'),
            'ok' => $present,
            'detail' => $present
                ? __('Traffic from :subnet is masqueraded out of :egress.', [
                    'subnet' => $service->subnet_cidr,
                    'egress' => $egress,
                ])
                : __('No MASQUERADE rule for :subnet out of :egress. Clients connect but reach nothing beyond the tunnel. Check the egress interface against `ip route show default`.', [
                    'subnet' => $service->subnet_cidr,
                    'egress' => $egress,
                ]),
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
            'label' => __('DNS resolver running'),
            'ok' => $active,
            'detail' => $active
                ? __(':unit is active.', ['unit' => $unit])
                : __(':unit is not running. Clients pointed at this service\'s resolver will fail to resolve anything, and no traffic logs will be recorded.', ['unit' => $unit]),
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
            'label' => __('Endpoint hostname resolves'),
            'ok' => $ok,
            'detail' => $ok
                ? __(':host resolves to :address.', ['host' => $host, 'address' => $resolved])
                : __(':host does not resolve. Clients will not find the server at all.', ['host' => $host]),
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
