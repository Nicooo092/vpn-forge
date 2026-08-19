<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

class ServiceUserPolicy
{
    use ReadOnlyForAuditors;
}
