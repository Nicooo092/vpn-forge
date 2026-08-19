<?php

namespace App\Console\Commands\Maintenance;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The external half of the box's health story. Every other alert in the panel
 * is raised by the privileged worker -- which means a box where the worker
 * itself is dead goes silent exactly when something is most wrong. This command
 * runs on the SCHEDULER (www-data), independent of the worker, and pings a push
 * URL an off-host monitor (healthchecks.io, Uptime Kuma, ...) watches: a missed
 * ping is the monitor's signal that the whole box has gone dark.
 *
 * It is deliberately a no-op unless a URL is configured, and a failed ping never
 * fails the run -- surfacing the outage is the remote monitor's job, not ours.
 */
#[Signature('vpnforge:heartbeat')]
#[Description('Ping the configured external heartbeat URL so a fully-dead box is detected off-host')]
class PingHeartbeat extends Command
{
    public function handle(): int
    {
        $url = trim((string) config('vpnforge.heartbeat.url', ''));

        // Opt-in: with no URL there is nothing to ping, and that is fine.
        if ($url === '') {
            return self::SUCCESS;
        }

        if (config('vpnforge.heartbeat.include_status', true)) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($this->status());
        }

        try {
            Http::timeout(10)->get($url)->throw();
        } catch (Throwable $e) {
            // A missed ping is what the monitor alerts on; leave a breadcrumb
            // but never fail the scheduler run over an unreachable endpoint --
            // and never log the raw message: this URL is the feature's only
            // secret (its path carries the push token), and a connection error
            // embeds the full request URI verbatim.
            Log::warning('External heartbeat ping failed: '.$this->safeError($e));
        }

        return self::SUCCESS;
    }

    /**
     * A log-safe description of a ping failure: an HTTP error is reduced to its
     * status, and any URL in any other message is redacted -- so the secret
     * heartbeat URL never reaches laravel.log. Mirrors the redaction the
     * notification transport already applies to its endpoint errors.
     */
    private function safeError(Throwable $e): string
    {
        $message = $e instanceof RequestException
            ? 'HTTP '.($e->response?->status() ?? '?')
            : $e->getMessage();

        return Str::limit((string) preg_replace('#https?://\S+#i', '[endpoint]', $message), 200);
    }

    /**
     * A compact, secret-free status line appended to the ping: how many services
     * are active out of the total, and how long ago the privileged worker last
     * checked in (or "down" if it never has). Nothing here identifies a user.
     *
     * @return array<string, string>
     */
    private function status(): array
    {
        $last = Cache::get('vpnforge:worker-heartbeat');
        $worker = $last === null
            ? 'down'
            : (string) max(0, now()->timestamp - (int) $last);

        return [
            'services' => Service::where('status', ServiceStatus::Active)->count().'/'.Service::count(),
            'worker' => $worker,
        ];
    }
}
