<?php

namespace App\Jobs\Vpn;

use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Enums\UserMailType;
use App\Jobs\Notifications\SendUserMail;
use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Services\Geo\GeoLocator;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Scheduled roughly every 30-60s (see routes/console.php). Reading peer
 * state (e.g. `wg show ... dump`) needs the same elevated privilege as
 * writing config, so this also runs on the `system` queue, not inline in a
 * web request.
 */
class PollAllServiceStatuses implements ShouldQueue
{
    use Queueable;

    // A single failed poll isn't worth retrying/blocking the next
    // scheduled run -- just try again at the next tick.
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $services = Service::where('status', ServiceStatus::Active)->get();

        foreach ($services as $service) {
            try {
                $this->pollOne($service);
            } catch (Throwable $e) {
                $service->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }

        // Heartbeat. This job runs every minute on the privileged worker, so a
        // fresh timestamp here is proof the worker is alive and draining the
        // queue that carries every enforcement job. The Server health page
        // reads it; a stale value means the worker has stopped and access
        // windows, quotas and device caps are no longer being applied.
        Cache::put('vpnforge:worker-heartbeat', Carbon::now()->timestamp, Carbon::now()->addDay());
    }

    private function pollOne(Service $service): void
    {
        $statuses = $service->driver()->pollStatus($service);
        $now = Carbon::now();

        $users = $service->serviceUsers()->get()->keyBy('tunnel_ip');

        $serviceTotalIn = 0;
        $serviceTotalOut = 0;

        foreach ($statuses as $tunnelIp => $status) {
            $user = $users->get($tunnelIp);

            if ($user === null) {
                continue; // Stale/unknown peer, e.g. right after removal.
            }

            // Interface counters report traffic since the tunnel came up, not
            // since this poller started watching it. A user we have never seen
            // connected, whose counters are nonetheless already non-zero, moved
            // that data while we had no visibility -- booking the whole backlog
            // as one delta plants a spike worth hours of real traffic in a
            // single bucket and flattens the rest of the bandwidth chart
            // against the axis. Seed the baseline instead and start measuring
            // from here. The cost is losing at most one poll interval of a
            // brand new user's traffic.
            $firstObservation = $user->last_handshake_at === null
                && $user->last_cumulative_bytes_in === 0
                && $user->last_cumulative_bytes_out === 0
                && ($status->bytesIn > 0 || $status->bytesOut > 0);

            $deltaIn = $firstObservation ? 0 : max(0, $status->bytesIn - $user->last_cumulative_bytes_in);
            $deltaOut = $firstObservation ? 0 : max(0, $status->bytesOut - $user->last_cumulative_bytes_out);

            $openLog = $user->connectionLogs()->whereNull('disconnected_at')->latest('connected_at')->first();

            if ($status->connected && $openLog === null) {
                // Checked before the log is written, so this connection is not
                // yet in the user's history -- a genuinely new /24 raises an
                // alert once.
                // Resolve country + ASN once, here, where the session opens: it
                // enriches the new-network alert below and is stored on the row
                // so it survives a later database change. Null when the GeoIP
                // databases are absent or the address is private/unknown.
                $geo = $status->peerIp !== null
                    ? app(GeoLocator::class)->locate($status->peerIp)
                    : null;

                if ($status->peerIp !== null && $this->isNewNetwork($user, $status->peerIp)) {
                    $where = $geo !== null && $geo['country_name'] !== null
                        ? ' from '.$geo['country_name'].($geo['as_org'] !== null ? " ({$geo['as_org']})" : '')
                        : '';

                    app(Notifier::class)->event(
                        NotificationEvent::NewLocation,
                        "{$user->name} connected from a new network",
                        "{$status->peerIp}{$where} (service {$service->name}).",
                    );

                    // Let the user know their own account connected from a new
                    // network. Only the /24 travels into the email and its
                    // de-dup key -- no exact address, no browsing detail.
                    // isNewNetwork() already guaranteed an IPv4 peer here.
                    $network = implode('.', array_slice(explode('.', $status->peerIp), 0, 3)).'.0/24';

                    SendUserMail::dispatch(
                        $user,
                        UserMailType::NewDeviceLocation,
                        "user-mail:new-location:{$user->id}:{$network}",
                        ['network' => $network],
                    );
                }

                $openLog = ConnectionLog::create([
                    'service_user_id' => $user->id,
                    'connected_at' => $status->lastHandshakeAt ?? $now,
                    'peer_ip' => $status->peerIp,
                    'country_code' => $geo['country_code'] ?? null,
                    'country_name' => $geo['country_name'] ?? null,
                    'asn' => $geo['asn'] ?? null,
                    'as_org' => $geo['as_org'] ?? null,
                ]);
                $user->last_connected_at = $status->lastHandshakeAt ?? $now;
            } elseif (! $status->connected && $openLog !== null) {
                $openLog->update(['disconnected_at' => $now]);
                $openLog = null;
            }

            $openLog?->update([
                'bytes_in' => $status->bytesIn,
                'bytes_out' => $status->bytesOut,
            ]);

            if ($deltaIn > 0 || $deltaOut > 0) {
                BandwidthSample::create([
                    'service_id' => $service->id,
                    'service_user_id' => $user->id,
                    'sampled_at' => $now,
                    'bytes_in_delta' => $deltaIn,
                    'bytes_out_delta' => $deltaOut,
                ]);
            }

            $serviceTotalIn += $deltaIn;
            $serviceTotalOut += $deltaOut;

            $user->forceFill([
                'last_handshake_at' => $status->lastHandshakeAt,
                'last_seen_ip' => $status->peerIp,
                'last_cumulative_bytes_in' => $status->bytesIn,
                'last_cumulative_bytes_out' => $status->bytesOut,
            ])->save();
        }

        if ($serviceTotalIn > 0 || $serviceTotalOut > 0) {
            BandwidthSample::create([
                'service_id' => $service->id,
                'service_user_id' => null,
                'sampled_at' => $now,
                'bytes_in_delta' => $serviceTotalIn,
                'bytes_out_delta' => $serviceTotalOut,
            ]);
        }
    }

    /**
     * Whether this is the first time the user has connected from the peer IP's
     * /24. Matched at /24 (not the exact address) so an ISP or mobile carrier
     * shuffling the last octet between reconnects does not read as a new
     * location every time. IPv6 endpoints are not alerted on.
     */
    private function isNewNetwork(ServiceUser $user, string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $parts = explode('.', $ip);
        // The trailing dot keeps 1.2.3.% from also matching 1.2.30.x. The
        // octets are digits only (validated above), so nothing LIKE-special
        // reaches the query.
        $prefix = "{$parts[0]}.{$parts[1]}.{$parts[2]}.";

        return ! $user->connectionLogs()->where('peer_ip', 'like', $prefix.'%')->exists();
    }
}
