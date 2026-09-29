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
    public function edit(string $workingPath, string $prompt, bool $transparent, string $tempTarget, ?string $quality = null, ?int $maxSide = null, bool $lossless = false): array
    {
        $model = (string) config('services.openai.image_model');
        $input = $this->input->editFile($workingPath, $maxSide ?? (int) config('services.openai.max_side'));

        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            // Set by the Super Admin (Systeem > Verwerking): medium = faster, high = finest detail.
            'quality' => $quality ?? (string) app(\App\Services\SystemSettings::class)->get('ai_image_quality', config('services.openai.image_quality', 'medium')),
            // PNG for cut-outs: no compression artefacts along the product edge.
            'output_format' => $transparent || $lossless ? 'png' : 'jpeg',
        ];

        if (! $transparent && ! $lossless) {
            $fields['output_compression'] = 95;
        }
        // A key-colour cut-out must stay opaque: a transparent answer loses the key colour.
        $fields['background'] = $transparent ? 'transparent' : 'opaque';

        // Stay as close as possible to the source photo (product integrity). Dropped
        // automatically below for a model that does not accept the parameter.
        if (str_starts_with($model, 'gpt-image') && config('services.openai.input_fidelity')) {
            $fields['input_fidelity'] = (string) config('services.openai.input_fidelity');
        }

        try {
            $fields['size'] = $this->size($input['width'], $input['height']);

            // Not every model accepts every option: drop a refused one (custom size -> auto,
            // input_fidelity -> left out) and try again, at most three times.
            for ($try = 0; ; $try++) {
                try {
                    $response = $this->client->imageEdit($fields, ['image' => $input['path']]);
                    break;
                } catch (OpenAIException $e) {
                    $message = strtolower($e->getMessage());
                    if ($e->reason !== 'invalid_request' || $try >= 3) {
                        throw $e;
                    }
                    if (isset($fields['input_fidelity']) && str_contains($message, 'input_fidelity')) {
                        unset($fields['input_fidelity']);
                    } elseif (isset($fields['background']) && str_contains($message, 'background')) {
                        unset($fields['background']);
                    } elseif ($fields['size'] !== 'auto' && str_contains($message, 'size')) {
                        $fields['size'] = 'auto';
                    } else {
                        throw $e;
                    }
                }
            }
        } finally {
            @unlink($input['path']);
        }

        $b64 = data_get($response, 'data.0.b64_json');
        $bytes = is_string($b64) ? base64_decode($b64, true) : false;

        if ($bytes === false || $bytes === '' || @getimagesizefromstring($bytes) === false) {
            throw new OpenAIException('invalid_response', 'No image in edit response', true);
        }

        $extension = $transparent || $lossless ? 'png' : 'jpg';
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
