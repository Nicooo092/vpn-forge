<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * The read-only side of the two-role model. An admin never reaches these --
 * Gate::before (AppServiceProvider) short-circuits every ability to true for an
 * admin -- so these methods only ever decide what an Auditor may do: see
 * everything, change nothing. Filament reads them for both resource pages and
 * the CRUD actions inside relation/record tables, so an Auditor's create/edit/
 * delete controls disappear and a crafted request is refused, not merely hidden.
 */
trait ReadOnlyForAuditors
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, mixed $record): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $record): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, mixed $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, mixed $record): bool
    {
        return false;
    }
}
