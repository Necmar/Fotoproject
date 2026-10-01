<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Phase 7: company logo, watermark only on download, single and ZIP downloads. */
class DownloadAndWatermarkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function png(int $w = 400, int $h = 200): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 20, 20, $w - 20, $h - 20, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent('logo.png', ob_get_clean());
    }

    private function processedBatch(array $settings = ['name' => 'BMW 320i'], int $photos = 2): Batch
    {
        $id = $this->postJson('/api/company/batches', $settings)->json('data.id');
        for ($i = 0; $i < $photos; $i++) {
            $img = imagecreatetruecolor(600, 400);
            imagefill($img, 0, 0, imagecolorallocate($img, 30, 60 + 40 * $i, 90));
            ob_start();
            imagejpeg($img, null, 90);
            $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent("{$i}.jpg", ob_get_clean())])->assertCreated();
        }
        $this->postJson("/api/company/batches/{$id}/start")->assertOk();
        $this->artisan('bora:work')->assertSuccessful();

        return Batch::query()->findOrFail($id);
    }

    private function downloadBody(string $url): string
    {
        $response = $this->get($url)->assertOk();

        return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    public function test_logo_upload_replace_and_remove(): void
    {
        $first = $this->postJson('/api/company/logo', ['logo' => $this->png()])
            ->assertOk()->assertJsonPath('data.has_logo', true)->json('data.logo_url');
        $path = $this->user->company->fresh()->logo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(Storage::disk('local')->get($path))[2]);
        $this->get($first)->assertOk();

        $this->postJson('/api/company/logo', ['logo' => $this->png(1600, 400)])->assertOk();
        $new = $this->user->company->fresh()->logo_path;
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(1200, getimagesizefromstring(Storage::disk('local')->get($new))[0], 'resized to max 1200 px');
        $this->assertDatabaseHas('activity_logs', ['action' => 'logo_changed']);

        $this->deleteJson('/api/company/logo')->assertOk()->assertJsonPath('data.has_logo', false);
        Storage::disk('local')->assertMissing($new);
        $this->assertSame(0, $this->user->company->fresh()->storage_bytes);
    }

    public function test_invalid_logo_is_rejected(): void
    {
        $this->postJson('/api/company/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', 'GIF89a nope')])
            ->assertStatus(422)->assertJsonPath('code', 'logo_invalid');
    }

    public function test_logo_is_private_to_the_company(): void
    {
        $this->postJson('/api/company/logo', ['logo' => $this->png()])->assertOk();

        $this->actingAs(User::factory()->create())->get('/api/company/logo')->assertNotFound();
    }

    public function test_single_download_uses_file_name_and_has_no_metadata(): void
    {
        $batch = $this->processedBatch();
        $image = $batch->images()->first();

        $response = $this->get("/api/company/images/{$image->id}/download")->assertOk();
        $this->assertStringContainsString('bmw-320i-01.jpg', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('Exif', $this->downloadBody("/api/company/images/{$image->id}/download"));
    }

    public function test_watermark_only_on_download_never_on_the_stored_result(): void
    {
        $this->postJson('/api/company/logo', ['logo' => $this->png()])->assertOk();
        $batch = $this->processedBatch();
        $image = $batch->images()->first();
        $stored = Storage::disk('local')->get($image->optimized_path);

        $plain = $this->downloadBody("/api/company/images/{$image->id}/download");
        $this->assertSame($stored, $plain, 'no watermark while mode is none');

        $this->putJson("/api/company/batches/{$batch->id}/watermark", ['watermark_mode' => 'all', 'watermark_position' => 'bottom_right', 'watermark_opacity' => 100])
            ->assertOk()->assertJsonPath('data.settings.watermark_mode', 'all');

        $marked = $this->downloadBody("/api/company/images/{$image->id}/download");
        $this->assertNotSame($stored, $marked);
        $this->assertSame($stored, Storage::disk('local')->get($image->fresh()->optimized_path), 'stored result unchanged');

        // The red logo sits in the bottom-right corner.
        $gd = imagecreatefromstring($marked);
        $rgb = imagecolorat($gd, imagesx($gd) - 60, imagesy($gd) - 40);
        $this->assertGreaterThan(200, ($rgb >> 16) & 0xFF);
    }

    public function test_watermark_on_selected_photos_only(): void
    {
        $this->postJson('/api/company/logo', ['logo' => $this->png()])->assertOk();
        $batch = $this->processedBatch();
        [$a, $b] = $batch->images()->get();
        $this->putJson("/api/company/batches/{$batch->id}/watermark", ['watermark_mode' => 'selected', 'watermark_position' => 'center', 'watermark_opacity' => 70])->assertOk();

        $this->patchJson("/api/company/images/{$a->id}/watermark", ['apply' => true])->assertOk()->assertJsonPath('data.apply_watermark', true);

        $this->assertNotSame(Storage::disk('local')->get($a->optimized_path), $this->downloadBody("/api/company/images/{$a->id}/download"));
        $this->assertSame(Storage::disk('local')->get($b->optimized_path), $this->downloadBody("/api/company/images/{$b->id}/download"));
    }

    public function test_watermark_needs_a_logo(): void
    {
        $batch = $this->processedBatch();

        $this->putJson("/api/company/batches/{$batch->id}/watermark", ['watermark_mode' => 'all', 'watermark_position' => 'center', 'watermark_opacity' => 70])
            ->assertStatus(422)->assertJsonPath('code', 'watermark_needs_logo');
    }

    public function test_zip_contains_all_finished_photos_and_is_reused_until_something_changes(): void
    {
        $this->postJson('/api/company/logo', ['logo' => $this->png()])->assertOk();
        $batch = $this->processedBatch();

        $response = $this->get("/api/company/batches/{$batch->id}/download")->assertOk();
        $this->assertStringContainsString('bmw-320i.zip', $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertSame(['bmw-320i-01.jpg', 'bmw-320i-02.jpg'], $names);

        $first = $batch->fresh()->zip_path;
        $this->get("/api/company/batches/{$batch->id}/download")->assertOk();
        $this->assertSame($first, $batch->fresh()->zip_path, 'reused');

        $this->putJson("/api/company/batches/{$batch->id}/watermark", ['watermark_mode' => 'all', 'watermark_position' => 'top_left', 'watermark_opacity' => 50])->assertOk();
        $this->get("/api/company/batches/{$batch->id}/download")->assertOk();
        $this->assertNotSame($first, $batch->fresh()->zip_path, 'rebuilt after watermark change');
        Storage::disk('local')->assertMissing($first);
    }

    public function test_nothing_to_download_and_tenant_isolation(): void
    {
        $batch = $this->processedBatch();
        $image = $batch->images()->first();

        $draft = $this->postJson('/api/company/batches')->json('data.id');
        $this->getJson("/api/company/batches/{$draft}/download")->assertStatus(422)->assertJsonPath('code', 'nothing_to_download');

        $this->actingAs(User::factory()->create());
        $this->get("/api/company/images/{$image->id}/download")->assertForbidden();
        $this->get("/api/company/batches/{$batch->id}/download")->assertForbidden();
        $this->putJson("/api/company/batches/{$batch->id}/watermark", ['watermark_mode' => 'none', 'watermark_position' => 'center', 'watermark_opacity' => 70])->assertForbidden();
        $this->patchJson("/api/company/images/{$image->id}/watermark", ['apply' => true])->assertForbidden();
    }

    public function test_missing_file_gives_404_and_zip_skips_it(): void
    {
        $batch = $this->processedBatch(photos: 2);
        [$lost, $kept] = $batch->images()->orderBy('position')->get()->all();
        Storage::disk(config('bora.disk'))->delete($lost->optimized_path);

        $this->getJson("/api/company/images/{$lost->id}/download")->assertNotFound()->assertJsonPath('code', 'file_missing');

        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $this->downloadBody("/api/company/batches/{$batch->id}/download"));
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame([$kept->output_filename], [$zip->getNameIndex(0)]);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
        @unlink($path);
    }

    public function test_rate_limits_do_not_share_one_counter(): void
    {
        $image = $this->processedBatch(photos: 1)->images()->first();
        for ($i = 0; $i < 12; $i++) {
            $this->get("/api/company/images/{$image->id}/download")->assertOk();
        }
        // The logo limit is 10 per minute: downloads must not have used it up.
        $this->post('/api/company/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])->assertSuccessful();
    }
}
