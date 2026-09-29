<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 10: security review fixes. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_on_pages_and_api(): void
    {
        foreach (['/login', '/api/meta'] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy');
        }

        $this->assertStringContainsString("frame-ancestors 'none'", $this->get('/login')->headers->get('Content-Security-Policy'));
    }

    public function test_not_found_never_exposes_model_names(): void
    {
        config(['app.debug' => false]);
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/company/batches/01jzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();

        $this->assertStringNotContainsString('App\\Models', $response->json('message'));
        $this->assertSame(__('messages.errors.not_found'), $response->json('message'));
    }

    public function test_reoptimize_is_capped_per_photo_and_per_company_per_day(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'bora.system_defaults.reoptimize_per_image' => 2, 'bora.system_defaults.reoptimize_per_company_per_day' => 3]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $batchId = $this->postJson('/api/company/batches')->json('data.id');
        foreach ([1, 2] as $i) {
            $img = imagecreatetruecolor(400, 300);
            ob_start();
            imagejpeg($img);
            $this->postJson("/api/company/batches/{$batchId}/images", ['file' => UploadedFile::fake()->createWithContent("{$i}.jpg", ob_get_clean())])->assertCreated();
        }
        $this->postJson("/api/company/batches/{$batchId}/start")->assertOk();
        $this->artisan('bora:work')->assertSuccessful();
        [$first, $second] = Batch::query()->findOrFail($batchId)->images()->orderBy('position')->get()->all();

        $choices = ['strength' => 'strong', 'background' => 'keep', 'remove_people' => false];
        $reoptimize = function (Image $image) use ($choices) {
            $response = $this->postJson("/api/company/images/{$image->id}/reoptimize", $choices);
            $this->artisan('bora:work');

            return $response;
        };

        $reoptimize($first)->assertStatus(202)->assertJsonPath('data.reoptimize_left', 1);
        $reoptimize($first)->assertStatus(202)->assertJsonPath('data.reoptimize_left', 0);
        $reoptimize($first)->assertStatus(422)->assertJsonPath('code', 'reoptimize_limit');

        // Third paid re-optimisation of the day for this company, then the daily cap.
        $reoptimize($second)->assertStatus(202);
        $reoptimize($second)->assertStatus(429)->assertJsonPath('code', 'reoptimize_daily_limit');
    }

    public function test_a_normal_reset_token_is_not_accepted_as_invitation(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'nieuw-wachtwoord-1', 'password_confirmation' => 'nieuw-wachtwoord-1'];

        $this->postJson('/api/auth/reset-password', $payload + ['invite' => true])->assertStatus(422);
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
    }

    public function test_password_changes_rotate_the_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'oud-wachtwoord-1', 'remember_token' => 'oude-token']);

        $this->actingAs($user)->putJson('/api/account/password', [
            'current_password' => 'oud-wachtwoord-1', 'password' => 'nieuw-wachtwoord-1', 'password_confirmation' => 'nieuw-wachtwoord-1',
        ])->assertOk();
        $this->assertNotSame('oude-token', $user->fresh()->remember_token);

        $user->forceFill(['remember_token' => 'oude-token'])->save();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/admin/companies/{$user->company_id}", ['password' => 'admin-gezet-wachtwoord-1'])->assertOk();
        $this->assertNotSame('oude-token', $user->fresh()->remember_token);
    }

    public function test_login_is_throttled_per_ip_across_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', ['email' => "iemand{$i}@example.com", 'password' => 'fout'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'nog-een@example.com', 'password' => 'fout'])->assertStatus(429);
    }

    public function test_unverified_owner_opening_a_file_link_is_redirected_not_a_500(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/api/company/batches')->assertRedirect(route('verification.notice'));
    }
}
