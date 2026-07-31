<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\WireGuard\WireGuardDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TelemetryProbe2Test extends TestCase
{
    use RefreshDatabase;

    public function test_driver_poll(): void
    {
        $service = Service::create([
            'name' => 'Home tunnel',
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.8.0.0/24',
            'listen_port' => 51820,
            'logging_enabled_default' => true,
            'config' => ['endpoint_host' => 'vpn.example.com'],
        ]);

        $user = ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'phone',
            'status' => ServiceUserStatus::Active,
            'tunnel_ip' => '10.8.0.2',
        ]);

        $handshake = time() - 20;
        $dump = implode("\n", [
            implode("\t", ['aPriv=', 'aPub=', '51820', 'off']),
            implode("\t", ['peerPub=', 'psk=', '203.0.113.9:48123', '10.8.0.2/32', (string) $handshake, '123456789', '987654321', '25']),
        ])."\n";

        Process::fake(['*' => Process::result(output: $dump)]);

        $statuses = (new WireGuardDriver)->pollStatus($service);

        fwrite(STDERR, "\n--- statuses keys: ".json_encode(array_keys($statuses))."\n");
        foreach ($statuses as $k => $s) {
            fwrite(STDERR, "--- [$k] connected=".var_export($s->connected, true)
                .' hs='.var_export($s->lastHandshakeAt?->toDateTimeString(), true)
                .' peerIp='.var_export($s->peerIp, true)
                .' in='.$s->bytesIn.' out='.$s->bytesOut."\n");
        }

        $users = $service->serviceUsers()->get()->keyBy('tunnel_ip');
        fwrite(STDERR, '--- user keys: '.json_encode($users->keys()->all())."\n");
        fwrite(STDERR, '--- raw tunnel_ip: '.var_export($user->fresh()->tunnel_ip, true)."\n");

        $this->assertTrue(true);
    }
}
