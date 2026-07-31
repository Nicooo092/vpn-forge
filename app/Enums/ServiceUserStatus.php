<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceUserStatus: string implements HasColor, HasLabel
{
    case Active = 'active';

    /**
     * Blocked but recoverable: the keys and certificate are kept, so turning
     * the user back on is a switch rather than a re-issue. Revoked is the
     * permanent one -- for OpenVPN it burns the certificate serial, and there
     * is no way back from it.
     */
    case Suspended = 'suspended';

    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Revoked => 'danger',
        };
    }
}
