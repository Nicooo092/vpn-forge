<?php

namespace App\Services\Vpn;

use App\Enums\VpnProtocol;
use App\Services\Vpn\OpenVpn\OpenVpnDriver;
use App\Services\Vpn\WireGuard\WireGuardDriver;

class DriverFactory
{
    public function make(VpnProtocol $protocol): VpnProtocolDriver
    {
        return match ($protocol) {
            VpnProtocol::WireGuard => app(WireGuardDriver::class),
            VpnProtocol::OpenVpn => app(OpenVpnDriver::class),
        };
    }
}
