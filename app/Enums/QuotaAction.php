<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What happens to a user who exhausts their data allowance: cut them off (the
 * original behaviour) or slow them to a low speed while leaving them connected.
 */
enum QuotaAction: string implements HasLabel
{
    case Suspend = 'suspend';
    case Throttle = 'throttle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Suspend => __('Suspend'),
            self::Throttle => __('Throttle to a low speed'),
        };
    }
}
