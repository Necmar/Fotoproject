<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_can_log_in_and_fetch_profile(): void
    {
        $user = User::factory()->create(['password' => 'geheim-wachtwoord-1']);

        $this->postJson('/api/auth/login', ['email' => strtoupper($user->email), 'password' => 'geheim-wachtwoord-1'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'company_owner')
            ->assertJsonPath('data.company.id', $user->company_id);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'login']);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_is_rejected_and_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'fout'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_blocked_company_cannot_log_in(): void
    {
        $user = User::factory()->for(Company::factory()->blocked())->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable();

        $this->assertGuest();
    }

    public function test_user_is_logged_out_when_company_gets_blocked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->company->update(['status' => 'blocked']);

        $this->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('code', 'account_blocked');
        $this->assertGuest();
    }

    public function test_guest_gets_401_json(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'onbekend@example.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password/') && str_contains($url, urlencode($user->email));
        });
    }

    public function test_password_can_be_reset_and_email_becomes_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nieuw-wachtwoord-2',
            'password_confirmation' => 'nieuw-wachtwoord-2',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nieuw-wachtwoord-2'])->assertOk();
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'ongeldig',
            'email' => $user->email,
            'password' => 'nieuw-wachtwoord-2',
            'password_confirmation' => 'nieuw-wachtwoord-2',
        ])->assertUnprocessable();
    }

    public function test_email_verification_link_verifies_without_session(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_owner_cannot_use_company_area_but_can_resend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->getJson('/api/company/settings')->assertForbidden();
        $this->postJson('/api/auth/email/verification-notification')->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_profile_update_and_email_change_requires_new_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->putJson('/api/account/profile', ['name' => 'Nieuwe Naam', 'email' => 'Nieuw@Example.com', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.email', 'nieuw@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.locale', 'en');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => 'oud-wachtwoord-1']);
        $this->actingAs($user);

        $this->putJson('/api/account/password', [
            'current_password' => 'fout',
            'password' => 'nieuw-wachtwoord-2',
            'password_confirmation' => 'nieuw-wachtwoord-2',
        ])->assertJsonValidationErrors('current_password');

        $this->putJson('/api/account/password', [
            'current_password' => 'oud-wachtwoord-1',
            'password' => 'nieuw-wachtwoord-2',
            'password_confirmation' => 'nieuw-wachtwoord-2',
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'password_changed']);
    }

    public function test_registration_is_disabled_by_default(): void
    {
        $this->postJson('/api/auth/register', [
            'company_name' => 'Test BV',
            'owner_name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'wachtwoord-123',
            'password_confirmation' => 'wachtwoord-123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_activity_log_never_contains_passwords(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'fout-wachtwoord']);

        $this->assertDatabaseMissing('activity_logs', ['properties' => json_encode(['password' => 'fout-wachtwoord'])]);
        $this->assertStringNotContainsString('fout-wachtwoord', ActivityLog::query()->pluck('properties')->toJson());
    }
}
