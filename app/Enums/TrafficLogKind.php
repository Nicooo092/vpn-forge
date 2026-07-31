<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TrafficLogKind: string implements HasLabel
{
    case Dns = 'dns';
    case Http = 'http';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dns => 'DNS query',
            self::Http => 'HTTP request',
        };
    }
}
