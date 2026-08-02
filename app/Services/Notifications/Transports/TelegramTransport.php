<?php

namespace App\Services\Notifications\Transports;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class TelegramTransport implements NotificationTransport
{
    public function send(array $config, string $title, string $message): void
    {
        $token = (string) ($config['bot_token'] ?? '');
        $chatId = (string) ($config['chat_id'] ?? '');

        // The token lands in the request path, so it is checked to be exactly
        // the "<digits>:<token>" shape a real bot token has -- nothing that
        // could reshape the URL.
        if (preg_match('/\A\d+:[A-Za-z0-9_-]+\z/', $token) !== 1) {
            throw new InvalidArgumentException('Telegram bot token is not in the expected <id>:<token> form.');
        }

        Http::timeout(8)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "{$title}\n{$message}",
                'disable_web_page_preview' => true,
            ])
            ->throw();
    }
}
