<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class TrafficShapingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): Service
    {
        return Service::create([
            'name' => 'home',
            'interface_name' => 'wg0',
            'subnet_cidr' => '10.0.0.0/24',
            'listen_port' => 51820,
            'protocol' => VpnProtocol::WireGuard,
            'transport' => Transport::Udp,
            'status' => ServiceStatus::Active,
            'logging_enabled_default' => true,
            'config' => [],
        ]);
    }

    private function user(Service $service, string $ip, ?int $kbps, ServiceUserStatus $status = ServiceUserStatus::Active): ServiceUser
    {
        return ServiceUser::create([
            'service_id' => $service->id,
            'name' => 'u'.str_replace('.', '', $ip),
            'status' => $status,
            'tunnel_ip' => $ip,
            'rate_limit_kbps' => $kbps,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function commands(Service $service): array
    {
        return collect((new TrafficShaper)->plan($service))
            ->map(fn ($step) => implode(' ', $step['argv']))
            ->all();
    }

    private function assertHasCommand(array $commands, string $needle): void
    {
        $this->assertTrue(
            collect($commands)->contains(fn (string $c) => str_contains($c, $needle)),
            "Expected a command containing: {$needle}\nGot:\n".implode("\n", $commands),
        );
    }

    public function test_with_no_limits_the_plan_only_tears_down(): void
    {
        $service = $this->service();
        $this->user($service, '10.0.0.2', null);

        $plan = (new TrafficShaper)->plan($service);

        // Only the three teardown commands, and every one allowed to fail.
        $this->assertCount(3, $plan);
        $this->assertTrue(collect($plan)->every(fn ($s) => $s['allowFail'] === true));
        $this->assertSame([], collect($this->commands($service))->filter(fn ($c) => str_contains($c, 'htb'))->all());
    }

    public function test_a_limited_user_gets_download_and_upload_rules(): void
    {
        $service = $this->service();
        $this->user($service, '10.0.0.2', 5000); // 5 Mbit/s
        $ifb = (new TrafficShaper)->ifbName($service);

        $commands = $this->commands($service);

        // Egress (download) tree on the tunnel interface.
        $this->assertHasCommand($commands, 'tc qdisc add dev wg0 root handle 1: htb default 2');
        // The per-user class + a filter matching the client as destination.
        $this->assertHasCommand($commands, 'tc class add dev wg0 parent 1:1 classid 1:100 htb rate 5000kbit ceil 5000kbit');
        $this->assertHasCommand($commands, 'match ip dst 10.0.0.2/32 flowid 1:100');

        // Ingress (upload) mirrored to the per-service IFB device.
        $this->assertHasCommand($commands, "ip link add {$ifb} type ifb");
        $this->assertHasCommand($commands, "action mirred egress redirect dev {$ifb}");
        $this->assertHasCommand($commands, "tc class add dev {$ifb} parent 1:1 classid 1:100 htb rate 5000kbit ceil 5000kbit");
        $this->assertHasCommand($commands, 'match ip src 10.0.0.2/32 flowid 1:100');
    }

    public function test_each_limited_user_gets_a_distinct_class(): void
    {
        $service = $this->service();
        $this->user($service, '10.0.0.2', 3000);
        $this->user($service, '10.0.0.3', 8000);

        $commands = $this->commands($service);

        $this->assertHasCommand($commands, 'match ip dst 10.0.0.2/32 flowid 1:100');
        $this->assertHasCommand($commands, 'match ip dst 10.0.0.3/32 flowid 1:101');
        $this->assertHasCommand($commands, 'classid 1:101 htb rate 8000kbit ceil 8000kbit');
    }

    public function test_a_suspended_limited_user_is_not_shaped(): void
    {
        $service = $this->service();
        $this->user($service, '10.0.0.2', 5000, ServiceUserStatus::Suspended);

        // No active limited users -> teardown-only.
        $this->assertCount(3, (new TrafficShaper)->plan($service));
    }

    public function test_the_ifb_device_name_is_per_service(): void
    {
        $service = $this->service();

        $this->assertSame('vpnfb'.$service->id, (new TrafficShaper)->ifbName($service));
    }

    public function test_apply_runs_the_whole_plan(): void
    {
        Process::fake();
        $service = $this->service();
        $this->user($service, '10.0.0.2', 5000);

        (new TrafficShaper)->apply($service);

        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'match ip dst 10.0.0.2/32'));
    }

    public function test_apply_throws_when_a_setup_command_fails(): void
    {
        // Teardown (del) still succeeds; a class-add fails -> not tolerated.
        Process::fake([
            'tc class add*' => Process::result(exitCode: 1, errorOutput: 'boom'),
            '*' => Process::result(exitCode: 0),
        ]);

        $service = $this->service();
        $this->user($service, '10.0.0.2', 5000);

        $this->expectException(RuntimeException::class);
        (new TrafficShaper)->apply($service);
    }

    public function test_apply_tolerates_teardown_failure_when_nothing_is_limited(): void
    {
        // Everything fails, but the no-limit plan is teardown-only and every
        // step is allowed to fail, so apply must not throw.
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'nope')]);

        $service = $this->service();
        $this->user($service, '10.0.0.2', null);

        (new TrafficShaper)->apply($service);

        $this->assertTrue(true);
    }
}
