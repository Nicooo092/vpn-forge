<?php

namespace App\Services\Notifications\Transports;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DiscordTransport implements NotificationTransport
{
    public function send(array $config, string $title, string $message): void
    {
        $url = (string) ($config['webhook_url'] ?? '');

        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Discord webhook URL must be an https URL.');
        }

        Http::timeout(8)
            ->post($url, ['content' => "**{$title}**\n{$message}"])
            ->throw();
    }
}
