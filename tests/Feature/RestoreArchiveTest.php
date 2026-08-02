<?php

namespace Tests\Feature;

use App\Services\Backup\RestoreArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class RestoreArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeArchive(): string
    {
        $path = sys_get_temp_dir().'/vpnforge-test-'.Str::random(8).'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sql', "SELECT 1;\n");
        $zip->addFromString('etc/wireguard/wg0.conf', "[Interface]\nPrivateKey = X\n");
        $zip->addFromString('etc/openvpn/vpnforge/private/server.key', 'KEYDATA');
        $zip->close();

        return $path;
    }

    public function test_it_imports_the_database_and_restores_the_directories(): void
    {
        Process::fake();
        $archive = $this->makeArchive();
        $root = sys_get_temp_dir().'/vpnforge-root-'.Str::random(8);

        (new RestoreArchive($root))->restore($archive);

        // The database dump was piped to mysql.
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'mysql'));

        // The key material landed under the (temp) root.
        $this->assertFileExists($root.'/etc/wireguard/wg0.conf');
        $this->assertFileExists($root.'/etc/openvpn/vpnforge/private/server.key');

        File::deleteDirectory($root);
        @unlink($archive);
    }

    public function test_it_mirrors_directories_removing_files_absent_from_the_archive(): void
    {
        Process::fake();
        $archive = $this->makeArchive(); // has etc/wireguard/wg0.conf
        $root = sys_get_temp_dir().'/vpnforge-root-'.Str::random(8);

        // A stale file already on disk that the archive does not contain -- e.g.
        // a certificate revoked since the backup was taken.
        File::ensureDirectoryExists($root.'/etc/wireguard');
        File::put($root.'/etc/wireguard/ghost.conf', 'stale');

        (new RestoreArchive($root))->restore($archive);

        $this->assertFileExists($root.'/etc/wireguard/wg0.conf');
        $this->assertFileDoesNotExist($root.'/etc/wireguard/ghost.conf');

        File::deleteDirectory($root);
        @unlink($archive);
    }

    public function test_a_missing_archive_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new RestoreArchive('/tmp/whatever'))->restore('/no/such/archive.zip');
    }

    public function test_a_failed_database_import_throws(): void
    {
        Process::fake(['mysql*' => Process::result(exitCode: 1, errorOutput: 'access denied')]);
        $archive = $this->makeArchive();
        $root = sys_get_temp_dir().'/vpnforge-root-'.Str::random(8);

        try {
            $this->expectException(RuntimeException::class);
            (new RestoreArchive($root))->restore($archive);
        } finally {
            File::deleteDirectory($root);
            @unlink($archive);
        }
    }
}
