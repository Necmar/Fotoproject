<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GD and the HEIC converters need real local files. On the local disk the
 * stored file is used directly; on an object-storage disk (later, on a VPS)
 * files are copied to a temp folder and back. Temp files live inside
 * storage/app/tmp because Plesk's open_basedir may block /tmp.
 */
class LocalFiles
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('bora.disk'));
    }

    private function isLocal(): bool
    {
        return config('filesystems.disks.'.config('bora.disk').'.driver') === 'local';
    }

    /** Absolute path of a readable local copy of a stored file. */
    public function localPath(string $diskPath): string
    {
        if ($this->isLocal()) {
            return $this->disk()->path($diskPath);
        }

        $tmp = $this->tempPath(pathinfo($diskPath, PATHINFO_EXTENSION));
        file_put_contents($tmp, $this->disk()->readStream($diskPath));

        return $tmp;
    }

    /** Absolute local path to write a new file that will end up at $diskPath. */
    public function writablePath(string $diskPath): string
    {
        if ($this->isLocal()) {
            $path = $this->disk()->path($diskPath);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            return $path;
        }

        return $this->tempPath(pathinfo($diskPath, PATHINFO_EXTENSION));
    }

    /** Finish a write started with writablePath(); returns the file size. */
    public function commit(string $localPath, string $diskPath): int
    {
        clearstatcache(true, $localPath);
        $size = (int) filesize($localPath);

        if (! $this->isLocal()) {
            $stream = fopen($localPath, 'rb');
            $this->disk()->writeStream($diskPath, $stream);
            is_resource($stream) && fclose($stream);
            @unlink($localPath);
        }

        return $size;
    }

    /** Remove a temp copy created by localPath() (no-op on the local disk). */
    public function release(string $localPath): void
    {
        if (! $this->isLocal() && str_starts_with($localPath, $this->tempDirectory())) {
            @unlink($localPath);
        }
    }

    public function tempPath(string $extension = 'tmp'): string
    {
        $dir = $this->tempDirectory();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.'/'.Str::ulid()->toBase32().'.'.($extension ?: 'tmp');
    }

    public function tempDirectory(): string
    {
        return storage_path('app/tmp');
    }
}
