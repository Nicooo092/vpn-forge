<?php

namespace App\Jobs\Maintenance;

use App\Services\Backup\BackupArchive;
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
            $archive->create();
        } catch (Throwable $e) {
            Log::error('backup failed: '.$e->getMessage());

            throw $e;
        }
    }
}
