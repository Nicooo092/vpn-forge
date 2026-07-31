<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VpnProtocol: string implements HasLabel
{
    case WireGuard = 'wireguard';
    case OpenVpn = 'openvpn';

    public function getLabel(): string
    {
        return match ($this) {
            self::WireGuard => 'WireGuard',
            self::OpenVpn => 'OpenVPN',
        };
    }
}
