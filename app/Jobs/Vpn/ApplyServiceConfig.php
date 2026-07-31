<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
use App\Enums\ServiceUserStatus;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyServiceConfig implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public Service $service)
    {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        try {
            $this->service->driver()->applyServiceConfig($this->service);

            $this->service->forceFill([
                'status' => ServiceStatus::Active,
                'last_error' => null,
            ])->save();

            // A service-level change (DNS, AllowedIPs, endpoint host, ...)
            // can change what each user's client config should contain --
            // re-cache every still-valid user's config rather than leaving
            // stale copies around from before this change.
            $this->service->serviceUsers()
                ->where('status', ServiceUserStatus::Active)
                ->get()
                ->each(fn ($user) => $user->refreshRenderedClientConfig());
        } catch (Throwable $e) {
            $this->service->forceFill([
                'status' => ServiceStatus::Error,
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
