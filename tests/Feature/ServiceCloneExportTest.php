<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Exceptions\InvalidServiceDefinitionException;
use App\Models\Service;
use App\Services\Vpn\ServiceDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A service export must be a faithful copy of the non-secret definition and
 * nothing more -- the WireGuard server_public_key that lives in the config
 * blob must never cross the boundary -- and importing it back must reconstruct
 * the same shape as a fresh, non-colliding provisioning copy.
 */
class ServiceCloneExportTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        return Service::create([
            'name' => 'office',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [
                'endpoint_host' => '203.0.113.10',
                'egress_interface' => 'eth0',
                'mtu' => 1420,
                'server_public_key' => 'SECRET-DO-NOT-LEAK',
            ],
        ]);
    }

    public function test_export_carries_the_definition_but_never_the_server_key(): void
    {
        $definition = app(ServiceDefinition::class);
        $json = $definition->exportJson($this->service());
        $export = json_decode($json, true);

        $this->assertSame('vpnforge.service', $export['kind']);
        $this->assertSame(1, $export['version']);
        $this->assertSame('office', $export['service']['name']);
        $this->assertSame('wireguard', $export['service']['protocol']);
        $this->assertSame('udp', $export['service']['transport']);
        $this->assertSame('10.8.0.0/24', $export['service']['subnet_cidr']);
        $this->assertSame(51820, $export['service']['listen_port']);
        $this->assertTrue($export['service']['logging_enabled_default']);
        $this->assertSame('203.0.113.10', $export['service']['config']['endpoint_host']);
        $this->assertSame(1420, $export['service']['config']['mtu']);

        // The one secret in the config blob must not appear anywhere.
        $this->assertArrayNotHasKey('server_public_key', $export['service']['config']);
        $this->assertStringNotContainsString('SECRET-DO-NOT-LEAK', $json);
    }

    public function test_import_round_trips_the_config_shape_as_a_fresh_copy(): void
    {
        $definition = app(ServiceDefinition::class);
        $original = $this->service();

        $imported = $definition->createFromImport($definition->exportJson($original));

        // Non-secret shape carried across intact.
        $this->assertSame($original->protocol, $imported->protocol);
        $this->assertSame($original->transport, $imported->transport);
        $this->assertTrue($imported->logging_enabled_default);
        $this->assertSame('203.0.113.10', $imported->config['endpoint_host']);
        $this->assertSame(1420, (int) $imported->config['mtu']);

        // Fresh identity: the source still exists, so nothing may collide, and
        // the server key is never carried.
        $this->assertNotSame($original->interface_name, $imported->interface_name);
        $this->assertNotSame($original->subnet_cidr, $imported->subnet_cidr);
        $this->assertNotSame((int) $original->listen_port, (int) $imported->listen_port);
        $this->assertArrayNotHasKey('server_public_key', $imported->config);

        // Lands as a provisioning copy so the driver mints fresh keys/PKI.
        $this->assertSame(ServiceStatus::Provisioning, $imported->status);
    }

    public function test_clone_state_suggests_a_non_colliding_network_without_the_key(): void
    {
        $state = app(ServiceDefinition::class)->cloneFormState($this->service());

        $this->assertSame('advanced', $state['form_mode']);
        $this->assertSame('office (copy)', $state['name']);
        $this->assertSame('wireguard', $state['protocol']);
        $this->assertSame('wg1', $state['interface_name']);
        $this->assertSame('10.9.0.0/24', $state['subnet_cidr']);
        $this->assertSame(51821, $state['listen_port']);
        $this->assertSame('203.0.113.10', $state['config']['endpoint_host']);
        $this->assertArrayNotHasKey('server_public_key', $state['config']);
    }

    public function test_a_malformed_import_is_rejected(): void
    {
        $this->expectException(InvalidServiceDefinitionException::class);

        app(ServiceDefinition::class)->createFromImport('{"service":{"protocol":"nope"}}');
    }
}
