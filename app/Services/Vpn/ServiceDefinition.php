<?php

namespace App\Services\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Exceptions\InvalidServiceDefinitionException;
use App\Models\Service;

/**
 * Clone / export / import of a service *definition* -- the non-secret shape of
 * a service, never its key material.
 *
 * The only secret that ever reaches the DB `config` blob is WireGuard's
 * server_public_key (written by the driver at provisioning time); server and
 * client private keys and the OpenVPN PKI live on disk, worker-side, and never
 * touch this process. secretConfig() strips server_public_key plus anything
 * key-shaped defensively, so nothing secret can leave the panel through an
 * export or be carried into a clone -- the driver mints fresh keys/PKI on the
 * next provisioning run regardless.
 */
class ServiceDefinition
{
    public const EXPORT_KIND = 'vpnforge.service';

    public const EXPORT_VERSION = 1;

    /**
     * Config keys stripped from every export/clone. server_public_key is the
     * one secret the config blob actually carries; the defensive filter in
     * secretConfig() catches any future key-shaped value nobody remembered to
     * list here.
     *
     * @var list<string>
     */
    public const SECRET_CONFIG_KEYS = ['server_public_key'];

    /**
     * The downloadable, non-secret representation of a service.
     *
     * @return array<string, mixed>
     */
    public function toExport(Service $service): array
    {
        return [
            'kind' => self::EXPORT_KIND,
            'version' => self::EXPORT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'service' => [
                'name' => $service->name,
                'protocol' => $service->protocol->value,
                'transport' => $service->transport->value,
                'subnet_cidr' => $service->subnet_cidr,
                'listen_port' => (int) $service->listen_port,
                'logging_enabled_default' => (bool) $service->logging_enabled_default,
                'config' => $this->secretConfig($service->config ?? []),
            ],
        ];
    }

