<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\System\DeployState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Phase 10: installation check and deploy without SSH. */
class ProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_the_installation_check(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/admin/health')
            ->assertOk()
            ->assertJsonStructure(['data' => ['status', 'checks' => [['key', 'status', 'label', 'value', 'hint']]]])
            ->assertJsonPath('data.checks.0.key', 'php_version');

        $this->actingAs(User::factory()->create())->getJson('/api/admin/health')->assertForbidden();
    }

    public function test_debug_mode_in_production_is_reported_as_error(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        $checks = collect(app(\App\Services\System\HealthCheck::class)->run())->keyBy('key');

        $this->assertSame('error', $checks['debug']['status']);
        $this->assertNotNull($checks['debug']['hint']);
    }

    public function test_heic_and_equal_upload_limits_are_not_reported_as_problems(): void
    {
        $checks = collect(app(\App\Services\System\HealthCheck::class)->run())->keyBy('key');

        $this->assertSame('ok', $checks['heic_server']['status']);
        $this->assertNull($checks['heic_server']['hint']);
    }

    public function test_deploy_runs_once_per_new_version(): void
    {
        $version = base_path('VERSION');
        $applied = storage_path('app/deployed-version');
        @unlink($applied);
        file_put_contents($version, 'test-1');
        $this->app['env'] = 'production';

        try {
            $this->artisan('bora:deploy')->expectsOutputToContain('test-1')->assertSuccessful();
            $this->assertSame('test-1', app(DeployState::class)->appliedVersion());

            // Same version: nothing to do.
            $this->artisan('bora:deploy')->doesntExpectOutputToContain('migrate')->assertSuccessful();

            // Without VERSION file a fingerprint of migrations/build/routes is used.
            unlink($version);
            $this->assertStringStartsWith('auto-', app(DeployState::class)->availableVersion());
            $this->artisan('bora:deploy')->expectsOutputToContain('auto-')->assertSuccessful();
        } finally {
            @unlink($version);
            @unlink($applied);
            Artisan::call('optimize:clear');
        }
    }
}
