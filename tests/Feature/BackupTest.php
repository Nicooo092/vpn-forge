<?php

namespace Tests\Feature;

use App\Jobs\Maintenance\CreateBackup;
use App\Services\Backup\BackupArchive;
use App\Services\Backup\RestoreArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_upload_offsite_streams_the_archive_to_the_disk(): void
    {
        Storage::fake('offsite');

        $tmp = tempnam(sys_get_temp_dir(), 'bak');
        File::put($tmp, 'ZIP-CONTENTS');

        (new BackupArchive)->uploadOffsite($tmp, 'offsite');

        Storage::disk('offsite')->assertExists('vpnforge/'.basename($tmp));
        $this->assertSame('ZIP-CONTENTS', Storage::disk('offsite')->get('vpnforge/'.basename($tmp)));

        @unlink($tmp);
    }

    public function test_create_backup_prunes_and_skips_offsite_when_unconfigured(): void
    {
        config()->set('vpnforge.backup.keep', 5);
        config()->set('vpnforge.backup.offsite_disk', null);

        $archive = Mockery::mock(BackupArchive::class);
        $archive->shouldReceive('create')->once()->andReturn('/var/backups/vpnforge/x.zip');
        $archive->shouldReceive('prune')->once()->with(5);
        $archive->shouldReceive('uploadOffsite')->never();

        (new CreateBackup)->handle($archive);
    }

    public function test_create_backup_uploads_offsite_when_configured(): void
    {
        config()->set('vpnforge.backup.offsite_disk', 's3');

        $archive = Mockery::mock(BackupArchive::class);
        $archive->shouldReceive('create')->once()->andReturn('/var/backups/vpnforge/x.zip');
        $archive->shouldReceive('prune')->once();
        $archive->shouldReceive('uploadOffsite')->once()->with('/var/backups/vpnforge/x.zip', 's3');

        (new CreateBackup)->handle($archive);
    }

    public function test_a_failed_offsite_upload_does_not_fail_the_backup(): void
    {
        config()->set('vpnforge.backup.offsite_disk', 's3');

        $archive = Mockery::mock(BackupArchive::class);
        $archive->shouldReceive('create')->once()->andReturn('/var/backups/vpnforge/x.zip');
        $archive->shouldReceive('prune')->once();
        $archive->shouldReceive('uploadOffsite')->once()->andThrow(new \RuntimeException('network down'));

        // The local backup already succeeded, so this must not throw.
        (new CreateBackup)->handle($archive);

        $this->assertTrue(true);
    }

    public function test_openssl_command_is_pure_and_keeps_the_passphrase_out_of_argv(): void
    {
        $encrypt = BackupArchive::opensslCommand('encrypt', '/b/vpnforge-x.zip', '/b/vpnforge-x.zip.enc');

        $this->assertSame([
            'openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-salt',
            '-in', '/b/vpnforge-x.zip', '-out', '/b/vpnforge-x.zip.enc',
            '-pass', 'env:'.BackupArchive::PASS_ENV,
        ], $encrypt);

        $decrypt = BackupArchive::opensslCommand('decrypt', '/b/vpnforge-x.zip.enc', '/b/vpnforge-x.zip');

        // Decryption is the same invocation plus -d, reading the .enc.
        $this->assertContains('-d', $decrypt);
        $this->assertSame('/b/vpnforge-x.zip.enc', $decrypt[array_search('-in', $decrypt, true) + 1]);

        // The secret is handed over through the environment, never on argv.
        $joined = implode(' ', array_merge($encrypt, $decrypt));
        $this->assertStringNotContainsString('pass:', $joined);
        $this->assertStringContainsString('env:'.BackupArchive::PASS_ENV, $joined);
    }

    public function test_restoring_an_encrypted_archive_without_a_passphrase_fails_loudly(): void
    {
        config()->set('vpnforge.backup.passphrase', null);

        // A stand-in for an encrypted archive: restore must refuse it before
        // touching the database, not fail silently or half-restore.
        $enc = sys_get_temp_dir().'/vpnforge-enc-test-'.uniqid().'.zip.enc';
        File::put($enc, 'CIPHERTEXT');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('VPNFORGE_BACKUP_PASSPHRASE');

            (new RestoreArchive)->restore($enc);
        } finally {
            @unlink($enc);
        }
    }
}
