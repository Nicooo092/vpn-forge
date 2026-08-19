<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything needed to rebuild this installation somewhere else: the
 * database, and the on-disk material the database cannot regenerate --
 * above all the OpenVPN PKI, where losing ca.key means every certificate
 * ever issued becomes unverifiable and every client has to be re-enrolled.
 *
 * Runs in the privileged worker, because none of these paths are readable
 * by the web process.
 */
class BackupArchive
{
    public const DIRECTORY = '/var/backups/vpnforge';

    /**
     * @var list<string>
     */
    private const PATHS = [
        '/etc/wireguard',
        '/etc/openvpn/vpnforge',
        '/etc/vpnforge',
    ];

    public function create(): string
    {
        File::ensureDirectoryExists(self::DIRECTORY, 0770);

        $stamp = now()->format('Y-m-d_His');
        $path = self::DIRECTORY."/vpnforge-{$stamp}.zip";

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the archive at {$path}.");
        }

        $zip->addFromString('database.sql', $this->databaseDump());

        // APP_KEY decrypts the sensitive database columns -- client private
        // keys, rendered configs, the 2FA secret and recovery codes -- which
        // exist ONLY in encrypted form in database.sql. Without it, a restore
        // onto a rebuilt host (where `key:generate` has run) leaves all of
        // those permanently undecryptable. Ship the whole .env so a restore is
        // actually complete; this archive is already a plaintext key vault, so
        // it adds no exposure it did not already carry.
        $envPath = base_path('.env');

        if (File::exists($envPath)) {
            $zip->addFromString('env', File::get($envPath));
        }

        $skipped = [];

        foreach (self::PATHS as $source) {
            $skipped = [...$skipped, ...$this->addDirectory($zip, $source)];
        }

        $zip->addFromString('README.txt', $this->readme($skipped));

        // ZipArchive only reads the files it was handed when the archive is
        // closed, so an unreadable one fails here rather than at addFile()
        // -- which is why a single 640 file owned by another account used to
        // take the whole backup down with a message naming none of them.
        if (! $zip->close()) {
            throw new RuntimeException('Could not write the archive: '.$zip->getStatusString());
        }

        // Group-readable so the web process can serve it; the directory is
        // setgid to a group both accounts belong to. Never world-readable --
        // this file contains every private key on the box.
        @chmod($path, 0640);

        return $path;
    }

    /**
     * Tables deliberately left out of the dump:
     *  - the queue/cache/session tables, so a restore never resurrects stale
     *    jobs or clobbers the live queue the restore itself runs on;
     *  - the high-volume, 90-day-retention log tables, which are not config to
     *    recover and would balloon the archive (and its memory footprint).
     * A backup is for the configuration and keys; the log export covers logs.
     *
     * @var list<string>
     */
    private const EXCLUDED_TABLES = [
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'traffic_logs', 'connection_logs', 'bandwidth_samples',
    ];

    private function databaseDump(): string
    {
        $database = config('database.connections.mariadb.database');

        $ignore = array_map(
            fn (string $table) => "--ignore-table={$database}.{$table}",
            self::EXCLUDED_TABLES,
        );

        $result = Process::run([
            'mysqldump',
            '--host='.config('database.connections.mariadb.host'),
            '--port='.config('database.connections.mariadb.port'),
            '--user='.config('database.connections.mariadb.username'),
            '--password='.config('database.connections.mariadb.password'),
            '--single-transaction',
            '--no-tablespaces',
            ...$ignore,
            $database,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }

        return $result->output();
    }

    /**
     * @return list<string> paths deliberately left out, to be reported
     */
    private function addDirectory(ZipArchive $zip, string $source): array
    {
        if (! File::isDirectory($source)) {
            return []; // A service of that protocol was never provisioned.
        }

        $prefix = ltrim($source, '/');
        $skipped = [];

        foreach (File::allFiles($source, hidden: true) as $file) {
            // Sockets and other non-regular files under these directories
            // cannot be read into an archive and are not worth keeping.
            if (! $file->isFile()) {
                continue;
            }

            // The agent's config belongs to the capture agent's own account
            // and is unreadable here by design. Skip what cannot be read and
            // say so, rather than failing the entire backup over it.
            if (! is_readable($file->getPathname())) {
                $skipped[] = $file->getPathname();

                continue;
            }

            $zip->addFile($file->getPathname(), $prefix.'/'.$file->getRelativePathname());
        }

        return $skipped;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function readme(array $skipped): string
    {
        $omitted = $skipped === []
            ? ''
            : "\n\nNot included, because the backup process cannot read them:\n  ".implode("\n  ", $skipped)
                ."\nCopy these by hand as root if you need them.\n";

        return $this->readmeBody().$omitted;
    }

    private function readmeBody(): string
    {
        return <<<'TXT'
        vpn-forge backup

        Contents:
          database.sql            full dump of the panel database
          env                      the .env file, including APP_KEY
          etc/wireguard/          server keys and peer lists
          etc/openvpn/vpnforge/   the certificate authority, server and client
                                  certificates, and the revocation list
          etc/vpnforge/           dnsmasq configuration and the agent config

        This archive contains private keys, APP_KEY and the database password
        in the clear. Store it somewhere you would store those directly, and
        delete it from the server once you have copied it off.

        To restore onto a rebuilt server:
          1. Copy `env` back to the app directory as .env (as root), keeping
             its original ownership -- APP_KEY here is what decrypts the client
             private keys, rendered configs and 2FA secrets held in the
             database. A fresh key:generate would make all of those unreadable.
          2. Put the etc/ directories back where they came from with their
             original ownership.
          3. Import database.sql.
          4. Re-run provisioning for each service from the panel.
        TXT;
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, created_at: int}>
     */
    public function list(): array
    {
        if (! File::isDirectory(self::DIRECTORY)) {
            return [];
        }

        return collect(File::files(self::DIRECTORY))
            ->filter(fn ($file) => $file->getExtension() === 'zip')
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'created_at' => $file->getMTime(),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Keep the newest $keep archives, delete the rest. Returns how many were
     * removed. Only ever touches local archives -- an offsite copy has its own
     * lifecycle on the remote.
     */
    public function prune(int $keep): int
    {
        // Never below 1: a keep of 0 (or negative) would otherwise delete every
        // archive, including the one just created.
        $stale = array_slice($this->list(), max(1, $keep));

        foreach ($stale as $archive) {
            File::delete($archive['path']);
        }

        return count($stale);
    }

    /**
     * Copy one archive to a configured filesystem disk (S3-compatible, SFTP,
     * ...). Streamed rather than read whole into memory, since the archive
     * carries the database dump and every key on the box.
     */
    public function uploadOffsite(string $path, string $disk): void
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open the archive to upload: {$path}");
        }

        try {
            // The disk may be configured with throw=false (the s3 disk is), so
            // a failed write returns false rather than raising -- turn that back
            // into an exception so a silently-failed upload is actually noticed.
            if (Storage::disk($disk)->writeStream('vpnforge/'.basename($path), $handle) === false) {
                throw new RuntimeException("Offsite upload to disk [{$disk}] failed.");
            }
        } finally {
            fclose($handle);
        }
    }
}
