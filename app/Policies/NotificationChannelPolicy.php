<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForAuditors;

class NotificationChannelPolicy
{
    use ReadOnlyForAuditors;
}
