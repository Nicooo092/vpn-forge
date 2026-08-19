<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

class ServicePolicy
{
    use ReadOnlyForAuditors;
}
