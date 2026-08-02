<?php

namespace App\Jobs\Blocklist;

use App\Enums\ServiceStatus;
use App\Models\Blocklist;
use App\Models\Service;
use App\Services\Blocklist\BlocklistCompiler;
use App\Services\Blocklist\BlocklistParser;
use App\Services\Vpn\DnsmasqManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches every enabled subscription, merges them into the one hosts file the
 * resolvers read, and restarts the resolvers so the change takes effect. Runs
 * on the privileged worker: it needs to write under /etc/vpnforge and to
 * restart the per-service dnsmasq units. One bad source never blocks the rest
 * -- its error is recorded against the row and the others still compile.
 */
class RefreshBlocklists implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(BlocklistCompiler $compiler, DnsmasqManager $dnsmasq): void
    {
        $all = [];

        foreach (Blocklist::where('enabled', true)->get() as $list) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'vpn-forge'])
                    ->timeout(60)
                    ->get($list->url);

                if (! $response->successful()) {
                    throw new \RuntimeException("HTTP {$response->status()}");
                }

                $domains = BlocklistParser::parse($response->body());
                $all = [...$all, ...$domains];

                $list->forceFill([
                    'domain_count' => count($domains),
                    'last_fetched_at' => now(),
                    'last_error' => null,
                ])->save();
            } catch (Throwable $e) {
                $list->forceFill(['last_error' => $e->getMessage()])->save();
            }
        }

        $merged = array_values(array_unique($all));

        $compiler->write($merged);

        // Re-provision each opted-in resolver rather than only restarting it:
        // provisioning is what writes the addn-hosts line into the config in
        // the first place, so this is self-healing -- a service provisioned
        // before blocklists existed picks up the line here -- and it restarts
        // the unit anyway, which is what makes it re-read the new file. One
        // failing service never blocks the rest.
        Service::query()
            ->where('status', ServiceStatus::Active)
            ->get()
            ->filter(fn (Service $service) => ($service->config['use_blocklists'] ?? true))
            ->each(function (Service $service) use ($dnsmasq) {
                try {
                    $dnsmasq->provision($service);
                } catch (Throwable) {
                    // Interface down, unit wedged, etc. -- the next refresh
                    // (or a config change) will bring it into line.
                }
            });
    }
}
