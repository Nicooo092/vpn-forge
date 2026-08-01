<?php

namespace App\Services\Vpn;

use InvalidArgumentException;

/**
 * Validates the handful of service fields that end up inside root-executed
 * shell strings (wg-quick PostUp), systemd unit instance names, filesystem
 * paths and resolver config. These are the values an attacker would target,
 * so validation lives here at the sink -- reached by every write path
 * (the form, queued jobs, tinker, a direct database edit) rather than only
 * the form, which a form rule alone would miss.
 *
 * Everything is allow-list: a value is rejected unless it matches a strict
 * shape, so no shell metacharacter, newline, slash or "../" can survive.
 */
class NetworkInput
{
    /**
     * A Linux network interface name: letters/digits, starts with a letter,
     * max 15 chars (IFNAMSIZ), and -- because this is also a systemd instance
     * name and a path component -- deliberately no dot, dash, slash or space.
     */
    public static function assertInterfaceName(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9]{0,14}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid interface name: {$value}. Use lower-case letters and digits only, e.g. wg0 or tun0."
            );
        }

        return $value;
    }

    /**
     * IPv4 CIDR only. Rejects anything the iptables PostUp string would treat
     * as extra arguments or a command separator.
     */
    public static function assertSubnetCidr(string $value): string
    {
        [$network, $prefix] = array_pad(explode('/', $value), 2, null);

        if ($prefix === null
            || filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! ctype_digit($prefix)
            || (int) $prefix < 0
            || (int) $prefix > 32) {
            throw new InvalidArgumentException("Invalid subnet CIDR: {$value}. Expected something like 10.8.0.0/24.");
        }

        return $value;
    }

    /**
     * An interface name again -- same rules as the tunnel interface, since it
     * lands in the same PostUp string and iptables arguments.
     */
    public static function assertEgressInterface(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9]{0,14}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid egress interface: {$value}. Use lower-case letters and digits only, e.g. eth0 or ens5."
            );
        }

        return $value;
    }

    /**
     * A single IPv4/IPv6 resolver address. Anything else -- a newline that
     * would inject a dnsmasq directive, a hostname, junk -- is dropped.
     */
    public static function filterUpstreams(array $servers): array
    {
        return collect($servers)
            ->map(fn ($server) => trim((string) $server))
            ->filter(fn (string $server) => filter_var($server, FILTER_VALIDATE_IP) !== false)
            ->values()
            ->all();
    }

    /**
     * A DNS name for the blocklist. dnsmasq's address=/NAME/ takes a domain,
     * so anything that is not a plain hostname -- crucially anything with a
     * newline, slash or space -- is dropped, which is what stops a "domain"
     * from smuggling in extra config lines.
     */
    public static function filterBlockedDomains(array $domains): array
    {
        return collect($domains)
            ->map(fn ($domain) => strtolower(trim((string) $domain, " \t\n\r\0\x0B.")))
            ->filter(fn (string $domain) => $domain !== ''
                && preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)*$/', $domain) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
