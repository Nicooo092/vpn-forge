<?php

namespace App\Services\Vpn;

use App\Enums\ServiceUserStatus;
use App\Models\Service;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Per-user speed limits, enforced with tc/HTB on the service's tunnel
 * interface. Runs in the privileged worker (CAP_NET_ADMIN); nothing here shells
 * out -- every command is an argv array, so a value can never be read as an
 * extra tc argument.
 *
 * Two directions, because a VPN sees each client by its tunnel IP:
 *   - download (traffic the server sends TO the client) is egress on the
 *     tunnel interface, matched by destination IP;
 *   - upload (traffic the server receives FROM the client) is ingress on the
 *     tunnel interface, which the kernel cannot shape directly -- it is
 *     mirrored to a per-service IFB device and shaped there, matched by source
 *     IP.
 *
 * plan() is pure (it only reads the database and returns the list of commands)
 * so the exact tc invocations can be asserted on in a test without root; apply()
 * runs them. The whole thing is rebuilt from scratch on every apply, which is
 * what keeps it idempotent: tearing the root qdisc down drops every class and
 * filter with it, so there is no stale state to reconcile.
 */
class TrafficShaper
{
    private const DEFAULT_LINK_MBIT = 1000;

    // HTB minor numbers: 1 is the root class, 2 is where unshaped traffic
    // falls (htb default), and per-user classes start above both.
    private const DEFAULT_CLASS = '2';

    private const USER_CLASS_BASE = 100;

    /**
     * The commands that would bring the service's shaping into line with the
     * current per-user limits. Teardown first (so re-applying is idempotent),
     * then -- only if at least one active user has a limit -- the HTB trees and
     * per-user filters. With no limits set, the plan is teardown only, so the
     * interface is left completely untouched.
     *
     * @return array<int, array{argv: array<int, string>, allowFail: bool}>
     */
    public function plan(Service $service): array
    {
        $iface = NetworkInput::assertInterfaceName($service->interface_name);
        $ifb = $this->ifbName($service);

        // Teardown is always safe to attempt and always allowed to fail (there
        // may be nothing there yet).
        $plan = [
            $this->cmd(['tc', 'qdisc', 'del', 'dev', $iface, 'root'], allowFail: true),
            $this->cmd(['tc', 'qdisc', 'del', 'dev', $iface, 'ingress'], allowFail: true),
            $this->cmd(['ip', 'link', 'del', $ifb], allowFail: true),
        ];

        $limited = $service->serviceUsers()
            ->where('status', ServiceUserStatus::Active)
            ->whereNotNull('rate_limit_kbps')
            ->orderBy('id')
            ->get();

        if ($limited->isEmpty()) {
            return $plan;
        }

        $link = max(1, (int) config('vpnforge.shaping.link_mbit', self::DEFAULT_LINK_MBIT));

        // Egress tree on the tunnel interface (download).
        array_push($plan, ...$this->htbRoot($iface, $link));

        // Ingress: mirror everything arriving on the interface to a private
        // IFB device and build the same tree there (upload).
        $plan[] = $this->cmd(['ip', 'link', 'add', $ifb, 'type', 'ifb']);
        $plan[] = $this->cmd(['ip', 'link', 'set', 'dev', $ifb, 'up']);
        $plan[] = $this->cmd(['tc', 'qdisc', 'add', 'dev', $iface, 'handle', 'ffff:', 'ingress']);
        $plan[] = $this->cmd([
            'tc', 'filter', 'add', 'dev', $iface, 'parent', 'ffff:', 'protocol', 'ip',
            'u32', 'match', 'u32', '0', '0',
            'action', 'mirred', 'egress', 'redirect', 'dev', $ifb,
        ]);
        array_push($plan, ...$this->htbRoot($ifb, $link));

        $minor = self::USER_CLASS_BASE;

        foreach ($limited as $user) {
            $ip = NetworkInput::assertTunnelIp($user->tunnel_ip);
            $rate = $this->assertRate((int) $user->rate_limit_kbps);
            $classid = '1:'.$minor;

            // Download: cap traffic destined for this client on the interface.
            $plan[] = $this->userClass($iface, $classid, $rate);
            $plan[] = $this->userFilter($iface, 'dst', $ip, $classid);

            // Upload: cap traffic coming from this client on the IFB mirror.
            $plan[] = $this->userClass($ifb, $classid, $rate);
            $plan[] = $this->userFilter($ifb, 'src', $ip, $classid);

            $minor++;
        }

        return $plan;
    }

