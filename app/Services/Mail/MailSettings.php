<?php

namespace App\Services\Mail;

use App\Models\SystemSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * E-mail settings the Super Admin manages in the app (no .env editing needed):
 * on/off plus SMTP server, login and sender. The SMTP password is stored
 * encrypted (APP_KEY) and never sent back to the browser.
 *
 * Never configured in the app: automatic. E-mail is then off in production
 * while MAIL_MAILER=log (no mailbox), and follows .env otherwise.
 * Without e-mail the app still works: the Super Admin sets passwords and
 * e-mail addresses need no confirmation (see User::hasVerifiedEmail).
 */
class MailSettings
{
    private const CACHE_KEY = 'bora:mail_settings';

    public const KEYS = ['mail_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name'];

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /** @return array<string, mixed> Stored values; the password stays encrypted. */
    public function stored(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            $values = Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()->whereIn('key', self::KEYS)->pluck('value', 'key')->all());
        } catch (Throwable) {
            $values = []; // not installed / not migrated yet
        }

        return $this->resolved = $values;
    }

    /** Explicitly switched on or off in the app, or null (automatic). */
    public function toggle(): ?bool
    {
        $value = $this->stored()['mail_enabled'] ?? null;

        return $value === null ? null : (bool) $value;
    }

    public function isEnabled(): bool
    {
        $forced = config('bora.mail_enabled');
        if ($forced !== null && $forced !== '') {
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN);
        }

        $toggle = $this->toggle();
        if ($toggle !== null) {
            return $toggle && $this->hasSmtp();
        }

        return ! (app()->isProduction() && config('mail.default') === 'log');
    }

    public function hasSmtp(): bool
    {
        return filled($this->stored()['smtp_host'] ?? null);
    }

    public function password(): ?string
    {
        $encrypted = $this->stored()['smtp_password'] ?? null;
        if (! $encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null; // APP_KEY changed: the password has to be entered again
        }
    }

    /** Settings for the admin screen: never the password itself. */
    public function forAdmin(): array
    {
        $s = $this->stored();

        return [
            'enabled' => $this->isEnabled(),
            'toggle' => $this->toggle(),
            'forced_by_env' => filled(config('bora.mail_enabled')),
            'smtp_host' => $s['smtp_host'] ?? null,
            'smtp_port' => isset($s['smtp_port']) ? (int) $s['smtp_port'] : 587,
            'smtp_encryption' => $s['smtp_encryption'] ?? 'tls',
            'smtp_username' => $s['smtp_username'] ?? null,
            'password_set' => $this->password() !== null,
            'mail_from_address' => $s['mail_from_address'] ?? null,
            'mail_from_name' => $s['mail_from_name'] ?? config('app.name'),
        ];
    }

    /** @param array<string, mixed> $values An empty/missing smtp_password keeps the current one. */
    public function update(array $values): void
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            if ($key === 'smtp_password') {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = Crypt::encryptString((string) $value);
            }

            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if (! empty($values['clear_password'])) {
            SystemSetting::query()->where('key', 'smtp_password')->delete();
        }

        $this->flush();
        $this->apply();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    /**
     * Point Laravel's mailer at the SMTP server from the app settings. Called
     * when the mail manager is first used (AppServiceProvider) and after saving.
     */
    public function apply(): void
    {
        if ($this->toggle() !== true || ! $this->hasSmtp()) {
            return;
        }

        $s = $this->stored();
        $encryption = $s['smtp_encryption'] ?? 'tls';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $s['smtp_host'],
            'mail.mailers.smtp.port' => (int) ($s['smtp_port'] ?? 587),
            // "ssl" = implicit TLS (port 465); "tls" = STARTTLS (port 587), negotiated by the transport.
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $s['smtp_username'] ?? null,
            'mail.mailers.smtp.password' => $this->password(),
            'mail.from.address' => $s['mail_from_address'] ?: config('mail.from.address'),
            'mail.from.name' => $s['mail_from_name'] ?: config('mail.from.name'),
        ]);

        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }
}
