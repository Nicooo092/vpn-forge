<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often a user's allowance window rolls forward on its own. Rolling forward
 * moves quota_started_at rather than deleting samples, so usage re-counts from
 * zero without losing any history.
 */
enum QuotaReset: string implements HasLabel
{
    case None = 'none';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('No automatic reset'),
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
        };
    }
}
