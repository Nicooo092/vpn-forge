<?php

namespace App\Jobs\Vpn;

use App\Models\ServiceUser;
use App\Services\Vpn\TrafficShaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AddServiceUser implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public ServiceUser $serviceUser)
    {
        $this->onQueue('system');
    }

    public function handle(TrafficShaper $shaper): void
    {
        $service = $this->serviceUser->service;

        try {
            $service->driver()->addUser($service, $this->serviceUser);
            $this->serviceUser->refreshRenderedClientConfig();

            // A user created with a speed limit needs the tc rules put in place;
            // with none it is a no-op teardown.
            $shaper->apply($service);
        } catch (Throwable $e) {
            $service->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }
    }
}
