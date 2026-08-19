<?php

namespace App\Mail;

use App\Models\ServiceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the user (not the operator) when their account connects from a
 * network not seen for them before. Carries only the coarse /24 it came from
 * -- never the exact address, and never any browsing or traffic detail.
 */
class NewDeviceLocationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ServiceUser $user,
        public string $network,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('A new sign-in to your VPN account'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.new-device-location',
            with: [
                'user' => $this->user,
                'network' => $this->network,
            ],
        );
    }
}
