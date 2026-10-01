<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** E-mail optional; SMTP managed by the Super Admin. */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function mailOff(): void
    {
        config(['bora.mail_enabled' => false]);
    }

    public function test_mail_is_off_automatically_in_production_without_a_mailbox(): void
    {
        $mail = app(MailSettings::class);
        $this->app['env'] = 'production';

        config(['mail.default' => 'log']);
        $this->assertFalse($mail->isEnabled());

        config(['mail.default' => 'smtp']);
        $this->assertTrue($mail->isEnabled());
    }

    public function test_without_mail_the_app_works_without_confirmations_or_mails(): void
    {
        Notification::fake();
        $this->mailOff();

        // Unverified owners can still use the portal.
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner)->getJson('/api/company/batches')->assertOk();
        $this->getJson('/api/meta')->assertJsonPath('data.mail_enabled', false);

        // "Forgot password" explains that e-mail is off.
        auth()->logout();
        $this->postJson('/api/auth/forgot-password', ['email' => $owner->email])->assertStatus(422)->assertJsonPath('code', 'mail_disabled');

        // New companies need a password from the Super Admin.
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->postJson('/api/admin/companies', ['company_name' => 'X', 'owner_name' => 'Y', 'email' => 'x@example.com'])
            ->assertJsonValidationErrors('password');
        $this->postJson('/api/admin/companies', ['company_name' => 'X', 'owner_name' => 'Y', 'email' => 'x@example.com', 'password' => 'sterk-wachtwoord-1'])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_super_admin_configures_smtp_without_the_password_ever_leaving_the_server(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/admin/mail', ['mail_enabled' => false])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.password_set', false);
        $this->getJson('/api/meta')->assertJsonPath('data.mail_enabled', false);

        $this->putJson('/api/admin/mail', ['mail_enabled' => true])->assertJsonValidationErrors(['smtp_host', 'mail_from_address']);

        $response = $this->putJson('/api/admin/mail', [
            'mail_enabled' => true, 'smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_username' => 'noreply@example.com', 'smtp_password' => 'geheim-smtp', 'mail_from_address' => 'noreply@example.com', 'mail_from_name' => 'Bora',
        ])->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.password_set', true);

        $this->assertStringNotContainsString('geheim-smtp', $response->getContent());
        $this->assertStringNotContainsString('geheim-smtp', (string) SystemSetting::query()->where('key', 'smtp_password')->value('value'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('geheim-smtp', config('mail.mailers.smtp.password'));
        $this->getJson('/api/meta')->assertJsonPath('data.mail_enabled', true);

        // Saving again without a password keeps the stored one.
        $this->putJson('/api/admin/mail', ['mail_enabled' => true, 'smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'mail_from_address' => 'noreply@example.com'])
            ->assertJsonPath('data.password_set', true);

        $this->postJson('/api/admin/mail/test')->assertOk();

        // Company owners cannot reach it.
        $this->actingAs(User::factory()->create())->getJson('/api/admin/mail')->assertForbidden();
    }

    public function test_saving_the_untouched_mail_card_in_automatic_mode_keeps_it_automatic(): void
    {
        config(['mail.default' => 'smtp']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/admin/mail')->assertJsonPath('data.toggle', null)->assertJsonPath('data.enabled', true);
        $this->putJson('/api/admin/mail', ['mail_enabled' => true, 'smtp_host' => null, 'smtp_port' => 587, 'mail_from_address' => null])
            ->assertOk()->assertJsonPath('data.toggle', null)->assertJsonPath('data.enabled', true);
    }

    public function test_switching_on_without_host_is_allowed_when_env_has_a_mailer(): void
    {
        config(['mail.default' => 'smtp']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/admin/mail', ['mail_enabled' => false])->assertOk();
        $this->putJson('/api/admin/mail', ['mail_enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);

        // A host given: port and sender are needed as well.
        $this->putJson('/api/admin/mail', ['mail_enabled' => true, 'smtp_host' => 'mail.example.com', 'smtp_port' => null])
            ->assertJsonValidationErrors(['smtp_port', 'mail_from_address']);
    }

    public function test_mail_validation_messages_are_dutch(): void
    {
        app()->setLocale('nl');
        $this->actingAs(User::factory()->superAdmin()->create(['locale' => 'nl']));

        $this->putJson('/api/admin/mail', ['mail_enabled' => true, 'smtp_host' => 'mail.example.com'])
            ->assertJsonPath('errors.smtp_port.0', 'SMTP-poort is verplicht.')
            ->assertJsonPath('errors.mail_from_address.0', 'Afzenderadres is verplicht.');
    }
}
