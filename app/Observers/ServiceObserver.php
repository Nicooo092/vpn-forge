<?php

namespace App\Observers;

use App\Enums\NotificationEvent;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Notifications\Notifier;

class ServiceObserver
{
    /**
     * Alert only on the transition into error, not on every save while a
     * service stays in error -- wasChanged('status') is false when the status
     * did not actually move, so a stuck service notifies once, not every poll.
     */
    public function updated(Service $service): void
    {
        if ($service->wasChanged('status') && $service->status === ServiceStatus::Error) {
            app(Notifier::class)->event(
                NotificationEvent::ServiceError,
                "Service {$service->name} is in error",
                $service->last_error ?: 'No further detail was recorded.',
            );
        }
    }
}
