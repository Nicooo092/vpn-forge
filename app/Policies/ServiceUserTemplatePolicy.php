<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

/**
 * Read for every operator, write for admins only -- the same two-role shape as
 * every other config resource. An admin never reaches these methods
 * (Gate::before short-circuits to true); they only ever decide an Auditor's
 * access, which is view-only.
 */
class ServiceUserTemplatePolicy
{
    use ReadOnlyForAuditors;
}
