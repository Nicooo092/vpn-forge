<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Puts a backup archive back: imports the database dump and restores the
 * on-disk key material (WireGuard keys, the OpenVPN PKI, the resolver + agent
 * config) over the top of what is there.
 *
 * Deliberately does NOT try to bring interfaces back up itself -- that is
 * fragile to do from a job, and a plain reboot does it cleanly (systemd brings
 * wg-quick@/openvpn-server@ back, and vpn:restore-after-boot re-applies the
 * rest). So restore recovers the state; the operator reboots.
 *
 * The target root is injectable so the whole thing can be exercised against a
 * temporary directory in a test rather than the real /etc.
 */
class RestoreArchive
{
    /**
     * @var array<int, string> archive-relative directories to put back under root
     */
    private const PATHS = [
        'etc/wireguard',
        'etc/openvpn/vpnforge',
        'etc/vpnforge',
    ];

    public function __construct(private readonly string $root = '') {}

    public function restore(string $archivePath): void
    {
        if (! File::exists($archivePath)) {
            throw new RuntimeException("Backup archive not found: {$archivePath}");
        }

        $work = sys_get_temp_dir().'/vpnforge-restore-'.Str::random(12);
        File::ensureDirectoryExists($work, 0700);

        try {
            $this->extract($archivePath, $work);
            $this->importDatabase("{$work}/database.sql");
            $this->clearVolatileState();
            $this->restoreDirectories($work);
            $this->tightenKeyPermissions();
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Empty the queue/cache/session tables after the import. New backups no
     * longer dump these (see BackupArchive::EXCLUDED_TABLES), but an older
     * archive may still carry them -- and importing a stale `jobs` table into
     * the very queue this restore runs on would otherwise resurrect old,
     * possibly destructive jobs. Best-effort: a restore must not fail over this.
     */
    private function clearVolatileState(): void
    {
        foreach (['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'] as $table) {
            try {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            } catch (Throwable) {
                // ignore
            }
        }
    }

    private function extract(string $archivePath, string $dest): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Could not open the backup archive.');
        }

        $zip->extractTo($dest);
        $zip->close();
    }

    private function importDatabase(string $sqlPath): void
    {
        if (! File::exists($sqlPath)) {
            throw new RuntimeException('The archive has no database dump.');
        }

        $handle = fopen($sqlPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('Could not read the database dump.');
        }

        // Streamed to mysql's stdin so a large dump is not read into memory.
        $result = Process::input($handle)->run([
            'mysql',
            '--host='.config('database.connections.mariadb.host'),
            '--port='.config('database.connections.mariadb.port'),
            '--user='.config('database.connections.mariadb.username'),
            '--password='.config('database.connections.mariadb.password'),
            config('database.connections.mariadb.database'),
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('Database import failed: '.trim($result->errorOutput()));
        }
    }

    private function restoreDirectories(string $work): void
    {
        foreach (self::PATHS as $relative) {
            $source = "{$work}/{$relative}";

            if (! File::isDirectory($source)) {
                continue; // that protocol was never provisioned when the backup was taken
            }

            $target = $this->root.'/'.$relative;
            File::ensureDirectoryExists($target);
            // Mirror, not merge: empty the target first so files that no longer
            // exist in the archive (a since-revoked certificate, a removed
            // peer) do not survive the restore as invisible ghosts. cleanDirectory
            // keeps the directory itself, preserving its ownership and setgid bit.
            File::cleanDirectory($target);
            File::copyDirectory($source, $target);
        }
    }

    /**
     * Private keys back to 0600 -- a zip preserves no ownership or mode, so
     * restored key material would otherwise land world-readable-ish under the
     * umask.
     */
    private function tightenKeyPermissions(): void
    {
        foreach (['etc/wireguard', 'etc/openvpn/vpnforge'] as $relative) {
            $dir = $this->root.'/'.$relative;

            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::allFiles($dir, hidden: true) as $file) {
                $name = $file->getFilename();
                $path = $file->getPathname();

                if (str_ends_with($name, '.conf')
                    || str_ends_with($name, '.key')
                    || str_contains($path, '/private/')) {
                    @chmod($path, 0600);
                }
            }
        }

        // The private-key directory itself must stay 0700 -- a zip restores no
        // mode, so it would otherwise land 0755 and expose the key filenames
        // (client common names) to any local account.
        $privateDir = $this->root.'/etc/openvpn/vpnforge/pki/private';

        if (File::isDirectory($privateDir)) {
            @chmod($privateDir, 0700);
        }
    }
}
