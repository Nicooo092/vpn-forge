<?php

namespace App\Services\Notifications\Transports;

/**
 * One way of delivering an alert. Implementations throw on failure; the
 * SendNotification job turns that into a recorded last_error on the channel.
 */
interface NotificationTransport
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function send(array $config, string $title, string $message): void;
}
