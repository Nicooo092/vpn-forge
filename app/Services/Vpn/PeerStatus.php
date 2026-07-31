<?php

namespace App\Services\Vpn;

use Carbon\CarbonImmutable;

/**
 * A snapshot of one peer's live state, as reported by a driver's
 * pollStatus(). WireGuard has no real connect/disconnect event -- this is
 * how a scheduled job infers session boundaries from handshake age instead.
 * OpenVPN's driver populates the same shape from its client-connect /
 * client-disconnect script hooks.
 */
final class PeerStatus
{
    public function __construct(
        public readonly string $tunnelIp,
        public readonly bool $connected,
        public readonly ?CarbonImmutable $lastHandshakeAt,
        public readonly ?string $peerIp,
        public readonly int $bytesIn,
        public readonly int $bytesOut,
    ) {}
}
