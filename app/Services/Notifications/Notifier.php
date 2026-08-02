<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Jobs\Notifications\SendNotification;
use App\Models\NotificationChannel;

/**
 * The one entry point for raising an alert. Fans a single event out to every
 * enabled channel subscribed to it, queuing the actual delivery so a slow or
 * unreachable endpoint never blocks the VPN work that raised it.
 */
class Notifier
{
    public function event(NotificationEvent $event, string $title, string $message): void
    {
        $title = trim($event->emoji().' '.$title);

        NotificationChannel::where('enabled', true)
            ->get()
            ->filter(fn (NotificationChannel $channel) => $channel->subscribesTo($event))
            ->each(fn (NotificationChannel $channel) => SendNotification::dispatch($channel, $title, $message));
    }
}
