<?php

namespace App\Services;

use App\Services\Mail\MailSettings;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime settings managed by the Super Admin, backed by the system_settings
 * table with defaults from config('bora.system_defaults'). Cached forever and
 * flushed on every write, so reads are cheap on shared hosting.
 */
class SystemSettings
{
    private const CACHE_KEY = 'bora.system_settings';

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, function () {
            return SystemSetting::query()->pluck('value', 'key')->all();
        });

        $defaults = config('bora.system_defaults', []);

        return $this->resolved = array_replace($defaults, array_intersect_key($stored, $defaults));
    }

    /** @param array<string, mixed> $values Only known keys are stored. */
    public function update(array $values): void
    {
        $known = array_intersect_key($values, config('bora.system_defaults', []));

        foreach ($known as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    /** Whether the app sends e-mail at all (see config bora.mail_enabled). */
    public function mailEnabled(): bool
    {
        return app(MailSettings::class)->isEnabled();
    }

    public function retentionDays(): int
    {
        return (int) $this->get('retention_days', 7);
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->get('registration_enabled', false);
    }

    public function jpgQuality(): int
    {
        $limits = config('bora.limits');

        return max($limits['jpg_quality_min'], min($limits['jpg_quality_max'], (int) $this->get('jpg_quality', 88)));
    }

    public function maxImagesPerBatch(): int
    {
        return min((int) config('bora.limits.max_images_per_batch'), (int) $this->get('max_images_per_batch', 30));
    }

    public function maxUploadMb(): int
    {
        return (int) $this->get('max_upload_mb', 25);
    }
}
