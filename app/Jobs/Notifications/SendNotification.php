<?php

namespace App\Jobs\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\Transports\TransportFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one alert to one channel. On the `system` queue so it shares the
 * worker that raises most events. tries=1 on purpose: a retry could double-send
 * an alert whose first POST actually landed but answered slowly. A failure is
 * recorded on the channel and swallowed rather than piling up in failed_jobs.
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // If the channel was deleted between dispatch and run, quietly drop the
    // job rather than letting SerializesModels fail it into failed_jobs.
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public NotificationChannel $channel,
        public string $title,
        public string $message,
    ) {
        $this->onQueue('system');
    }

    public function handle(TransportFactory $factory): void
    {
        try {
            $factory->for($this->channel->type)->send($this->channel->config ?? [], $this->title, $this->message);

            if ($this->channel->last_error !== null) {
                $this->recordError(null);
            }
        } catch (Throwable $e) {
            $this->recordError($this->safeError($e));
        }
    }

    private function recordError(?string $message): void
    {
        // A database hiccup while recording the outcome must not itself fail
        // the job (which would then land in failed_jobs).
        try {
            $this->channel->forceFill(['last_error' => $message])->save();
        } catch (Throwable) {
            // best effort
        }
    }

    /**
     * Never store the endpoint URL: it carries the secret (a Telegram bot
     * token, a Discord webhook, a private ntfy topic) and last_error is shown
     * in the panel and copied into backups. A transport (HTTP) failure is
     * reduced to its status; any URL in any other message is redacted.
     */
    private function safeError(Throwable $e): string
    {
        $message = $e instanceof RequestException
            ? 'Request failed (HTTP '.($e->response?->status() ?? '?').')'
            : $e->getMessage();

        return Str::limit(preg_replace('#https?://\S+#i', '[endpoint]', $message), 240);
    }
}
