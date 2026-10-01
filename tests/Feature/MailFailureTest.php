<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** A broken SMTP server never causes a 500 or leaks its error text. */
class MailFailureTest extends TestCase
{
    use RefreshDatabase;

    private const SMTP_ERROR = '535 5.7.8 secret-smtp-detail authentication failed';

    protected function setUp(): void
    {
        parent::setUp();

        app(ChannelManager::class)->extend('mail', fn () => new class
        {
            public function send($notifiable, $notification): void
            {
                throw new TransportException(MailFailureTest::SMTP_ERROR);
            }
        });
    }

    public function test_company_is_created_with_warning_when_invitation_mail_fails(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/admin/companies', [
            'company_name' => 'Mailloos BV',
            'owner_name' => 'Piet',
            'email' => 'piet@example.com',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Mailloos BV')
            ->assertJsonPath('code', 'mail_failed')
            ->assertJsonPath('mail_failed', true)
            ->assertJsonPath('warning', __('messages.admin.company_mail_failed'));

        $this->assertStringNotContainsString('secret-smtp-detail', $response->getContent());
        $this->assertDatabaseHas('companies', ['name' => 'Mailloos BV']);
        $this->assertDatabaseHas('users', ['email' => 'piet@example.com']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'admin_company_created']);
    }

    public function test_company_with_password_is_created_when_verification_mail_fails(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/admin/companies', [
            'company_name' => 'Twee BV',
            'owner_name' => 'Klaas',
            'email' => 'klaas@example.com',
            'password' => 'sterk-wachtwoord-9',
        ])->assertCreated()->assertJsonPath('code', 'mail_failed');

        $this->assertDatabaseHas('users', ['email' => 'klaas@example.com']);
    }

    public function test_admin_password_reset_returns_clean_422_when_mail_fails(): void
    {
        $owner = User::factory()->unverified()->create(['last_login_at' => null]);
        $this->actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson("/api/admin/companies/{$owner->company_id}/password-reset")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'mail_failed')
            ->assertJsonPath('message', __('messages.admin.mail_send_failed'));

        $this->assertStringNotContainsString('secret-smtp-detail', $response->getContent());
    }

    public function test_verification_resend_returns_422_when_mail_fails(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $response = $this->postJson('/api/auth/email/verification-notification')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'mail_send_failed');

        $this->assertStringNotContainsString('secret-smtp-detail', $response->getContent());
    }

    public function test_forgot_password_answers_the_same_when_mail_fails(): void
    {
        config(['queue.default' => 'sync']);
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', __('passwords.sent_generic'));
    }

    public function test_profile_email_change_is_saved_when_verification_mail_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->putJson('/api/account/profile', ['name' => $user->name, 'email' => 'nieuw@example.com', 'locale' => 'nl', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('code', 'mail_failed');

        $this->assertSame('nieuw@example.com', $user->fresh()->email);
    }

    public function test_admin_email_change_is_saved_when_verification_mail_fails(): void
    {
        $owner = User::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->putJson("/api/admin/companies/{$owner->company_id}", ['email' => 'ander@example.com'])
            ->assertOk()
            ->assertJsonPath('code', 'mail_failed');

        $this->assertSame('ander@example.com', $owner->fresh()->email);
        $this->assertInstanceOf(Company::class, $owner->fresh()->company);
    }
}
