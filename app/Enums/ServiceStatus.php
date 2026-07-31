<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceStatus: string implements HasColor, HasLabel
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Provisioning => 'Provisioning',
            self::Active => 'Active',
            self::Error => 'Error',
            self::Disabled => 'Disabled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Provisioning => 'warning',
            self::Active => 'success',
            self::Error => 'danger',
            self::Disabled => 'gray',
        };
    }
}
