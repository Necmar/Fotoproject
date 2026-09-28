<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ImageProcessingRecord;
use App\Models\User;
use App\Notifications\BatchCompleted;
use App\Services\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 8: history, retention cleanup, e-mail when a batch is done. */
class HistoryCleanupMailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        config(['queue.default' => 'database']);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function processedBatch(string $name = 'Golf 8', int $photos = 2): Batch
    {
        $id = $this->postJson('/api/company/batches', ['name' => $name])->json('data.id');
        for ($i = 0; $i < $photos; $i++) {
            $img = imagecreatetruecolor(800, 600);
            imagefill($img, 0, 0, imagecolorallocate($img, 40, 80 + 30 * $i, 120));
            ob_start();
            imagejpeg($img, null, 90);
            $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent("{$i}.jpg", ob_get_clean())])->assertCreated();
        }
        $this->postJson("/api/company/batches/{$id}/start")->assertOk();
        $this->artisan('bora:work')->assertSuccessful();

        return Batch::query()->findOrFail($id);
    }

    public function test_mail_is_sent_once_when_the_batch_is_done(): void
    {
        $batch = $this->processedBatch();

        Notification::assertSentToTimes($this->user, BatchCompleted::class, 1);
        Notification::assertSentTo($this->user, BatchCompleted::class, function (BatchCompleted $n) use ($batch) {
            $mail = $n->toMail($this->user);
            $text = implode("\n", $mail->introLines);

            return $n->batch->is($batch)
                && str_contains($text, 'Aantal succesvol: 2')
                && str_contains($text, 'Aantal met fout: 0')
                && str_contains($mail->actionUrl, "/batches/{$batch->id}")
                && $mail->attachments === [] && $mail->rawAttachments === [];
        });

        // Re-optimising a photo finishes the batch again, but does not send a second mail.
        $image = $batch->images()->first();
        $this->postJson("/api/company/images/{$image->id}/reoptimize", ['strength' => 'strong', 'background' => 'keep', 'remove_people' => false])->assertStatus(202);
        $this->artisan('bora:work')->assertSuccessful();

        Notification::assertSentToTimes($this->user, BatchCompleted::class, 1);
    }

    public function test_cleanup_removes_expired_batches_and_files_but_keeps_statistics(): void
    {
        $old = $this->processedBatch('Oud');
        $recent = $this->processedBatch('Nieuw');
        $old->forceFill(['expires_at' => now()->subHour()])->save();
        $oldDir = $old->storageDirectory();
        $this->assertNotEmpty(Storage::disk('local')->allFiles($oldDir));
        $records = ImageProcessingRecord::query()->where('batch_id', $old->id)->count();

        $this->artisan('bora:cleanup')->expectsOutputToContain('Verlopen batches: 1')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertSame([], Storage::disk('local')->allFiles($oldDir));
        $this->assertModelExists($recent);
        $this->assertNotEmpty(Storage::disk('local')->allFiles($recent->storageDirectory()));

        // Statistics survive, anonymised.
        $this->assertSame($records, ImageProcessingRecord::query()->whereNull('batch_id')->count());
        $this->assertSame(4, $this->user->company->fresh()->images_processed_total);
        $this->assertSame($recent->fresh()->storage_bytes, $this->user->company->fresh()->storage_bytes);
    }

    public function test_cleanup_removes_old_empty_drafts_and_temp_files(): void
    {
        $draft = Batch::query()->findOrFail($this->postJson('/api/company/batches')->json('data.id'));
        Batch::query()->whereKey($draft->id)->update(['created_at' => now()->subDays(2)]);
        $fresh = $this->postJson('/api/company/batches')->json('data.id');

        $dir = storage_path('app/tmp');
        @mkdir($dir, 0775, true);
        touch($stale = $dir.'/stale-test.tmp', now()->subDays(2)->getTimestamp());
        touch($new = $dir.'/new-test.tmp');

        $this->artisan('bora:cleanup')->assertSuccessful();

        $this->assertModelMissing($draft);
        $this->assertDatabaseHas('batches', ['id' => $fresh]);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($new);
        @unlink($new);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $batch = $this->processedBatch();
        $batch->forceFill(['expires_at' => now()->subHour()])->save();

        $this->artisan('bora:cleanup', ['--dry-run' => true])->expectsOutputToContain('[dry-run]')->assertSuccessful();

        $this->assertModelExists($batch);
    }

    public function test_history_lists_own_batches_within_retention_only(): void
    {
        $visible = $this->processedBatch('Zichtbaar');
        $expired = $this->processedBatch('Verlopen');
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/company/batches?per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.settings.output_format', 'jpg')
            ->assertJsonPath('data.0.download_url', route('api.company.batches.download', $visible->id))
            ->assertJsonStructure(['data' => [['name', 'status', 'images_count', 'cover_url', 'created_at', 'expires_at']], 'meta' => ['last_page']]);

        $this->actingAs(User::factory()->create())->getJson('/api/company/batches')->assertJsonCount(0, 'data');
    }

    public function test_changing_retention_updates_expiry_dates(): void
    {
        $batch = $this->processedBatch();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/admin/settings', ['retention_days' => 3])->assertOk();

        $this->assertEqualsWithDelta($batch->started_at->copy()->addDays(3)->getTimestamp(), $batch->fresh()->expires_at->getTimestamp(), 2);
        $this->assertSame(3, app(SystemSettings::class)->retentionDays());
    }
}
