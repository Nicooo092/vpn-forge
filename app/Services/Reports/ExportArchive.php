<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The folder of generated exports -- PDF site reports and the log CSV zips --
 * that the scheduled command writes to and the Exports page lists. Unlike a
 * backup these hold no keys, so they live under the app's own storage owned by
 * the web user; the web process and the (www-data) scheduler both reach them.
 */
class ExportArchive
{
    public function directory(): string
    {
        return storage_path('app/exports');
    }

    public function ensureDirectory(): void
    {
        File::ensureDirectoryExists($this->directory(), 0750);
    }

    public function put(string $filename, string $contents): string
    {
        $this->ensureDirectory();
        $path = $this->directory().'/'.$filename;
        File::put($path, $contents);

        return $path;
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, created_at: int}>
     */
    public function list(): array
    {
        if (! File::isDirectory($this->directory())) {
            return [];
        }

        return collect(File::files($this->directory()))
            ->filter(fn ($file) => in_array($file->getExtension(), ['pdf', 'zip'], true))
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
     * Resolve a browser-supplied name back to a real file by matching it
     * against the listing rather than joining it onto the path -- the same
     * "../.." guard the backups page uses.
     */
    public function resolve(string $name): ?string
    {
        foreach ($this->list() as $file) {
            if ($file['name'] === $name) {
                return $file['path'];
            }
        }

        return null;
    }

    /**
     * Drop exports older than the retention window so the folder does not grow
     * without bound under a schedule.
     */
    public function prune(int $retentionDays): int
    {
        $cutoff = Carbon::now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach ($this->list() as $file) {
            if ($file['created_at'] < $cutoff) {
                File::delete($file['path']);
                $deleted++;
            }
        }

        return $deleted;
    }
}
