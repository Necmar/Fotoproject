<?php

namespace App\Console\Commands;

use App\Services\System\DeployState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a new version after an upload or Plesk Git pull, without SSH: the
 * scheduler runs this every minute; it only acts (in production) when the
 * code version changed (see DeployState).
 *
 * Steps: migrations, clear caches, cache routes/views/events. The config is
 * deliberately NOT cached, so changes in .env apply immediately.
 */
class Deploy extends Command
{
    protected $signature = 'bora:deploy {--force : Ook uitvoeren als de versie niet veranderd is}';

    protected $description = 'Voer update-stappen uit na een nieuwe versie (migraties en caches)';

    public function handle(DeployState $state): int
    {
        $version = $state->availableVersion();
        $force = (bool) $this->option('force');

        // Only automatic in production: locally, cached routes would hide route changes.
        if (! $force && (! app()->isProduction() || $version === null || $version === $state->appliedVersion())) {
            return self::SUCCESS;
        }

        $version ??= 'dev';
        $lock = Cache::lock('bora:deploy', 600);
        if (! $lock->get()) {
            $this->warn('Er loopt al een update.');

            return self::SUCCESS;
        }

        try {
            $steps = [
                'migrate' => ['migrate', ['--force' => true]],
                'optimize:clear' => ['optimize:clear', []],
                'route:cache' => ['route:cache', []],
                'view:cache' => ['view:cache', []],
                'event:cache' => ['event:cache', []],
            ];

            foreach ($steps as $name => [$command, $arguments]) {
                $this->line("→ {$name}");
                if (Artisan::call($command, $arguments) !== 0) {
                    throw new \RuntimeException(trim(Artisan::output()) ?: $name);
                }
            }

            $state->markApplied($version);
            Log::info('Deploy applied', ['version' => $version]);
            $this->info("Versie {$version} is toegepast.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $state->markFailed($version, $name ?? 'unknown');
            Log::error('Deploy failed', ['version' => $version, 'step' => $name ?? null, 'error' => $e->getMessage()]);
            $this->error("Update mislukt bij {$name}: ".$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
