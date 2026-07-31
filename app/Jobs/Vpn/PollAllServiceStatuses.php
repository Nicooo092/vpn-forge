<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Models\BandwidthSample;
use App\Models\ConnectionLog;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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

            $deltaIn = max(0, $status->bytesIn - $user->last_cumulative_bytes_in);
            $deltaOut = max(0, $status->bytesOut - $user->last_cumulative_bytes_out);

            $openLog = $user->connectionLogs()->whereNull('disconnected_at')->latest('connected_at')->first();

            if ($status->connected && $openLog === null) {
                $openLog = ConnectionLog::create([
                    'service_user_id' => $user->id,
                    'connected_at' => $status->lastHandshakeAt ?? $now,
                    'peer_ip' => $status->peerIp,
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
}
