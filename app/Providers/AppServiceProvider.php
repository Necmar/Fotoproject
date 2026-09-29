<?php

namespace App\Providers;

use App\Services\SystemSettings;
use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use App\Services\Mail\MailSettings;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailSettings::class);
        // SMTP settings from the admin screen are applied when mail is first used.
        $this->app->resolving('mail.manager', fn () => $this->app->make(MailSettings::class)->apply());

        $this->app->singleton(SystemSettings::class);
    }

    public function boot(): void
    {
        $proxies = (string) config('bora.trusted_proxies', '*');
        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));

        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(10)->letters()->numbers()->max(255));

        // Reset links point to the React app, not to a Blade page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => FrontendUrl::to(
            '/reset-password/'.$token,
            ['email' => $user->getEmailForPasswordReset()],
        ));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Per IP, across all accounts (the per account+IP lockout lives in the login request).
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(20)->by($request->ip()), Limit::perHour(100)->by($request->ip())]);

        // A batch is max 30 files, uploaded one per request; allow retries.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('cron', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(10)->by(mb_strtolower((string) $request->input('email'))),
        ]);
    }
}
