<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

class AdminIpRulePolicy
{
    use ReadOnlyForAuditors;
}
