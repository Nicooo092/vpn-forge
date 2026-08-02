<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One subscription to a public blocklist. The rows here are the sources; the
 * merged, de-duplicated domains they compile to live in a single hosts file on
 * disk (see App\Services\Blocklist\BlocklistCompiler) that the resolvers read.
 */
class Blocklist extends Model
{
    protected $fillable = [
        'name',
        'url',
        'enabled',
        'domain_count',
        'last_fetched_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'domain_count' => 'integer',
            'last_fetched_at' => 'datetime',
        ];
    }

    /**
     * Curated starting points offered in the panel, so the common case is a
     * pick rather than hunting down a raw URL. Any other URL still works.
     *
     * @return array<string, array{name: string, url: string}>
     */
    public static function presets(): array
    {
        return [
            'stevenblack' => [
                'name' => 'StevenBlack (ads + malware)',
                'url' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
            ],
            'oisd-small' => [
                'name' => 'OISD Small (ads + tracking)',
                'url' => 'https://small.oisd.nl/domainswild',
            ],
            'hagezi-light' => [
                'name' => 'HaGeZi Light',
                'url' => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/hosts/light.txt',
            ],
            'adguard' => [
                'name' => 'AdGuard DNS filter',
                'url' => 'https://raw.githubusercontent.com/AdguardTeam/FiltersRegistry/master/filters/filter_15_DnsFilter/filter.txt',
            ],
        ];
    }
}
