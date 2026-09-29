<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;

/**
 * The deployed code version. Taken from a VERSION file when present, otherwise
 * a fingerprint of what an update changes (migrations, routes, React build,
 * composer.lock). `bora:deploy` compares it with the last applied version and
 * then runs the update steps. Without SSH this is how updates apply.
 */
class DeployState
{
    public const KEY = 'bora:deploy:state';

    public function availableVersion(): ?string
    {
        $file = base_path('VERSION');
        if (is_file($file) && ($version = trim((string) file_get_contents($file))) !== '') {
            return $version;
        }

        return $this->fingerprint();
    }

    /** Changes whenever uploaded code needs migrations or fresh caches. */
    public function fingerprint(): ?string
    {
        $parts = array_map(fn (string $f) => basename($f), glob(database_path('migrations/*.php')) ?: []);
        foreach ([base_path('composer.lock'), public_path('build/manifest.json'), base_path('routes/api.php'), base_path('routes/web.php')] as $file) {
            $parts[] = is_file($file) ? md5_file($file) : '-';
        }

        return 'auto-'.substr(md5(implode('|', $parts)), 0, 12);
    }

    public function appliedVersion(): ?string
    {
        $file = $this->appliedFile();

        return is_file($file) ? (trim((string) file_get_contents($file)) ?: null) : null;
    }

    public function markApplied(string $version): void
    {
        file_put_contents($this->appliedFile(), $version);
        Cache::forever(self::KEY, ['version' => $version, 'status' => 'applied', 'at' => now()->toIso8601String()]);
    }

    public function markFailed(string $version, string $step): void
    {
        Cache::forever(self::KEY, ['version' => $version, 'status' => 'failed', 'step' => $step, 'at' => now()->toIso8601String()]);
    }

    private function appliedFile(): string
    {
        return storage_path('app/deployed-version');
    }
}