    public function apply(Service $service): void
    {
        foreach ($this->plan($service) as $step) {
            $result = Process::run($step['argv']);

            if (! $step['allowFail'] && ! $result->successful()) {
                throw new RuntimeException(
                    'Traffic shaping failed on `'.implode(' ', $step['argv']).'`: '.trim($result->errorOutput())
                );
            }
        }
    }

    /**
     * Best-effort teardown, used when a service is removed: drop the qdiscs and
     * the IFB device, ignoring anything that was not there.
     */
    public function clear(Service $service): void
    {
        $iface = $service->interface_name;

        foreach ([
            ['tc', 'qdisc', 'del', 'dev', $iface, 'root'],
            ['tc', 'qdisc', 'del', 'dev', $iface, 'ingress'],
            ['ip', 'link', 'del', $this->ifbName($service)],
        ] as $argv) {
            Process::run($argv);
        }
    }

    /**
     * A short, unique IFB device name per service. Deliberately not "ifb0/1",
     * which the module creates itself -- "vpnfb{id}" cannot collide with those
     * and stays within the 15-char interface-name limit.
     */
    public function ifbName(Service $service): string
    {
        return 'vpnfb'.$service->id;
    }

    /**
     * @return array<int, array{argv: array<int, string>, allowFail: bool}>
     */
    private function htbRoot(string $dev, int $linkMbit): array
    {
        return [
            $this->cmd(['tc', 'qdisc', 'add', 'dev', $dev, 'root', 'handle', '1:', 'htb', 'default', self::DEFAULT_CLASS]),
            $this->cmd(['tc', 'class', 'add', 'dev', $dev, 'parent', '1:', 'classid', '1:1', 'htb', 'rate', "{$linkMbit}mbit"]),
            $this->cmd(['tc', 'class', 'add', 'dev', $dev, 'parent', '1:1', 'classid', '1:'.self::DEFAULT_CLASS, 'htb', 'rate', "{$linkMbit}mbit", 'ceil', "{$linkMbit}mbit"]),
        ];
    }

    /**
     * @return array{argv: array<int, string>, allowFail: bool}
     */
    private function userClass(string $dev, string $classid, int $rateKbps): array
    {
        return $this->cmd([
            'tc', 'class', 'add', 'dev', $dev, 'parent', '1:1', 'classid', $classid,
            'htb', 'rate', "{$rateKbps}kbit", 'ceil', "{$rateKbps}kbit",
        ]);
    }

    /**
     * @return array{argv: array<int, string>, allowFail: bool}
     */
    private function userFilter(string $dev, string $direction, string $ip, string $classid): array
    {
        return $this->cmd([
            'tc', 'filter', 'add', 'dev', $dev, 'protocol', 'ip', 'parent', '1:0', 'prio', '1',
            'u32', 'match', 'ip', $direction, "{$ip}/32", 'flowid', $classid,
        ]);
    }

    private function assertRate(int $kbps): int
    {
        // The column is an integer, so this cannot carry an injection; the
        // bounds only stop a nonsensical value from producing a broken rule
        // (8 kbit/s floor, 100 Gbit/s ceiling).
        if ($kbps < 8 || $kbps > 100_000_000) {
            throw new InvalidArgumentException("Invalid rate limit: {$kbps} kbit/s.");
        }

        return $kbps;
    }

    /**
     * @param  array<int, string>  $argv
     * @return array{argv: array<int, string>, allowFail: bool}
     */
    private function cmd(array $argv, bool $allowFail = false): array
    {
        return ['argv' => $argv, 'allowFail' => $allowFail];
    }
}
