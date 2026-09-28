<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-tenancy: a company may never see or change data of another company.
 * Policies are checked directly here; later phases add HTTP-level checks for
 * batch/image/download endpoints on top of these.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_settings_are_scoped_to_own_company(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)
            ->putJson('/api/company/settings', [
                'company_name' => 'A BV',
                'default_output_format' => 'png',
                'default_resolution' => '2560',
                'default_aspect_ratio' => '1:1',
                'default_strength' => 'subtle',
                'default_background' => 'blur_light',
                'default_watermark_mode' => 'all',
                'default_watermark_position' => 'center',
                'default_watermark_opacity' => 50,
                'filename_prefix' => 'Auto Jansen',
            ])
            ->assertOk()
            ->assertJsonPath('data.filename_prefix', 'auto-jansen')
            ->assertJsonPath('data.default_output_format', 'png');

        $this->assertSame('jpg', $b->company->settings->fresh()->default_output_format->value);
        $this->assertNotSame('A BV', $b->company->fresh()->name);
    }

    public function test_invalid_company_settings_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/company/settings', ['default_output_format' => 'gif', 'default_resolution' => '4000'])
            ->assertJsonValidationErrors(['company_name', 'default_output_format', 'default_resolution']);
    }

    public function test_batch_and_image_policies_isolate_companies(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $batch = Batch::query()->create(['company_id' => $a->company_id, 'user_id' => $a->id, 'filename_base' => 'foto', 'settings' => []]);
        $image = Image::query()->create([
            'batch_id' => $batch->id, 'company_id' => $a->company_id, 'position' => 1, 'original_filename' => 'a.jpg',
        ]);

        foreach (['view', 'update', 'delete', 'download'] as $ability) {
            $this->assertTrue($a->can($ability, $batch), "owner {$ability} batch");
            $this->assertFalse($b->can($ability, $batch), "other company {$ability} batch");
            $this->assertTrue($a->can($ability, $image), "owner {$ability} image");
            $this->assertFalse($b->can($ability, $image), "other company {$ability} image");
        }

        // Super Admin may inspect and delete, but never downloads customer files as a company.
        $this->assertTrue($admin->can('view', $batch));
        $this->assertTrue($admin->can('delete', $batch));
        $this->assertFalse($admin->can('download', $batch));
        $this->assertFalse($admin->can('update', $batch));
    }

    public function test_company_stats_endpoint(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/company/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['batches_active', 'images_last_30_days', 'storage_bytes']]);
    }
}
