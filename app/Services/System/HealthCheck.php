<?php

namespace App\Services\System;

use App\Services\Images\HeicConverter;
use App\Services\Images\MemoryGuard;
use App\Services\Processing\QueueHealth;
use App\Services\Storage\LocalFiles;
use App\Services\SystemSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Installation check for hosting without SSH: PHP version and extensions,
 * limits, writable folders, cron, mail, OpenAI, security settings, build and
 * deploy status. Shown to the Super Admin and available as `bora:doctor`.
 * Web and CLI (cron) can have different PHP settings; each reports its own.
 */
class HealthCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    private const REQUIRED_EXTENSIONS = ['gd', 'zip', 'fileinfo', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'ctype'];

    /** @var list<array{key: string, status: string, value: string|null}> */
    private array $checks = [];

    public function __construct(
        private readonly QueueHealth $queue,
        private readonly HeicConverter $heic,
        private readonly LocalFiles $files,
        private readonly SystemSettings $settings,
    ) {}

    /** @return list<array{key: string, status: string, label: string, value: string|null, hint: string|null}> */
    public function run(): array
    {
        $this->checks = [];
        $production = app()->isProduction();

        $this->add('php_version', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::ERROR, PHP_VERSION);

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn ($ext) => ! extension_loaded($ext)));
        $driver = config('database.connections.'.config('database.default').'.driver');
        if (in_array($driver, ['mysql', 'mariadb'], true) && ! extension_loaded('pdo_mysql')) {
            $missing[] = 'pdo_mysql';
        }
        $this->add('extensions', $missing ? self::ERROR : self::OK, $missing ? implode(', ', $missing) : null);

        // Not a problem either way: browsers convert HEIC to JPG before uploading.
        $this->add('heic_server', self::OK, $this->heic->isAvailable() ? __('health.heic_server.server') : __('health.heic_server.browser'));

        $memory = MemoryGuard::limit();
        $this->add('memory_limit', $memory === -1 || $memory >= 512 * 1024 * 1024 ? self::OK : ($memory >= 256 * 1024 * 1024 ? self::WARNING : self::ERROR), (string) ini_get('memory_limit'));

        $maxUpload = $this->settings->maxUploadMb() * 1024 * 1024;
        $upload = MemoryGuard::toBytes((string) ini_get('upload_max_filesize'));
        $post = MemoryGuard::toBytes((string) ini_get('post_max_size'));
        // One photo per request: both limits must fit the largest allowed photo (plus a little for the form).
        $uploadOk = $upload === -1 || $upload >= $maxUpload;
        $postOk = $post === 0 || $post === -1 || $post >= $maxUpload + 1024 * 1024;
        $this->add('upload_limits', $uploadOk && $postOk ? self::OK : self::WARNING,
            ini_get('upload_max_filesize').' / '.ini_get('post_max_size'));

        $time = (int) ini_get('max_execution_time');
        $this->add('max_execution_time', $time === 0 || $time >= 60 ? self::OK : self::WARNING, $time === 0 ? '∞' : $time.'s');

        $this->add('app_key', config('app.key') ? self::OK : self::ERROR);
        $this->add('debug', $production && config('app.debug') ? self::ERROR : self::OK, config('app.debug') ? 'true' : 'false');
        $this->add('environment', $production ? self::OK : self::WARNING, (string) config('app.env'));
        $this->add('https', str_starts_with((string) config('app.url'), 'https://') || ! $production ? self::OK : self::WARNING, (string) config('app.url'));
        $this->add('secure_cookie', config('session.secure') || ! $production ? self::OK : self::WARNING);

        $this->add('storage', $this->writable() ? self::OK : self::ERROR);

        $free = @disk_free_space(storage_path());
        $this->add('disk_space', $free === false || $free >= 2 * 1024 ** 3 ? self::OK : ($free >= 500 * 1024 ** 2 ? self::WARNING : self::ERROR),
            $free === false ? null : round($free / 1024 ** 3, 1).' GB');

        $queue = $this->queue->snapshot();
        $lastRun = $queue['last_worker_run_at'] ? Carbon::parse($queue['last_worker_run_at']) : null;
        $this->add('cron', $lastRun && $lastRun->gt(now()->subMinutes(5)) ? self::OK : self::ERROR, $lastRun?->diffForHumans());

        $mailer = (string) config('mail.default');
        $this->add('mail', in_array($mailer, ['log', 'array'], true) ? ($production ? self::ERROR : self::WARNING) : self::OK, $mailer);

        $this->add('openai', config('services.openai.key') ? self::OK : self::WARNING);

        $this->add('frontend_build', is_file(public_path('build/manifest.json')) ? self::OK : self::ERROR);

        $deploy = Cache::get(DeployState::KEY);
        $this->add('deploy', ($deploy['status'] ?? null) === 'failed' ? self::ERROR : self::OK,
            $deploy ? trim(($deploy['version'] ?? '').' '.($deploy['status'] ?? '')) : null);

        return array_map(fn (array $c) => $c + [
            'label' => __("health.{$c['key']}.label"),
            'hint' => $c['status'] === self::OK ? null : __("health.{$c['key']}.hint"),
        ], $this->checks);
    }

    public function worstStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array(self::ERROR, $statuses, true) ? self::ERROR : (in_array(self::WARNING, $statuses, true) ? self::WARNING : self::OK);
    }

    private function add(string $key, string $status, ?string $value = null): void
    {
        $this->checks[] = ['key' => $key, 'status' => $status, 'value' => $value];
    }

    private function writable(): bool
    {
        try {
            $dirs = [storage_path('framework/cache'), storage_path('logs'), base_path('bootstrap/cache'), $this->files->tempDirectory()];
            foreach ($dirs as $dir) {
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
                    return false;
                }
                if (! is_writable($dir)) {
                    return false;
                }
            }

            $probe = '.health-'.bin2hex(random_bytes(4));
            $disk = $this->files->disk();
            $disk->put($probe, 'ok');
            $ok = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }
}
