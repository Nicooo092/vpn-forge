<?php

namespace App\Jobs\Maintenance;

use App\Enums\NotificationEvent;
use App\Services\Backup\RestoreArchive;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Restores a backup archive on the privileged worker -- the only account that
 * can write /etc/wireguard and the OpenVPN PKI. Destructive by nature: it
 * overwrites the database and the on-disk key material with the archive's.
 */
class RestoreBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $archivePath)
    {
        $this->onQueue('system');
    }

    public function handle(RestoreArchive $restore): void
    {
        try {
            $restore->restore($this->archivePath);
            Log::info('backup restored from '.$this->archivePath);
        } catch (Throwable $e) {
            Log::error('restore failed: '.$e->getMessage());

            // A failure here can leave a half-restored box, so make it loud.
            app(Notifier::class)->event(
                NotificationEvent::BackupFailed,
                'Restore FAILED',
                'The server may be half-restored -- check before rebooting. '.$e->getMessage(),
            );

            throw $e;
        }
    }
}
