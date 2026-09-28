<?php

namespace App\Console\Commands;

use App\Services\System\HealthCheck;
use Illuminate\Console\Command;

/** `php artisan bora:doctor`: the installation check on the command line (CLI/cron PHP settings). */
class Doctor extends Command
{
    protected $signature = 'bora:doctor';

    protected $description = 'Controleer de installatie (PHP, rechten, cron, mail, OpenAI, beveiliging)';

    public function handle(HealthCheck $health): int
    {
        $checks = $health->run();

        $this->table(['', 'Controle', 'Waarde', 'Advies'], array_map(fn ($c) => [
            ['ok' => '✔', 'warning' => '!', 'error' => '✘'][$c['status']],
            $c['label'],
            $c['value'] ?? '',
            $c['hint'] ?? '',
        ], $checks));

        return $health->worstStatus($checks) === HealthCheck::ERROR ? self::FAILURE : self::SUCCESS;
    }
}
