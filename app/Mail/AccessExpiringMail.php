<?php

namespace App\Mail;

use App\Models\ServiceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the user (not the operator) when their access is about to expire.
 * Carries only their name, the service name and the expiry date -- nothing
 * about what they do on the VPN.
 */
class AccessExpiringMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ServiceUser $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your VPN access is expiring soon'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.access-expiring',
            with: [
                'user' => $this->user,
                'expiresAt' => $this->user->expires_at?->toDayDateTimeString(),
            ],
        );
    }
}
