<?php

namespace App\Services\Lockdown;

use App\Models\Setting;
use App\Models\User;

/**
 * The global lockdown flag. Kept in the settings table rather than the cache
 * because a lockdown is a security control: it has to survive a cache:clear, a
 * queue restart and a reboot, and be the same authoritative value for every
 * process. A single row, present only while lockdown is engaged, records who
 * threw the switch and when for the audit trail.
 */
class LockdownManager
{
    private const KEY = 'lockdown';

    public function isEngaged(): bool
    {
        return $this->state() !== null;
    }

    /**
     * @return array{engaged_at: string, engaged_by: int, engaged_by_email: string}|null
     */
    public function state(): ?array
    {
        return Setting::query()->whereKey(self::KEY)->first()?->value;
    }

    public function engage(User $by): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => [
                'engaged_at' => now()->toIso8601String(),
                'engaged_by' => $by->id,
                'engaged_by_email' => $by->email,
            ]],
        );
    }

    public function lift(): void
    {
        Setting::query()->whereKey(self::KEY)->delete();
    }
}
