<?php

namespace App\Services\OpenAI;

use App\Models\ImageProcessingRecord;
use App\Services\Storage\LocalFiles;
use App\Support\BatchSettings;
use Illuminate\Support\Str;
use Throwable;

/**
 * Super Admin "Verbinding testen": checks the key, access to both models, a
 * real (tiny) analysis and optionally a real (small, low quality) edit, and
 * reports OpenAI's own error message for each step.
 */
class ConnectionTester
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly ImageAnalyzer $analyzer,
        private readonly ImageEditService $editor,
        private readonly LocalFiles $files,
    ) {}

    /** @return list<array{step: string, ok: bool, message: ?string, ms: int}> */
    public function run(bool $withEdit): array
    {
        $results = [];

        if (! $this->client->isConfigured()) {
            return [['step' => 'key', 'ok' => false, 'message' => 'OPENAI_API_KEY ontbreekt in .env', 'ms' => 0]];
        }
        $results[] = ['step' => 'key', 'ok' => true, 'message' => null, 'ms' => 0];

        foreach (['analysis_model', 'image_model'] as $key) {
            $model = (string) config("services.openai.{$key}");
            $results[] = $this->step($key, fn () => $this->client->get('models/'.rawurlencode($model)), $model);
        }

        $path = 'tmp/openai-test-'.Str::lower(Str::random(8)).'.jpg';
        $this->files->disk()->put($path, $this->sampleJpeg());

        try {
            $settings = BatchSettings::fromArray([]);
            $results[] = $this->step('analysis', fn () => $this->analyzer->analyze($path, $settings));

            if ($withEdit) {
                $results[] = $this->step('edit', function () use ($path) {
                    $edit = $this->editor->edit($path, 'Make the background plain light grey. Keep the red object exactly as it is.', false, $this->files->tempPath('img'), 'low', 1024);
                    @unlink($edit['path']);
                });
            }
        } finally {
            $this->files->disk()->delete($path);
        }

        return $results;
    }

    /** Most recent failed OpenAI call, for the admin screen. */
    public function lastError(): ?array
    {
        $record = ImageProcessingRecord::query()
            ->where('provider', 'openai')
            ->where('status', ImageProcessingRecord::STATUS_FAILED)
            ->latest('id')
            ->first();

        return $record ? [
            'at' => $record->created_at?->toIso8601String(),
            'type' => $record->type?->value,
            'model' => $record->model,
            'code' => $record->error_code,
            'message' => $record->error_message,
        ] : null;
    }

    private function step(string $name, callable $call, ?string $detail = null): array
    {
        $started = microtime(true);

        try {
            $call();

            return ['step' => $name, 'ok' => true, 'message' => $detail, 'ms' => (int) round((microtime(true) - $started) * 1000)];
        } catch (OpenAIException $e) {
            $message = trim(($detail ? "{$detail}: " : '').$e->getMessage().($e->httpStatus ? " (HTTP {$e->httpStatus})" : ''));
        } catch (Throwable $e) {
            $message = ($detail ? "{$detail}: " : '').$e->getMessage();
        }

        return ['step' => $name, 'ok' => false, 'message' => mb_substr($message, 0, 500), 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    private function sampleJpeg(): string
    {
        $img = imagecreatetruecolor(512, 384);
        imagefill($img, 0, 0, imagecolorallocate($img, 180, 180, 175));
        imagefilledrectangle($img, 140, 120, 372, 270, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img, null, 88);

        return (string) ob_get_clean();
    }
}
