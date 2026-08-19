<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

class BlocklistPolicy
{
    use ReadOnlyForAuditors;
}
