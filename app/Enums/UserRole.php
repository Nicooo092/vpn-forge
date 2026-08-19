<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Panel access levels. Admin can do everything; Auditor can see everything the
 * panel holds -- including the traffic logs -- but cannot change anything or
 * reveal key material. The whole point of Auditor is a reviewer who needs
 * visibility without the ability to alter services, users or logging.
 */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Auditor = 'auditor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Auditor => __('Auditor (read-only)'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
