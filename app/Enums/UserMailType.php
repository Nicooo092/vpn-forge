<?php

namespace App\Enums;

/**
 * The kinds of email a service user can receive about their OWN account. Kept
 * deliberately separate from NotificationEvent, which is the operator-facing
 * alert taxonomy fanned out over the notification channels.
 */
enum UserMailType: string
{
    case AccessExpiring = 'access_expiring';
    case NewDeviceLocation = 'new_device_location';

    /**
     * How long the per-user de-dup key lives, in days. Expiry is keyed on the
     * expiry timestamp (pushing the date back changes the key and re-arms it),
     * so a long window is safe. A new-network notice re-arms only when a
     * genuinely different /24 appears, so a shorter window lets a real
     * re-appearance from that network notify again later.
     */
    public function dedupDays(): int
    {
        return match ($this) {
            self::AccessExpiring => 120,
            self::NewDeviceLocation => 45,
        };
    }
}
