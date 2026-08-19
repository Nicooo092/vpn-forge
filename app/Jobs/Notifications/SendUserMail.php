<?php

namespace App\Jobs\Notifications;

use App\Enums\UserMailType;
use App\Mail\AccessExpiringMail;
use App\Mail\NewDeviceLocationMail;
use App\Models\ServiceUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers one account email to the end user themselves (not the operator).
 * On the `system` queue alongside the jobs that raise it. Every gate lives
 * here so the callers stay a plain fire-and-forget dispatch:
 *
 *  - the whole channel can be turned off (vpnforge.user_mail.enabled);
 *  - the user must actually have a valid email address;
 *  - a real mailer must be configured -- with MAIL_MAILER left at 'log' (the
 *    shipped default) or empty there is nowhere to send, so it degrades
 *    silently rather than logging or throwing;
 *  - de-duplicated per user per event window via a cache key, the same way the
 *    operator quota/expiry warnings are, so a repeated enforcement pass does
 *    not re-send.
 *
 * Only the coarse facts a user needs about their OWN account travel here -- an
 * expiry date, the /24 a new connection came from. No browsing or traffic
 * detail is ever emailed.
 */
class SendUserMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // If the user was deleted between dispatch and run, quietly drop the job
    // rather than letting SerializesModels fail it into failed_jobs.
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<string, string>  $context
     */
    public function __construct(
        public ServiceUser $user,
        public UserMailType $type,
        public string $dedupKey,
        public array $context = [],
    ) {
        $this->onQueue('system');
    }

    public function handle(): void
    {
        if (! config('vpnforge.user_mail.enabled', true)) {
            return;
        }

        $email = (string) ($this->user->email ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        if (! self::mailConfigured()) {
            return;
        }

        // One send per user per event window. add() is atomic, so even two
        // overlapping runs cannot both get through.
        if (! Cache::add($this->dedupKey, true, now()->addDays($this->type->dedupDays()))) {
            return;
        }

        Mail::to($email)->send($this->mailable());
    }

    private function mailable(): Mailable
    {
        return match ($this->type) {
            UserMailType::AccessExpiring => new AccessExpiringMail($this->user),
            UserMailType::NewDeviceLocation => new NewDeviceLocationMail(
                $this->user,
                (string) ($this->context['network'] ?? ''),
            ),
        };
    }

    /**
     * Whether a real mailer is configured. With MAIL_MAILER at 'log' (the
     * shipped default) or empty there is nowhere to deliver, so the whole
     * feature stays quiet. 'array' is only ever the test transport.
     */
    public static function mailConfigured(): bool
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $mailer !== '' && ! in_array($mailer, ['log', 'array'], true);
    }
}
