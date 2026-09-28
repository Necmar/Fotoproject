<?php

namespace Tests\Feature;

use App\Enums\AspectRatio;
use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use App\Services\Images\ExifOrientation;
use App\Services\Images\ImageEditor;
use App\Services\Images\OutputRenderer;
use App\Support\BatchSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 3: working copy, orientation, metadata, resizing, cropping, output, duplicates. */
class ImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $batchId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Jobs wait in the database queue; tests process them explicitly.
        config(['queue.default' => 'database']);
        $this->user = User::factory()->create();
        $this->batchId = $this->actingAs($this->user)->postJson('/api/company/batches', ['name' => 'BMW 320i'])->json('data.id');
    }

    /** JPEG bytes from GD, optionally with an EXIF block (orientation + fake GPS marker). */
    private function jpeg(int $w, int $h, array $rgb = [120, 140, 160], ?int $orientation = null, bool $pattern = true): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        if ($pattern) {
            // Some structure so hashes and crops have something to work with.
            imagefilledrectangle($img, (int) ($w * 0.1), (int) ($h * 0.2), (int) ($w * 0.35), (int) ($h * 0.7), imagecolorallocate($img, 20, 20, 20));
            imagefilledellipse($img, (int) ($w * 0.7), (int) ($h * 0.5), (int) ($w * 0.2), (int) ($h * 0.3), imagecolorallocate($img, 230, 200, 40));
        }
        ob_start();
        imagejpeg($img, null, 92);
        $bytes = ob_get_clean();

        if ($orientation === null) {
            return $bytes;
        }

        // TIFF (big endian): IFD0 with Orientation + a "GPSInfo" pointer tag, to check metadata stripping.
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"
            ."\x00\x02"
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
            ."\x88\x25\x00\x04\x00\x00\x00\x01\x00\x00\x00\x00"
            ."\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($bytes, 2);
    }

    private function upload(string $bytes, string $name = 'foto.jpg'): Image
    {
        $id = $this->postJson("/api/company/batches/{$this->batchId}/images", ['file' => UploadedFile::fake()->createWithContent($name, $bytes)])
            ->assertCreated()
            ->json('data.id');

        return Image::query()->findOrFail($id);
    }

    private function disk()
    {
        return Storage::disk('local');
    }

    public function test_upload_creates_oriented_metadata_free_working_copy_and_thumbnail(): void
    {
        $bytes = $this->jpeg(800, 400, orientation: 6);
        $this->assertStringContainsString('Exif', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($tmp, $bytes);
        $this->assertSame(6, ExifOrientation::read($tmp));
        unlink($tmp);

        $image = $this->upload($bytes);

        // Orientation 6 = rotate 90° clockwise: 800x400 becomes 400x800.
        $this->assertSame(400, $image->width);
        $this->assertSame(800, $image->height);

        $working = $this->disk()->get($image->working_path);
        $this->assertStringNotContainsString('Exif', $working);
        [$ww, $wh] = getimagesizefromstring($working);
        $this->assertSame([400, 800], [$ww, $wh]);

        [$tw, $th] = getimagesizefromstring($this->disk()->get($image->thumbnail_path));
        $this->assertSame(480, max($tw, $th));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $image->perceptual_hash);
        $this->assertArrayHasKey('sharpness', $image->analysis['local']);
    }

    public function test_working_copy_is_limited_to_3072_px(): void
    {
        $image = $this->upload($this->jpeg(4000, 3000));

        [$w, $h] = getimagesizefromstring($this->disk()->get($image->working_path));
        $this->assertSame([3072, 2304], [$w, $h]);
        $this->assertSame([4000, 3000], [$image->width, $image->height]);
    }

    public function test_storage_counter_matches_files_on_disk(): void
    {
        $this->upload($this->jpeg(1600, 1200));
        $this->upload($this->jpeg(900, 900, [200, 90, 90]));

        $batch = Batch::query()->findOrFail($this->batchId);
        $onDisk = array_sum(array_map(fn ($f) => $this->disk()->size($f), $this->disk()->allFiles($batch->storageDirectory())));

        $this->assertSame($onDisk, $batch->storage_bytes);
        $this->assertSame($onDisk, $this->user->company->fresh()->storage_bytes);
    }

    public function test_dark_photo_gets_a_warning(): void
    {
        $image = $this->upload($this->jpeg(600, 400, [8, 8, 10], pattern: false));

        $codes = array_column($image->warnings, 'code');
        $this->assertContains('too_dark', $codes);

        $this->getJson("/api/company/batches/{$this->batchId}")
            ->assertJsonPath('data.images.0.warnings.0.code', 'too_dark')
            ->assertJsonPath('data.images.0.warnings.0.message', __('messages.warnings.too_dark'));
    }

    public function test_output_resize_never_upscales_and_uses_file_name(): void
    {
        $small = $this->upload($this->jpeg(1200, 800));
        $large = $this->upload($this->jpeg(4000, 3000, [60, 100, 140]));
        $renderer = app(OutputRenderer::class);
        $settings = BatchSettings::fromArray(['resolution' => '2000', 'output_format' => 'jpg']);

        $renderer->render($small, $settings);
        $renderer->render($large, $settings);

        $this->assertSame([1200, 800], [$small->output_width, $small->output_height]);
        $this->assertSame([2000, 1500], [$large->output_width, $large->output_height]);
        $this->assertSame('bmw-320i-01.jpg', $small->output_filename);
        $this->assertSame('bmw-320i-02.jpg', $large->output_filename);

        $out = $this->disk()->get($large->optimized_path);
        $this->assertStringNotContainsString('Exif', $out);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($out)[2]);
    }

    public function test_aspect_ratios_and_png_output(): void
    {
        $image = $this->upload($this->jpeg(1600, 1200));
        $renderer = app(OutputRenderer::class);

        foreach (['1:1' => [1200, 1200], '16:9' => [1600, 900], '3:2' => [1600, 1067]] as $ratio => $expected) {
            $renderer->render($image, BatchSettings::fromArray(['aspect_ratio' => $ratio, 'resolution' => '2560']));
            $this->assertSame($expected, [$image->output_width, $image->output_height], $ratio);
        }

        $previous = $image->optimized_path;
        $renderer->render($image, BatchSettings::fromArray(['output_format' => 'png', 'resolution' => '1600']));
        $this->assertSame('bmw-320i-01.png', $image->output_filename);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($this->disk()->get($image->optimized_path))[2]);
        $this->assertFalse($this->disk()->exists($previous), 'old render replaced');
    }

    public function test_crop_keeps_a_wide_product_in_view_by_extending_the_canvas(): void
    {
        $img = imagecreatetruecolor(1600, 900);
        $editor = ImageEditor::fromGd($img);

        // Product spans 90% of the width: a 1:1 crop would cut it, so the canvas grows instead.
        $editor->cropToRatio(AspectRatio::Square->ratio(), ['x' => 0.05, 'y' => 0.3, 'w' => 0.9, 'h' => 0.4]);

        $this->assertSame($editor->width(), $editor->height());
        $this->assertGreaterThanOrEqual((int) (1600 * 0.9), $editor->width());
    }

    public function test_crop_follows_the_focus_box(): void
    {
        $img = imagecreatetruecolor(2000, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 1500, 300, 1900, 700, imagecolorallocate($img, 255, 0, 0));
        $editor = ImageEditor::fromGd($img);

        $editor->cropToRatio(1.0, ['x' => 0.75, 'y' => 0.3, 'w' => 0.2, 'h' => 0.4]);

        $this->assertSame([1000, 1000], [$editor->width(), $editor->height()]);
        // The red product is inside the crop.
        $rgb = imagecolorat($editor->gd(), $editor->width() - 300, 500);
        $this->assertSame(255, ($rgb >> 16) & 0xFF);
        $this->assertSame(0, ($rgb >> 8) & 0xFF);
    }

    public function test_crop_without_focus_prefers_detail(): void
    {
        $img = imagecreatetruecolor(2000, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        // All detail on the left side.
        for ($i = 0; $i < 40; $i++) {
            imageline($img, 50 + $i * 20, 100, 60 + $i * 20, 900, imagecolorallocate($img, 0, 0, 0));
        }
        $editor = ImageEditor::fromGd($img);

        $editor->cropToRatio(1.0);

        // Count dark line pixels on one row of the crop: (nearly) all 40 lines are kept.
        $dark = 0;
        for ($x = 0; $x < $editor->width(); $x++) {
            $dark += (imagecolorat($editor->gd(), $x, 500) & 0xFF) < 100 ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual(38, $dark, 'left detail kept');
    }

    public function test_png_with_transparency_becomes_white_in_jpeg(): void
    {
        $png = imagecreatetruecolor(400, 300);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
        imagefilledrectangle($png, 100, 100, 300, 200, imagecolorallocate($png, 0, 0, 200));
        ob_start();
        imagepng($png);
        $image = $this->upload(ob_get_clean(), 'logo.png');

        $working = imagecreatefromstring($this->disk()->get($image->working_path));
        $corner = imagecolorat($working, 5, 5);
        $this->assertGreaterThan(240, ($corner >> 16) & 0xFF);
        $this->assertGreaterThan(240, $corner & 0xFF);
    }

    public function test_duplicates_are_marked_on_start_not_removed(): void
    {
        $bytes = $this->jpeg(1200, 800);
        $this->upload($bytes, 'a.jpg');
        $this->upload($this->jpeg(900, 600, [60, 60, 200], pattern: false), 'b.jpg');
        $this->upload($bytes, 'a-kopie.jpg');

        // Same photo, re-encoded and slightly smaller: similar, not identical.
        $img = imagecreatefromstring($bytes);
        $smaller = imagescale($img, 1100);
        ob_start();
        imagejpeg($smaller, null, 70);
        $this->upload(ob_get_clean(), 'a-klein.jpg');

        $this->postJson("/api/company/batches/{$this->batchId}/start")->assertOk();
        $images = Batch::query()->findOrFail($this->batchId)->images()->get();

        $this->assertCount(4, $images);
        $this->assertNull($images[0]->duplicate_of_id);
        $this->assertNull($images[1]->duplicate_of_id);
        $this->assertSame($images[0]->id, $images[2]->duplicate_of_id);
        $this->assertSame('duplicate_exact', collect($images[2]->warnings)->firstWhere('source', 'duplicate')['code']);
        $this->assertSame($images[0]->id, $images[3]->duplicate_of_id);
        $this->assertSame('duplicate_similar', collect($images[3]->warnings)->firstWhere('source', 'duplicate')['code']);

        $this->getJson("/api/company/batches/{$this->batchId}")
            ->assertJsonFragment(['message' => __('messages.warnings.duplicate_exact', ['position' => 1])]);
    }

    public function test_local_processing_completes_the_batch(): void
    {
        $this->upload($this->jpeg(1600, 1200));
        $this->upload($this->jpeg(1200, 1600, [150, 120, 90]));
        $this->patchJson("/api/company/batches/{$this->batchId}", ['aspect_ratio' => '4:3', 'resolution' => '1600']);
        $this->postJson("/api/company/batches/{$this->batchId}/start")->assertOk();

        $this->artisan('bora:process-local', ['batch' => $this->batchId])->assertSuccessful();

        $batch = Batch::query()->findOrFail($this->batchId);
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(2, $batch->completed_count);
        $this->assertNotNull($batch->completed_at);

        foreach ($batch->images as $image) {
            $this->assertSame('completed', $image->status->value);
            $this->assertTrue($this->disk()->exists($image->optimized_path));
            $this->assertEqualsWithDelta(4 / 3, $image->output_width / $image->output_height, 0.01);
            $this->assertLessThanOrEqual(1600, max($image->output_width, $image->output_height));
        }

        $this->assertSame(2, $this->user->company->fresh()->images_processed_total);
        $this->assertDatabaseCount('image_processing_records', 2);
        $this->getJson('/api/company/stats')->assertJsonPath('data.images_last_30_days', 2);

        $this->getJson("/api/company/batches/{$this->batchId}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress.completed', 2)
            ->assertJsonPath('data.progress.percent', 100);
    }

    public function test_a_failing_image_does_not_block_the_rest(): void
    {
        $good = $this->upload($this->jpeg(1000, 800));
        $bad = $this->upload($this->jpeg(1000, 800, [10, 200, 10]));
        $this->postJson("/api/company/batches/{$this->batchId}/start")->assertOk();

        // Simulate a lost working copy AND original: this image cannot be processed.
        $this->disk()->delete([$bad->working_path, $bad->original_path]);

        $this->artisan('bora:process-local', ['batch' => $this->batchId])->assertSuccessful();

        $this->assertSame('completed', $good->fresh()->status->value);
        $this->assertSame('failed', $bad->fresh()->status->value);
        $this->assertNotNull($bad->fresh()->error_message);
        $this->assertSame('completed_with_errors', Batch::query()->findOrFail($this->batchId)->status->value);
        $this->assertSame(1, $this->user->company->fresh()->images_failed_total);
    }
}
