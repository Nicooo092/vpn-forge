<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Jobs\Vpn\PollAllServiceStatuses;
use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\Service;
use App\Models\ServiceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TelemetryProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_writes_telemetry(): void
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

        // Realistic `wg show wg0 dump` output: interface line, then one peer.
        $handshake = time() - 20;
        $dump = implode("\n", [
            implode("\t", ['aPriv=', 'aPub=', '51820', 'off']),
            implode("\t", ['peerPub=', 'psk=', '203.0.113.9:48123', '10.8.0.2/32', (string) $handshake, '123456789', '987654321', '25']),
        ])."\n";

        Process::fake(['*' => Process::result(output: $dump)]);

        fwrite(STDERR, "\n--- active services: ".Service::where('status', ServiceStatus::Active)->count()."\n");

        (new PollAllServiceStatuses)->handle();

        $user->refresh();
        $service->refresh();

        fwrite(STDERR, "\n--- last_error: ".var_export($service->last_error, true)."\n");
        fwrite(STDERR, '--- last_handshake_at: '.var_export($user->last_handshake_at?->toDateTimeString(), true)."\n");
        fwrite(STDERR, '--- last_connected_at: '.var_export($user->last_connected_at?->toDateTimeString(), true)."\n");
        fwrite(STDERR, '--- last_seen_ip: '.var_export($user->last_seen_ip, true)."\n");
        fwrite(STDERR, '--- bytes: '.$user->last_cumulative_bytes_in.'/'.$user->last_cumulative_bytes_out."\n");
        fwrite(STDERR, '--- connection logs: '.ConnectionLog::count()."\n");
        fwrite(STDERR, '--- bandwidth samples: '.BandwidthSample::count()."\n");

        $this->assertNotNull($user->last_handshake_at);
    }
}
