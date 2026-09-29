<?php

namespace App\Services\System;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * First-run setup in the browser, for hosting without SSH: the owner enters
 * the database and the Super Admin account, the installer migrates the
 * database, creates the account and writes .env (with a fresh APP_KEY).
 *
 * Only available while .env does not exist. It only accepts a database on
 * this server (localhost) that the given credentials can open, so nobody
 * without the hosting's database password can complete it.
 */
class Installer
{
    public function __construct(private readonly ?string $envPath = null) {}

    public function envPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    public function isInstalled(): bool
    {
        return is_file($this->envPath());
    }

    /**
     * @param  array{db_database: string, db_username: string, db_password: ?string, name: string, email: string, password: string, openai_key: ?string}  $data
     * @return string the URL of the site
     */
    public function install(array $data, string $appUrl): string
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('already_installed');
        }

        $connection = $this->connectionConfig($data);
        $this->testConnection($connection);

        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        $this->useConnection($connection);

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('migrate: '.trim(Artisan::output()));
        }

        $this->createSuperAdmin($data);
        $this->writeEnv($data, $appUrl);

        return $appUrl;
    }

    /** @return array<string, mixed> */
    protected function connectionConfig(array $data): array
    {
        return array_replace(config('database.connections.mysql'), [
            'host' => 'localhost',
            'port' => '3306',
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => (string) ($data['db_password'] ?? ''),
            'url' => null,
        ]);
    }

    /** Run on the new database for the rest of this request. */
    protected function useConnection(array $connection): void
    {
        config(['database.connections.install' => $connection, 'database.default' => 'install']);
        DB::purge('install');
    }

    protected function testConnection(array $connection): void
    {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
                $connection['username'],
                $connection['password'],
                [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable) {
            throw new RuntimeException('db_connect');
        }
    }

    private function createSuperAdmin(array $data): void
    {
        $user = User::query()->where('role', UserRole::SuperAdmin)->first()
            ?? User::query()->where('email', Str::lower($data['email']))->first()
            ?? new User;

        $user->fill(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password']]);
        $user->role = UserRole::SuperAdmin;
        $user->company_id = null;
        $user->email_verified_at ??= now();
        $user->save();
    }

    private function writeEnv(array $data, string $appUrl): void
    {
        $template = @file_get_contents(base_path('.env.production.example'));
        if ($template === false) {
            throw new RuntimeException('env_template');
        }

        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $values = [
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => rtrim($appUrl, '/'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => (string) ($data['db_password'] ?? ''),
            'OPENAI_API_KEY' => (string) ($data['openai_key'] ?? ''),
            // No mailbox yet: mails go to the log until SMTP is filled in.
            'MAIL_MAILER' => 'log',
            'MAIL_FROM_ADDRESS' => 'noreply@'.preg_replace('/^www\./', '', $host),
            // Secure cookies only work on HTTPS.
            'SESSION_SECURE_COOKIE' => str_starts_with($appUrl, 'https://') ? 'true' : 'false',
        ];

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $template = preg_match("/^{$key}=.*$/m", $template)
                ? preg_replace_callback("/^{$key}=.*$/m", fn () => $line, $template)
                : rtrim($template)."\n{$line}\n";
        }

        $tmp = $this->envPath().'.tmp';
        if (@file_put_contents($tmp, $template, LOCK_EX) === false || ! @rename($tmp, $this->envPath())) {
            @unlink($tmp);
            throw new RuntimeException('env_write');
        }
        @chmod($this->envPath(), 0640);
    }

    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+=\-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
