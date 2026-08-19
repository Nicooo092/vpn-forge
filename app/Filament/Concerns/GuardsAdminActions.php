<?php

namespace App\Filament\Concerns;

use Closure;

/**
 * Helpers for standalone Filament pages whose mutating entry points are plain
 * Livewire methods (not resource actions Filament authorises on its own). Hiding
 * a button is not enough there -- a crafted Livewire call would still reach the
 * method -- so the method itself must refuse a non-admin.
 */
trait GuardsAdminActions
{
    /**
     * A ->visible()/->hidden() closure: shows an action to admins only.
     */
    protected static function adminOnly(): Closure
    {
        return fn (): bool => auth()->user()?->isAdmin() ?? false;
    }

    /**
     * Server-side guard for a Livewire method: 403s a non-admin outright.
     */
    protected function abortUnlessAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }
}
