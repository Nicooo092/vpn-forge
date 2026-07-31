<?php

namespace App\Jobs\Vpn;

use App\Models\ServiceUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reissues one user's key material. On the `system` queue like every other
 * job that touches key material or the running interface.
 */
class RotateUserKeys implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public ServiceUser $user)
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        $service = $this->user->service;

        $service->driver()->rotateUserKeys($service, $this->user);

        // The cached copy the panel hands out still holds the old key, and
        // is what both the download button and the QR code read.
        $this->user->refresh()->refreshRenderedClientConfig();
    }
}
