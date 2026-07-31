<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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

        foreach (self::PATHS as $source) {
            $this->addDirectory($zip, $source);
        }

        $zip->addFromString('README.txt', $this->readme());
        $zip->close();

        // Group-readable so the web process can serve it; the directory is
        // setgid to a group both accounts belong to. Never world-readable --
        // this file contains every private key on the box.
        @chmod($path, 0640);

        return $path;
    }

    private function databaseDump(): string
    {
        $result = Process::run([
            'mysqldump',
            '--host='.config('database.connections.mariadb.host'),
            '--port='.config('database.connections.mariadb.port'),
            '--user='.config('database.connections.mariadb.username'),
            '--password='.config('database.connections.mariadb.password'),
            '--single-transaction',
            '--no-tablespaces',
            config('database.connections.mariadb.database'),
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }

        return $result->output();
    }

    private function addDirectory(ZipArchive $zip, string $source): void
    {
        if (! File::isDirectory($source)) {
            return; // A service of that protocol was never provisioned.
        }

        $prefix = ltrim($source, '/');

        foreach (File::allFiles($source, hidden: true) as $file) {
            // Sockets and other non-regular files under these directories
            // cannot be read into an archive and are not worth keeping.
            if (! $file->isFile()) {
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix.'/'.$file->getRelativePathname());
        }
    }

    private function readme(): string
    {
        return <<<'TXT'
        vpn-forge backup

        Contents:
          database.sql            full dump of the panel database
          etc/wireguard/          server keys and peer lists
          etc/openvpn/vpnforge/   the certificate authority, server and client
                                  certificates, and the revocation list
          etc/vpnforge/           dnsmasq configuration and the agent config

        This archive contains private keys and the database password in the
        clear. Store it somewhere you would store those directly, and delete
        it from the server once you have copied it off.

        To restore: put the directories back where they came from with their
        original ownership, import database.sql, then re-run provisioning for
        each service from the panel.
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
}
