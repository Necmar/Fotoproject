<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\User;
use App\Services\Images\HeicConverter;
use App\Services\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchUploadTest extends TestCase
{
    use RefreshDatabase;

    private const HEIC = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic\x00\x00\x00\x00\x00\x00\x00\x00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function draft(User $user, array $data = []): string
    {
        return $this->actingAs($user)->postJson('/api/company/batches', $data)->assertCreated()->json('data.id');
    }

    public function test_draft_uses_company_defaults_and_name_becomes_filename_base(): void
    {
        $user = User::factory()->create();
        $user->company->settings->update(['default_output_format' => 'png', 'default_resolution' => '2560', 'filename_prefix' => 'garage']);

        $this->actingAs($user)->postJson('/api/company/batches', ['name' => 'BMW 320i Touring'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.filename_base', 'bmw-320i-touring')
            ->assertJsonPath('data.settings.output_format', 'png')
            ->assertJsonPath('data.settings.resolution', '2560')
            ->assertJsonPath('data.settings.watermark_mode', 'none');

        $this->postJson('/api/company/batches')->assertCreated()->assertJsonPath('data.filename_base', 'garage');
        $this->assertDatabaseHas('activity_logs', ['action' => 'batch_created', 'company_id' => $user->company_id]);
    }

    public function test_upload_jpg_and_png(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);

        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('auto.jpg', 1200, 800)])
            ->assertCreated()
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.width', 1200)
            ->assertJsonPath('data.original_mime', 'image/jpeg')
            ->assertJsonPath('data.status', 'uploaded');

        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('logo.png', 400, 400)])
            ->assertCreated()
            ->assertJsonPath('data.position', 2)
            ->assertJsonPath('data.original_mime', 'image/png');

        $batch = Batch::query()->findOrFail($id);
        $this->assertSame(2, $batch->images_count);
        $this->assertGreaterThan(0, $batch->storage_bytes);
        $this->assertSame($batch->storage_bytes, $user->company->fresh()->storage_bytes);

        // Stored under a random name inside the batch folder, never the client name.
        $path = $batch->images()->first()->original_path;
        $this->assertStringStartsWith($batch->storageDirectory().'/original/', $path);
        $this->assertStringNotContainsString('auto', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_extension_is_not_trusted(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);

        $fake = UploadedFile::fake()->createWithContent('foto.jpg', "<?php echo 'x';");

        $this->postJson("/api/company/batches/{$id}/images", ['file' => $fake])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_type');
    }

    public function test_corrupt_jpeg_is_rejected(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);

        $broken = UploadedFile::fake()->createWithContent('kapot.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 40));

        $this->postJson("/api/company/batches/{$id}/images", ['file' => $broken])
            ->assertStatus(422)
            ->assertJsonPath('code', 'corrupt_file');
    }

    public function test_heic_without_any_converter_is_rejected_with_clear_message(): void
    {
        $this->app->instance(HeicConverter::class, new class extends HeicConverter
        {
            public function convert(string $source, string $targetJpeg): bool
            {
                return false;
            }
        });

        $user = User::factory()->create();
        $id = $this->draft($user);

        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent('IMG_0001.HEIC', self::HEIC)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'conversion_failed');

        // Nothing left behind: no record, no files, no storage counted.
        $batch = Batch::query()->findOrFail($id);
        $this->assertSame(0, $batch->images()->count());
        $this->assertSame([], Storage::disk('local')->allFiles($batch->storageDirectory()));
        $this->assertSame(0, $user->company->fresh()->storage_bytes);
    }

    public function test_heic_is_converted_on_the_server_when_a_converter_exists(): void
    {
        $this->app->instance(HeicConverter::class, new class extends HeicConverter
        {
            public function convert(string $source, string $targetJpeg): bool
            {
                $img = imagecreatetruecolor(1200, 900);
                imagefill($img, 0, 0, imagecolorallocate($img, 90, 120, 150));

                return imagejpeg($img, $targetJpeg, 90);
            }
        });

        $user = User::factory()->create();
        $id = $this->draft($user);

        $response = $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent('IMG_0001.HEIC', self::HEIC)])
            ->assertCreated()
            ->assertJsonPath('data.original_mime', 'image/heic')
            ->assertJsonPath('data.width', 1200)
            ->assertJsonPath('data.height', 900);

        $this->assertStringContainsString('/thumbnail', $response->json('data.urls.thumbnail'));
    }

    public function test_image_files_are_cached_privately_with_etag_and_stay_authorized(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);
        $thumb = $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('a.jpg', 300, 200)])
            ->assertCreated()->json('data.urls.thumbnail');

        $response = $this->get($thumb)->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=604800', $response->headers->get('Cache-Control'));
        $this->assertNotNull($etag = $response->headers->get('ETag'));
        $this->assertNotNull($response->headers->get('Last-Modified'));

        // Same file again: 304 without a body.
        $this->get($thumb, ['If-None-Match' => $etag])->assertStatus(304);

        // Another company never gets it, not even with the ETag.
        $this->actingAs(User::factory()->create())->get($thumb, ['If-None-Match' => $etag])->assertForbidden();
    }

    public function test_max_images_per_batch_is_enforced(): void
    {
        app(SystemSettings::class)->update(['max_images_per_batch' => 2]);
        $user = User::factory()->create();
        $id = $this->draft($user);

        foreach ([1, 2] as $i) {
            $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image("{$i}.jpg")])->assertCreated();
        }

        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('3.jpg')])
            ->assertStatus(422)
            ->assertJsonPath('code', 'too_many_images');
    }

    public function test_file_size_limit(): void
    {
        app(SystemSettings::class)->update(['max_upload_mb' => 1]);
        $user = User::factory()->create();
        $id = $this->draft($user);

        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('groot.jpg')->size(2048)])
            ->assertJsonValidationErrors('file');
    }

    public function test_remove_image_from_draft_and_start_renumbers(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $id = $this->draft($user);

        $ids = collect([1, 2, 3])->map(fn ($i) => $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image("{$i}.jpg")])->json('data.id'));

        $this->deleteJson("/api/company/batches/{$id}/images/{$ids[0]}")->assertOk();

        $this->postJson("/api/company/batches/{$id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.images_count', 2)
            ->assertJsonPath('data.images.0.position', 1)
            ->assertJsonPath('data.images.0.status', 'queued')
            ->assertJsonPath('data.images.1.position', 2)
            ->assertJsonPath('data.progress.total', 2);

        $this->assertSame(1, $user->company->fresh()->batches_total);

        // Locked after start.
        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('4.jpg')])
            ->assertStatus(422)->assertJsonPath('code', 'batch_locked');
        $this->patchJson("/api/company/batches/{$id}", ['strength' => 'strong'])
            ->assertStatus(422)->assertJsonPath('code', 'batch_locked');
    }

    public function test_empty_batch_cannot_start(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);

        $this->postJson("/api/company/batches/{$id}/start")->assertStatus(422)->assertJsonPath('code', 'batch_empty');
    }

    public function test_update_settings_and_validation(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);

        $this->patchJson("/api/company/batches/{$id}", ['name' => 'Golf 8', 'strength' => 'subtle', 'remove_people' => true, 'aspect_ratio' => '4:3'])
            ->assertOk()
            ->assertJsonPath('data.filename_base', 'golf-8')
            ->assertJsonPath('data.settings.strength', 'subtle')
            ->assertJsonPath('data.settings.remove_people', true)
            ->assertJsonPath('data.settings.aspect_ratio', '4:3')
            ->assertJsonPath('data.settings.output_format', 'jpg');

        $this->patchJson("/api/company/batches/{$id}", ['resolution' => '999', 'background' => 'rainbow'])
            ->assertJsonValidationErrors(['resolution', 'background']);

        $this->patchJson("/api/company/batches/{$id}", ['watermark_mode' => 'all'])
            ->assertStatus(422)->assertJsonPath('code', 'watermark_needs_logo');
    }

    public function test_other_company_cannot_touch_batch_or_files(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->draft($owner);
        $imageId = $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('a.jpg')])->json('data.id');

        $this->actingAs($other);
        $this->getJson("/api/company/batches/{$id}")->assertForbidden();
        $this->patchJson("/api/company/batches/{$id}", ['name' => 'x'])->assertForbidden();
        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('b.jpg')])->assertForbidden();
        $this->deleteJson("/api/company/batches/{$id}/images/{$imageId}")->assertForbidden();
        $this->postJson("/api/company/batches/{$id}/start")->assertForbidden();
        $this->deleteJson("/api/company/batches/{$id}")->assertForbidden();
        $this->get("/api/company/images/{$imageId}/original")->assertForbidden();
        $this->getJson('/api/company/batches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_image_must_belong_to_batch_in_url(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $b = $this->draft($user);
        $imageId = $this->postJson("/api/company/batches/{$a}/images", ['file' => UploadedFile::fake()->image('a.jpg')])->json('data.id');

        $this->deleteJson("/api/company/batches/{$b}/images/{$imageId}")->assertNotFound();
    }

    public function test_owner_can_view_original_and_list_batches(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user, ['name' => 'Eerste']);
        $imageId = $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('a.jpg')])->json('data.id');

        $this->get("/api/company/images/{$imageId}/original")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/api/company/images/{$imageId}/optimized")->assertNotFound();

        $this->getJson('/api/company/batches?status=draft')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Eerste')
            ->assertJsonPath('data.0.cover_url', route('api.company.images.file', [$imageId, 'thumbnail']));
    }

    public function test_delete_batch_removes_files_and_storage(): void
    {
        $user = User::factory()->create();
        $id = $this->draft($user);
        $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->image('a.jpg')]);
        $dir = Batch::query()->findOrFail($id)->storageDirectory();

        $this->deleteJson("/api/company/batches/{$id}")->assertOk();

        $this->assertDatabaseMissing('batches', ['id' => $id]);
        $this->assertSame([], Storage::disk('local')->allFiles($dir));
        $this->assertSame(0, $user->company->fresh()->storage_bytes);
    }

    public function test_super_admin_cannot_use_company_upload(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/company/batches')
            ->assertForbidden();
    }
}
