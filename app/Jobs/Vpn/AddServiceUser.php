<?php

namespace App\Jobs\Vpn;

use App\Models\ServiceUser;
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

    public function handle(): void
    {
        $service = $this->serviceUser->service;

        try {
            $service->driver()->addUser($service, $this->serviceUser);
        } catch (Throwable $e) {
            $service->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }
    }
}
