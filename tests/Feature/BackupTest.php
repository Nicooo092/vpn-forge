<?php

namespace Tests\Feature;

use App\Jobs\Maintenance\CreateBackup;
use App\Services\Backup\BackupArchive;
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
}
