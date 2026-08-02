<?php

namespace App\Services\Blocklist;

use App\Services\Vpn\NetworkInput;

/**
 * Turns the raw body of a public blocklist into a clean list of domains,
 * whatever shape it arrived in -- a hosts file (`0.0.0.0 domain`), a plain
 * domain-per-line list, or the simple `||domain^` rules of an AdGuard/adblock
 * filter. Anything that is not a bare domain after that -- comments, cosmetic
 * or exception rules, IP literals, single-label junk like `localhost` -- is
 * dropped, so nothing odd can reach the resolver's hosts file.
 *
 * Pure and static: the fetching and the on-disk write live in the job, so the
 * parsing can be asserted on directly.
 */
class BlocklistParser
{
    /**
     * @return list<string>
     */
    public static function parse(string $content): array
    {
        $domains = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);

            // Comments (# hosts, ! adblock) and empty lines.
            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            // Adblock cosmetic rules, exceptions and anything with a path or a
            // wildcard is not a plain domain block -- skip rather than mangle.
            if (str_contains($line, '##') || str_starts_with($line, '@@') || str_contains($line, '/') || str_contains($line, '*')) {
                continue;
            }

            // Trailing inline comment.
            $line = trim(preg_replace('/\s+[#!].*$/', '', $line));

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            // hosts format is "<ip> <domain>" -- take the name, not the IP.
            // A plain list is just the domain.
            $candidate = count($parts) >= 2 ? $parts[1] : $parts[0];

            // Adblock network rule: ||domain.com^$modifiers -> domain.com
            $candidate = preg_replace('/^\|\|/', '', $candidate);
            $candidate = preg_replace('/[\^$].*$/', '', $candidate);

            $domains[] = $candidate;
        }

        // filterBlockedDomains lowercases, trims, validates the hostname shape
        // and de-duplicates. On top of that, drop IP literals and single-label
        // entries (localhost, 0.0.0.0) that would pass the hostname shape but
        // are never real blocklist targets.
        return collect(NetworkInput::filterBlockedDomains($domains))
            ->filter(fn (string $d) => str_contains($d, '.') && filter_var($d, FILTER_VALIDATE_IP) === false)
            ->values()
            ->all();
    }
}
