<?php

namespace App\Console\Commands\Geo;

use App\Jobs\Geo\RefreshGeoIpDatabases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Queues a refresh of the optional DB-IP Lite geolocation databases. Scheduled
 * monthly (routes/console.php) and runnable by hand. The command itself does no
 * privileged work -- it only dispatches; the actual download runs on the
 * privileged worker (RefreshGeoIpDatabases), which is the only account that can
 * write under /etc/vpnforge/geoip.
 */
#[Signature('vpnforge:geoip-refresh')]
#[Description('Download the latest DB-IP Lite country and ASN databases (queued to the privileged worker)')]
class GeoipRefresh extends Command
{
    public function handle(): int
    {
        RefreshGeoIpDatabases::dispatch();

        $this->info('Queued a GeoIP database refresh on the system queue.');

        return self::SUCCESS;
    }
}
