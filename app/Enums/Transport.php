<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Transport: string implements HasLabel
{
    case Udp = 'udp';
    case Tcp = 'tcp';

    public function getLabel(): string
    {
        return strtoupper($this->value);
    }
}
