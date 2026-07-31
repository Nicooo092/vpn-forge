<?php

namespace App\Services\Vpn;

/**
 * The downloadable config file offered to the admin after creating a
 * service user -- a .conf for WireGuard, a .ovpn for OpenVPN. Both
 * protocols give a single self-contained text file, so one small value
 * object covers either.
 */
final class ClientConfigFile
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
    ) {}
}
