<?php

namespace App\Jobs\Vpn;

use App\Enums\ServiceStatus;
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
        } catch (Throwable $e) {
            $this->service->forceFill([
                'status' => ServiceStatus::Error,
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
