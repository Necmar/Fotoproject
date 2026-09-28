<?php

namespace Tests\Feature;

use App\Jobs\ProcessImage;
use App\Models\Batch;
use App\Models\Image;
use App\Models\User;
use App\Services\Images\OutputRenderer;
use App\Services\Processing\QueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Phase 4: one job per image, cron-driven worker, retries, watchdog, cron URL. */
class QueueProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function startedBatch(int $photos = 2): Batch
    {
        $id = $this->actingAs($this->user)->postJson('/api/company/batches')->json('data.id');

        for ($i = 0; $i < $photos; $i++) {
            $img = imagecreatetruecolor(800, 600);
            imagefill($img, 0, 0, imagecolorallocate($img, 40 * $i, 120, 200 - 30 * $i));
            imagefilledrectangle($img, 100 + 50 * $i, 100, 400, 400 + 20 * $i, imagecolorallocate($img, 250, 250, 20));
            ob_start();
            imagejpeg($img, null, 90);
            $this->postJson("/api/company/batches/{$id}/images", ['file' => UploadedFile::fake()->createWithContent("{$i}.jpg", ob_get_clean())])->assertCreated();
        }

        $this->postJson("/api/company/batches/{$id}/start")->assertOk();

        return Batch::query()->findOrFail($id);
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    public function test_start_dispatches_one_job_per_image_on_the_images_queue(): void
    {
        Queue::fake();
        $batch = $this->startedBatch(3);

        Queue::assertPushed(ProcessImage::class, 3);
        Queue::assertPushedOn('images', ProcessImage::class);
        $this->assertSame('queued', $batch->status->value);
    }

    public function test_worker_processes_the_whole_batch_and_stops_when_empty(): void
    {
        $this->useDatabaseQueue();
        $batch = $this->startedBatch(3);
        $this->assertSame(3, DB::table('jobs')->count());

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $batch->refresh();
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(3, $batch->completed_count);
        $this->assertNotNull(Cache::get(QueueHealth::HEARTBEAT_KEY));
    }

    public function test_only_one_worker_runs_at_a_time(): void
    {
        $this->useDatabaseQueue();
        $this->startedBatch(1);

        $lock = Cache::lock('bora:worker', 60);
        $lock->get();

        $this->artisan('bora:work')->expectsOutputToContain('al een worker')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count(), 'nothing processed while locked');

        $lock->release();
        $this->artisan('bora:work')->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_temporary_error_is_retried_and_then_succeeds(): void
    {
        $this->useDatabaseQueue();
        config(['bora.queue.backoff' => [0, 0, 0]]);
        $batch = $this->startedBatch(1);

        // First render call fails (e.g. disk hiccup), second works.
        $real = app(OutputRenderer::class);
        $calls = 0;
        $this->app->instance(OutputRenderer::class, new class($real, $calls) extends OutputRenderer
        {
            public function __construct(private OutputRenderer $inner, private int &$calls) {}

            public function render(...$args): Image
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('temporary');
                }

                return $this->inner->render(...$args);
            }
        });

        $this->artisan('bora:work')->assertSuccessful();

        $image = $batch->images()->first();
        $this->assertSame('completed', $image->status->value);
        $this->assertSame(1, $image->attempts);
        $this->assertSame('completed', $batch->fresh()->status->value);
        $this->assertSame(0, $this->user->company->fresh()->images_failed_total);
    }

    public function test_permanent_error_fails_only_that_image_after_all_tries(): void
    {
        $this->useDatabaseQueue();
        config(['bora.queue.backoff' => [0, 0, 0]]);
        $batch = $this->startedBatch(2);
        $broken = $batch->images()->first();

        $real = app(OutputRenderer::class);
        $this->app->instance(OutputRenderer::class, new class($real, $broken->id) extends OutputRenderer
        {
            public function __construct(private OutputRenderer $inner, private string $brokenId) {}

            public function render(...$args): Image
            {
                if ($args[0]->id === $this->brokenId) {
                    throw new RuntimeException('always broken');
                }

                return $this->inner->render(...$args);
            }
        });

        $this->artisan('bora:work')->assertSuccessful();

        $broken->refresh();
        $this->assertSame('failed', $broken->status->value);
        $this->assertSame(__('messages.processing.failed'), $broken->error_message);
        $this->assertStringNotContainsString('always broken', $broken->error_message, 'no technical details for users');
        $this->assertSame('completed', $batch->images()->get()[1]->status->value);
        $this->assertSame('completed_with_errors', $batch->fresh()->status->value);
        $this->assertSame(1, $this->user->company->fresh()->images_failed_total);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_job_for_deleted_batch_is_ignored(): void
    {
        $this->useDatabaseQueue();
        $batch = $this->startedBatch(1);
        $this->deleteJson("/api/company/batches/{$batch->id}")->assertOk();

        $this->artisan('bora:work')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_watchdog_requeues_stuck_images(): void
    {
        $this->useDatabaseQueue();
        $batch = $this->startedBatch(1);
        DB::table('jobs')->delete(); // job lost
        $image = $batch->images()->first();
        Cache::lock('laravel_unique_job:'.ProcessImage::class.':'.$image->id)->forceRelease(); // lock expired meanwhile

        $image->forceFill(['status' => 'processing'])->save();
        Image::query()->whereKey($image->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('bora:recover-stuck')->expectsOutputToContain('Opnieuw in wachtrij: 1')->assertSuccessful();

        $this->assertSame('queued', $image->fresh()->status->value);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('bora:work')->assertSuccessful();
        $this->assertSame('completed', $batch->fresh()->status->value);
    }

    public function test_polling_shows_progress_while_processing(): void
    {
        $this->useDatabaseQueue();
        $batch = $this->startedBatch(2);

        $this->getJson("/api/company/batches/{$batch->id}")
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress.processed', 0);

        // Process exactly one job.
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'images', '--once' => true]);

        $this->getJson("/api/company/batches/{$batch->id}")
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress.completed', 1)
            ->assertJsonPath('data.progress.percent', 50)
            ->assertJsonPath('data.images.0.status', 'completed')
            ->assertJsonPath('data.images.1.status', 'queued');
    }

    public function test_cron_url_requires_a_long_secret_token(): void
    {
        $this->get('/cron/whatever')->assertNotFound();

        config(['bora.queue.cron_token' => 'short']);
        $this->get('/cron/short')->assertNotFound();

        $token = str_repeat('a1B2', 10);
        config(['bora.queue.cron_token' => $token]);
        $this->get('/cron/wrong'.$token)->assertNotFound();
        $this->get("/cron/{$token}")->assertOk()->assertSee('OK');
    }

    public function test_admin_sees_queue_health(): void
    {
        $this->useDatabaseQueue();
        $this->startedBatch(1);
        Cache::forget(QueueHealth::HEARTBEAT_KEY);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.queue.status', 'stale')
            ->assertJsonPath('data.queue.pending_jobs', 1);
    }
}
