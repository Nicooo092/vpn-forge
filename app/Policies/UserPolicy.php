<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

/**
 * Managing panel accounts is admin-only. An Auditor may see the list (it is the
 * answer to "who can read the traffic logs") but not add, edit or remove
 * accounts. The guard against removing or demoting the last admin lives in the
 * UI, because Gate::before grants an admin every ability before a policy method
 * is ever consulted.
 */
class UserPolicy
{
    use ReadOnlyForAuditors;
}
