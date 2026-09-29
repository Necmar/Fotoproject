<?php

namespace App\Services\OpenAI;

/**
 * "Afbeelding bewerken": sends the working copy and the instruction to the
 * Images edit endpoint and writes the result to a local temp file.
 */
class ImageEditService
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly ImageInput $input,
    ) {}

    /** @return array{path: string, extension: string, usage: Usage} */
    public function edit(string $workingPath, string $prompt, bool $transparent, string $tempTarget): array
    {
        $model = (string) config('services.openai.image_model');
        $input = $this->input->editFile($workingPath, (int) config('services.openai.max_side'));

        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            // Set by the Super Admin (Systeem > Verwerking): medium = faster, high = finest detail.
            'quality' => (string) app(\App\Services\SystemSettings::class)->get('ai_image_quality', config('services.openai.image_quality', 'medium')),
            'output_format' => $transparent ? 'png' : 'jpeg',
        ];

        if (! $transparent) {
            $fields['output_compression'] = 95;
        } else {
            $fields['background'] = 'transparent';
        }

        // gpt-image-1.x uses input_fidelity; gpt-image-2+ always keeps high fidelity and ignores it.
        if (str_starts_with($model, 'gpt-image-1')) {
            $fields['input_fidelity'] = (string) config('services.openai.input_fidelity', 'high');
        }

        try {
            $fields['size'] = $this->size($input['width'], $input['height']);

            try {
                $response = $this->client->imageEdit($fields, ['image' => $input['path']]);
            } catch (OpenAIException $e) {
                // Not every model accepts custom sizes: retry once with automatic sizing.
                if ($e->reason !== 'invalid_request' || $fields['size'] === 'auto' || ! str_contains(strtolower($e->getMessage()), 'size')) {
                    throw $e;
                }
                $fields['size'] = 'auto';
                $response = $this->client->imageEdit($fields, ['image' => $input['path']]);
            }
        } finally {
            @unlink($input['path']);
        }

        $b64 = data_get($response, 'data.0.b64_json');
        $bytes = is_string($b64) ? base64_decode($b64, true) : false;

        if ($bytes === false || $bytes === '' || @getimagesizefromstring($bytes) === false) {
            throw new OpenAIException('invalid_response', 'No image in edit response', true);
        }

        $extension = $transparent ? 'png' : 'jpg';
        $path = preg_replace('/\.[a-z]+$/', '', $tempTarget).'.'.$extension;
        file_put_contents($path, $bytes);

        return ['path' => $path, 'extension' => $extension, 'usage' => Usage::from($model, $response['usage'] ?? null)];
    }

    /** Same aspect ratio as the photo, both sides multiples of 16, long side <= max_side. */
    public function size(int $width, int $height): string
    {
        if (config('services.openai.size_strategy') !== 'match') {
            return 'auto';
        }

        $max = (int) config('services.openai.max_side', 2048);
        $scale = min(1, $max / max($width, $height));
        $round = fn (float $v) => max(256, (int) (round($v / 16) * 16));

        return $round($width * $scale).'x'.$round($height * $scale);
    }
}