    public function exportJson(Service $service): string
    {
        return (string) json_encode(
            $this->toExport($service),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Form state for the create page, pre-filled from an existing service:
     * advanced mode (so the suggested network details are on screen and
     * survive the save), a " (copy)" name, and the source's non-secret config.
     * Network details are always freshly suggested -- cloning the source's
     * interface/subnet/port would collide with it.
     *
     * @return array<string, mixed>
     */
    public function cloneFormState(Service $service): array
    {
        return [
            'form_mode' => 'advanced',
            'name' => __(':name (copy)', ['name' => $service->name]),
            'protocol' => $service->protocol->value,
            'transport' => $service->transport->value,
            'interface_name' => $this->suggestInterfaceName($service->protocol),
            'subnet_cidr' => $this->availableSubnet(),
            'listen_port' => $this->availableListenPort($service->protocol, $service->transport),
            'logging_enabled_default' => (bool) $service->logging_enabled_default,
            'config' => $this->secretConfig($service->config ?? []),
        ];
    }

    /**
     * Create a provisioning-status service from an exported (or bare) JSON
     * definition. Network details are reused when free, otherwise freshly
     * suggested, so an import never collides with a live service. The caller
     * dispatches ProvisionService, which generates fresh keys/PKI.
     *
     * @throws InvalidServiceDefinitionException
     */
    public function createFromImport(string $json): Service
    {
        $attributes = $this->parse($json);

        return Service::create([
            'name' => $attributes['name'],
            'protocol' => $attributes['protocol'],
            'transport' => $attributes['transport'],
            'status' => ServiceStatus::Provisioning,
            'interface_name' => $this->suggestInterfaceName($attributes['protocol']),
            'subnet_cidr' => $this->availableSubnet($attributes['subnet_cidr']),
            'listen_port' => $this->availableListenPort(
                $attributes['protocol'],
                $attributes['transport'],
                $attributes['listen_port'],
            ),
            'logging_enabled_default' => $attributes['logging_enabled_default'],
            'config' => $attributes['config'],
        ]);
    }

    /**
     * Decode and validate an import payload into normalised service
     * attributes. Accepts either the wrapped export ({kind, version, service})
     * or a bare service object.
     *
     * @return array{name: string, protocol: VpnProtocol, transport: Transport, subnet_cidr: string, listen_port: int, logging_enabled_default: bool, config: array<string, mixed>}
     *
     * @throws InvalidServiceDefinitionException
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidServiceDefinitionException(__('The import is not valid JSON.'));
        }

        if (isset($decoded['version']) && (int) $decoded['version'] > self::EXPORT_VERSION) {
            throw new InvalidServiceDefinitionException(__('This export was produced by a newer version of vpnforge.'));
        }

        $service = $decoded['service'] ?? $decoded;

        if (! is_array($service)) {
            throw new InvalidServiceDefinitionException(__('The import is missing its service definition.'));
        }

        $protocol = VpnProtocol::tryFrom((string) ($service['protocol'] ?? ''));
        if ($protocol === null) {
            throw new InvalidServiceDefinitionException(__('The import names an unknown VPN protocol.'));
        }

        $transport = Transport::tryFrom((string) ($service['transport'] ?? ''));
        if ($transport === null) {
            throw new InvalidServiceDefinitionException(__('The import names an unknown transport.'));
        }

        $name = trim((string) ($service['name'] ?? ''));
        if ($name === '') {
            throw new InvalidServiceDefinitionException(__('The import is missing a service name.'));
        }

        $subnet = (string) ($service['subnet_cidr'] ?? '');
        if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $subnet) !== 1) {
            throw new InvalidServiceDefinitionException(__('The import has an invalid subnet.'));
        }

        $port = (int) ($service['listen_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new InvalidServiceDefinitionException(__('The import has an invalid listen port.'));
        }

        $config = $service['config'] ?? [];
        if (! is_array($config)) {
            $config = [];
        }

        return [
            'name' => $name,
            'protocol' => $protocol,
            'transport' => $transport,
            'subnet_cidr' => $subnet,
            'listen_port' => $port,
            'logging_enabled_default' => (bool) ($service['logging_enabled_default'] ?? false),
            'config' => $this->secretConfig($config),
        ];
    }

    /**
     * Strip every secret from a config blob. Removes the known key(s), then
     * defensively drops anything key/secret-shaped so a future config addition
     * cannot silently leak.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function secretConfig(array $config): array
    {
        foreach (self::SECRET_CONFIG_KEYS as $key) {
            unset($config[$key]);
        }

        return array_filter(
            $config,
            static fn (string $key): bool => preg_match('/(private|secret|preshared|_key$)/i', $key) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Lowest interface index not already taken by another service. Mirrors
     * ServiceForm's simple-mode suggestion so clone/import propose a name that
     * does not collide with a live interface.
     */
    private function suggestInterfaceName(VpnProtocol $protocol): string
    {
        $prefix = $protocol === VpnProtocol::OpenVpn ? 'tun' : 'wg';
        $taken = Service::pluck('interface_name')->all();

        for ($i = 0; $i < 100; $i++) {
            if (! in_array("{$prefix}{$i}", $taken, true)) {
                return "{$prefix}{$i}";
            }
        }

        return "{$prefix}0";
    }

    private function availableSubnet(?string $preferred = null): string
    {
        $taken = Service::pluck('subnet_cidr')->all();

        if ($preferred !== null && ! in_array($preferred, $taken, true)) {
            return $preferred;
        }

        for ($i = 8; $i < 250; $i++) {
            if (! in_array("10.{$i}.0.0/24", $taken, true)) {
                return "10.{$i}.0.0/24";
            }
        }

        return '10.8.0.0/24';
    }

    private function availableListenPort(VpnProtocol $protocol, Transport $transport, ?int $preferred = null): int
    {
        // Ports are unique per transport (see the services table's composite
        // unique index), so only clashes on the same transport matter.
        $taken = Service::where('transport', $transport->value)
            ->pluck('listen_port')
            ->map(static fn ($p): int => (int) $p)
            ->all();

        if ($preferred !== null && $preferred >= 1 && $preferred <= 65535 && ! in_array($preferred, $taken, true)) {
            return $preferred;
        }

        $base = $protocol === VpnProtocol::OpenVpn ? 1194 : 51820;

        for ($port = $base; $port < $base + 200; $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        return $base;
    }
}
