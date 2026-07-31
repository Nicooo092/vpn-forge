<?php

namespace App\Services\Vpn;

/**
 * Ready-made upstream resolvers, because "which DNS does this service use"
 * is a per-service decision a company makes deliberately -- filtered
 * resolvers for one set of people, plain ones for another -- and typing
 * addresses from memory is how a digit gets transposed.
 *
 * Custom stays available for anything not listed, including a resolver on
 * the company's own network.
 */
class DnsPresets
{
    public const CUSTOM = 'custom';

    /**
     * @var array<string, array{label: string, servers: list<string>, note: string}>
     */
    private const PRESETS = [
        'cloudflare' => [
            'label' => 'Cloudflare',
            'servers' => ['1.1.1.1', '1.0.0.1'],
            'note' => 'Fast and unfiltered.',
        ],
        'cloudflare-malware' => [
            'label' => 'Cloudflare (blocks malware)',
            'servers' => ['1.1.1.2', '1.0.0.2'],
            'note' => 'Refuses known malware domains.',
        ],
        'cloudflare-family' => [
            'label' => 'Cloudflare (blocks malware and adult content)',
            'servers' => ['1.1.1.3', '1.0.0.3'],
            'note' => 'Adds adult content to the above.',
        ],
        'adguard' => [
            'label' => 'AdGuard (blocks ads and trackers)',
            'servers' => ['94.140.14.14', '94.140.15.15'],
            'note' => 'Filters advertising and tracking domains for every client on the service.',
        ],
        'adguard-family' => [
            'label' => 'AdGuard Family',
            'servers' => ['94.140.14.15', '94.140.15.16'],
            'note' => 'Adds adult content and enforces safe search.',
        ],
        'quad9' => [
            'label' => 'Quad9 (blocks malicious domains)',
            'servers' => ['9.9.9.9', '149.112.112.112'],
            'note' => 'Swiss non-profit, blocks domains known for malware and phishing.',
        ],
        'google' => [
            'label' => 'Google',
            'servers' => ['8.8.8.8', '8.8.4.4'],
            'note' => 'Unfiltered.',
        ],
        'opendns' => [
            'label' => 'OpenDNS',
            'servers' => ['208.67.222.222', '208.67.220.220'],
            'note' => 'Unfiltered. OpenDNS FamilyShield is 208.67.222.123 / 208.67.220.123.',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::PRESETS as $key => $preset) {
            $options[$key] = $preset['label'].' ('.implode(', ', $preset['servers']).')';
        }

        $options[self::CUSTOM] = 'Custom -- enter the addresses yourself';

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function serversFor(string $key): array
    {
        return self::PRESETS[$key]['servers'] ?? [];
    }

    public static function noteFor(?string $key): ?string
    {
        if ($key === self::CUSTOM) {
            return 'Any resolver reachable from the server, including one on your own network. Both queries and answers still pass through this service\'s own resolver first, so traffic logging is unaffected.';
        }

        return self::PRESETS[$key]['note'] ?? null;
    }

    /**
     * Which preset a stored server list corresponds to, so reopening the
     * form shows the choice that was made rather than defaulting back.
     *
     * @param  list<string>  $servers
     */
    public static function keyFor(array $servers): string
    {
        foreach (self::PRESETS as $key => $preset) {
            if ($preset['servers'] === array_values($servers)) {
                return $key;
            }
        }

        return self::CUSTOM;
    }
}
