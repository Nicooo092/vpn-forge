<?php

namespace App\Jobs\Maintenance;

use App\Enums\NotificationEvent;
use App\Services\Backup\BackupArchive;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * On the `system` queue: the PKI and the WireGuard keys are only readable by
 * the privileged worker, which is the whole point of the split.
 *
 * No completion notification -- the Backups page polls, so the archive
 * simply appears in the list a moment later.
 */
class CreateBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('system');
    }

    public function handle(BackupArchive $archive): void
    {
        try {
            $path = $archive->create();

            // Keep the newest N locally.
            $archive->prune((int) config('vpnforge.backup.keep', 7));
        } catch (Throwable $e) {
            Log::error('backup failed: '.$e->getMessage());

            app(Notifier::class)->event(
                NotificationEvent::BackupFailed,
                'Backup failed',
                $e->getMessage(),
            );

            throw $e;
        }

        // Offsite is best-effort: the local archive already succeeded, so a
        // failed upload is reported but does not fail the backup.
        $offsiteDisk = config('vpnforge.backup.offsite_disk');

        if (filled($offsiteDisk)) {
            try {
                $archive->uploadOffsite($path, $offsiteDisk);
            } catch (Throwable $e) {
                Log::error('offsite backup upload failed: '.$e->getMessage());

                app(Notifier::class)->event(
                    NotificationEvent::BackupFailed,
                    'Offsite backup upload failed',
                    $e->getMessage(),
                );
            }
        }
    }
}
