<?php

namespace App\Services\Notifications\Transports;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class EmailTransport implements NotificationTransport
{
    public function send(array $config, string $title, string $message): void
    {
        $to = (string) ($config['to'] ?? '');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid recipient email address is required.');
        }

        // Uses whatever mailer .env configures. If no SMTP is set up the send
        // throws and the channel records the error -- honest, rather than
        // silently doing nothing.
        Mail::raw($message, function ($mail) use ($to, $title) {
            $mail->to($to)->subject($title);
        });
    }
}
