<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyInvitation;
use App\Services\SystemSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_company_owner_cannot_access_admin_routes(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->postJson('/api/admin/companies', [])->assertForbidden();
    }

    public function test_super_admin_is_not_a_company_account(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/api/company/settings')->assertForbidden();
    }

    public function test_admin_lists_companies_with_stats_and_search(): void
    {
        User::factory()->for(Company::factory()->state(['name' => 'Garage Jansen']))->create();
        User::factory()->for(Company::factory()->state(['name' => 'Fietsen BV']))->create();

        $this->actingAs($this->admin());

        $this->getJson('/api/admin/companies?search=jansen')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Garage Jansen')
            ->assertJsonStructure(['data' => [['id', 'name', 'status', 'owner' => ['email', 'last_login_at'], 'stats']], 'meta', 'links']);
    }

    public function test_admin_creates_company_with_invitation(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/admin/companies', [
            'company_name' => 'Nieuw Bedrijf',
            'owner_name' => 'Piet',
            'email' => 'Piet@Example.com',
        ])->assertCreated();

        $owner = User::query()->where('email', 'piet@example.com')->firstOrFail();
        $this->assertSame($response->json('data.id'), $owner->company_id);
        $this->assertTrue($owner->isCompanyOwner());
        $this->assertNotNull($owner->company->settings);
        Notification::assertSentTo($owner, CompanyInvitation::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'admin_company_created']);
    }

    public function test_admin_creates_company_with_password_sends_verification(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());

        $this->postJson('/api/admin/companies', [
            'company_name' => 'Bedrijf Twee',
            'owner_name' => 'Klaas',
            'email' => 'klaas@example.com',
            'password' => 'sterk-wachtwoord-9',
        ])->assertCreated();

        Notification::assertSentTo(User::query()->where('email', 'klaas@example.com')->first(), VerifyEmail::class);
    }

    public function test_admin_updates_blocks_and_unblocks_company(): void
    {
        $owner = User::factory()->create();
        $company = $owner->company;
        $this->actingAs($this->admin());

        $this->putJson("/api/admin/companies/{$company->id}", ['company_name' => 'Hernoemd'])
            ->assertOk()->assertJsonPath('data.name', 'Hernoemd');

        $this->postJson("/api/admin/companies/{$company->id}/block", ['reason' => 'Onbetaald'])
            ->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->assertTrue($company->fresh()->isBlocked());

        $this->postJson("/api/admin/companies/{$company->id}/unblock")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_admin_deletes_company_only_with_name_confirmation(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $company = $owner->company;
        Storage::disk('local')->put("companies/{$company->id}/logo.png", 'x');
        $this->actingAs($this->admin());

        $this->deleteJson("/api/admin/companies/{$company->id}", ['confirm_name' => 'fout'])->assertUnprocessable();

        $this->deleteJson("/api/admin/companies/{$company->id}", ['confirm_name' => $company->name])->assertOk();

        $this->assertModelMissing($company);
        $this->assertModelMissing($owner);
        Storage::disk('local')->assertMissing("companies/{$company->id}/logo.png");
    }

    public function test_admin_can_delete_a_batch_and_its_files(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $batch = Batch::query()->create([
            'company_id' => $owner->company_id,
            'user_id' => $owner->id,
            'filename_base' => 'foto',
            'settings' => [],
            'storage_bytes' => 100,
        ]);
        $owner->company->forceFill(['storage_bytes' => 100])->save();
        Storage::disk('local')->put($batch->storageDirectory().'/original/a.jpg', 'x');

        $this->actingAs($this->admin());
        $this->getJson('/api/admin/batches')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/batches/{$batch->id}")->assertOk();

        $this->assertModelMissing($batch);
        $this->assertSame(0, $owner->company->fresh()->storage_bytes);
        Storage::disk('local')->assertMissing($batch->storageDirectory().'/original/a.jpg');
    }

    public function test_admin_updates_system_settings_within_limits(): void
    {
        $this->actingAs($this->admin());

        $this->putJson('/api/admin/settings', ['jpg_quality' => 95])->assertJsonValidationErrors('jpg_quality');

        $this->putJson('/api/admin/settings', ['retention_days' => 5, 'registration_enabled' => true, 'jpg_quality' => 86])
            ->assertOk()
            ->assertJsonPath('data.retention_days', 5)
            ->assertJsonPath('data.registration_enabled', true);

        $this->getJson('/api/meta')->assertJsonPath('data.registration_enabled', true)->assertJsonPath('data.retention_days', 5);
    }

    public function test_registration_works_when_enabled(): void
    {
        app(SystemSettings::class)->update(['registration_enabled' => true]);

        $this->postJson('/api/auth/register', [
            'company_name' => 'Zelf BV',
            'owner_name' => 'Zelf',
            'email' => 'zelf@example.com',
            'password' => 'wachtwoord-123',
            'password_confirmation' => 'wachtwoord-123',
        ])->assertCreated()->assertJsonPath('data.company.name', 'Zelf BV');

        $this->assertAuthenticated();
    }

    public function test_admin_dashboard_and_activity(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonStructure(['data' => ['totals' => ['companies', 'storage_bytes'], 'recent_failures']]);
        $this->getJson('/api/admin/activity')->assertOk()->assertJsonStructure(['data', 'meta']);
    }
}
