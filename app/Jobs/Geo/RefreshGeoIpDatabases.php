<?php

namespace App\Jobs\Geo;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-downloads the optional DB-IP Lite geolocation databases in place. Runs on
 * the privileged worker: the target directory (/etc/vpnforge/geoip) is owned by
 * vpnforge-worker, and www-data -- the scheduler that dispatches this -- cannot
 * write there. DB-IP publishes fresh, month-stamped mmdb.gz files at the start
 * of each month, so this fetches the current month and falls back to the
 * previous one when the new file has not landed yet. Entirely best-effort: a
 * failed download leaves the existing database untouched and never fails the
 * run, because a stale geo database is strictly better than none.
 */
class RefreshGeoIpDatabases implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $base = rtrim((string) config('vpnforge.geoip.download_base', 'https://download.db-ip.com/free'), '/');

        $this->refresh($base, 'dbip-country-lite', (string) config('vpnforge.geoip.country_db'));
        $this->refresh($base, 'dbip-asn-lite', (string) config('vpnforge.geoip.asn_db'));
    }

    private function refresh(string $base, string $dataset, string $target): void
    {
        if ($target === '') {
            return;
        }

        // The installer creates this directory with the ownership the worker
        // needs. If it is absent the feature was never provisioned -- do nothing
        // rather than try to create a directory the worker may not own.
        if (! is_dir(dirname($target))) {
            return;
        }

        foreach ([Carbon::now(), Carbon::now()->subMonthNoOverflow()] as $month) {
            $url = "{$base}/{$dataset}-{$month->format('Y-m')}.mmdb.gz";

            try {
                $response = Http::withHeaders(['User-Agent' => 'vpn-forge'])->timeout(120)->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $mmdb = @gzdecode($response->body());

                if ($mmdb === false || $mmdb === '') {
                    continue;
                }

                // Write to a sibling temp file and rename, so a reader never
                // sees a half-written database.
                $tmp = $target.'.tmp';
                file_put_contents($tmp, $mmdb);
                @chmod($tmp, 0o644);
                rename($tmp, $target);

                Log::info("Refreshed GeoIP database {$dataset} from {$url}");

                return;
            } catch (Throwable $e) {
                Log::warning("GeoIP refresh for {$dataset} failed on {$url}: ".$e->getMessage());
            }
        }
    }
}
