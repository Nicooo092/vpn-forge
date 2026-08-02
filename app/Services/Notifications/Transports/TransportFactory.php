<?php

namespace App\Services\Notifications\Transports;

use InvalidArgumentException;

class TransportFactory
{
    public function for(string $type): NotificationTransport
    {
        return match ($type) {
            'discord' => new DiscordTransport,
            'telegram' => new TelegramTransport,
            'ntfy' => new NtfyTransport,
            'email' => new EmailTransport,
            default => throw new InvalidArgumentException("Unknown notification channel type: {$type}"),
        };
    }
}
