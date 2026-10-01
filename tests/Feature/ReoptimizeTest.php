<?php

namespace Tests\Feature;

use App\Jobs\ProcessImage;
use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 6: before/after source and "opnieuw optimaliseren". */
class ReoptimizeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->user = User::factory()->create();
    }

    private function processedImage(): Image
    {
        $id = $this->actingAs($this->user)->postJson('/api/company/batches', ['strength' => 'normal'])->json('data.id');
        $img = imagecreatetruecolor(1200, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 90, 110, 130));
        ob_start();
        imagejpeg($img, null, 90);
        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent('a.jpg', ob_get_clean())])->assertCreated();
        $this->postJson("/api/company/batches/{$id}/start")->assertOk();
        $this->artisan('bora:work')->assertSuccessful();

        return Batch::query()->findOrFail($id)->images()->firstOrFail();
    }

    private function choices(array $override = []): array
    {
        return array_replace(['strength' => 'strong', 'background' => 'blur_light', 'remove_people' => true], $override);
    }

    public function test_reoptimize_queues_the_photo_with_new_choices_and_keeps_the_old_result_until_replaced(): void
    {
        $image = $this->processedImage();
        $oldResult = $image->optimized_path;
        Queue::fake();

        $this->postJson("/api/company/images/{$image->id}/reoptimize", $this->choices())
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.reoptimized', true)
            ->assertJsonPath('data.settings.strength', 'strong')
            ->assertJsonPath('data.settings.background', 'blur_light')
            ->assertJsonPath('data.settings.remove_people', true)
            // Batch-level settings that were not re-chosen stay the same.
            ->assertJsonPath('data.settings.output_format', 'jpg');

        Queue::assertPushed(ProcessImage::class, fn ($job) => $job->imageId === $image->id);
        Storage::disk('local')->assertExists($oldResult);
        $this->assertSame('queued', $image->batch->fresh()->status->value, 'batch is active again, so the page polls');
    }

    public function test_new_version_replaces_the_active_one(): void
    {
        $image = $this->processedImage();
        $oldResult = $image->optimized_path;
        $urls = fn () => $this->getJson("/api/company/batches/{$image->batch_id}")->json('data.images.0.urls');
        $before = $urls();

        $this->postJson("/api/company/images/{$image->id}/reoptimize", $this->choices(['background' => 'keep', 'remove_people' => false]))->assertStatus(202);
        $this->artisan('bora:work')->assertSuccessful();

        $image->refresh();
        $this->assertSame('completed', $image->status->value);
        $this->assertNotSame($oldResult, $image->optimized_path);
        // A new URL per result: a browser that cached the old file for an hour must fetch the new one.
        $after = $urls();
        $this->assertNotSame($before['preview'], $after['preview']);
        $this->assertNotSame($before['optimized'], $after['optimized']);
        $this->assertSame($after['preview'], $urls()['preview'], 'stable while nothing changes');
        Storage::disk('local')->assertMissing($oldResult);
        Storage::disk('local')->assertExists($image->optimized_path);
        $this->assertSame('completed', $image->batch->fresh()->status->value);
    }

    public function test_busy_photo_cannot_be_reoptimized(): void
    {
        $image = $this->processedImage();
        $image->forceFill(['status' => 'processing'])->save();

        $this->postJson("/api/company/images/{$image->id}/reoptimize", $this->choices())
            ->assertStatus(422)
            ->assertJsonPath('code', 'image_busy');
    }

    public function test_failed_photo_can_be_tried_again(): void
    {
        $image = $this->processedImage();
        $image->forceFill(['status' => 'failed', 'error_message' => 'x'])->save();
        Queue::fake();

        $this->postJson("/api/company/images/{$image->id}/reoptimize", $this->choices())
            ->assertStatus(202)
            ->assertJsonPath('data.error', null);
    }

    public function test_validation_and_tenant_isolation(): void
    {
        $image = $this->processedImage();

        $this->postJson("/api/company/images/{$image->id}/reoptimize", ['strength' => 'extreme'])
            ->assertJsonValidationErrors(['strength', 'background', 'remove_people']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/company/images/{$image->id}/reoptimize", $this->choices())
            ->assertForbidden();
        $this->get("/api/company/images/{$image->id}/working")->assertForbidden();
    }

    public function test_before_image_is_the_oriented_working_copy(): void
    {
        $image = $this->processedImage();

        $this->getJson("/api/company/batches/{$image->batch_id}")
            ->assertJsonPath('data.images.0.urls.before', route('api.company.images.file', [$image->id, 'working']))
            ->assertJsonPath('data.images.0.settings.strength', 'normal');

        $this->get("/api/company/images/{$image->id}/working")->assertOk();
    }
}
