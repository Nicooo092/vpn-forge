<?php

namespace App\Services\Notifications\Transports;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class NtfyTransport implements NotificationTransport
{
    public function send(array $config, string $title, string $message): void
    {
        $server = rtrim((string) ($config['server'] ?? 'https://ntfy.sh'), '/');
        $topic = (string) ($config['topic'] ?? '');

        // https only: the topic sits in the URL path and is the channel's
        // read/write credential, so it must never cross the network in clear.
        if (! str_starts_with($server, 'https://')) {
            throw new InvalidArgumentException('ntfy server must be an https URL.');
        }

        // The topic is the URL path; keep it to ntfy's own allowed charset so
        // it cannot walk the path or add a query.
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $topic) !== 1) {
            throw new InvalidArgumentException('ntfy topic must be letters, digits, - or _.');
        }

        // The title becomes an HTTP header; strip any CR/LF so it can never
        // split into extra headers. (Titles are generated single-line anyway.)
        $safeTitle = trim(str_replace(["\r", "\n"], ' ', $title));

        Http::timeout(8)
            ->withHeaders(['Title' => $safeTitle])
            ->withBody($message, 'text/plain')
            ->post("{$server}/{$topic}")
            ->throw();
    }
}
