<?php

namespace App\Services\OpenAI;

use App\Support\BatchSettings;

/**
 * "Afbeelding analyseren": one vision call per photo that returns a strict
 * JSON description of the product, problems, people, visible text/plates and
 * suggested corrections. The result is stored on the image and reused, so a
 * photo is analysed once even when jobs are retried.
 */
class ImageAnalyzer
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly ImageInput $input,
    ) {}

    /** @return array{result: array, usage: Usage} */
    public function analyze(string $workingPath, BatchSettings $settings): array
    {
        $model = (string) config('services.openai.analysis_model');

        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $this->systemPrompt()]]],
                ['role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => $this->userPrompt($settings)],
                    ['type' => 'input_image', 'image_url' => $this->input->dataUrl($workingPath), 'detail' => 'high'],
                ]],
            ],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'product_photo_analysis',
                'strict' => true,
                'schema' => self::schema(),
            ]],
        ];

        if ($effort = config('services.openai.analysis_reasoning')) {
            $payload['reasoning'] = ['effort' => $effort];
        }

        $response = $this->client->responses($payload);

        return [
            'result' => $this->normalize($this->decode($response)),
            'usage' => Usage::from($model, $response['usage'] ?? null),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are a meticulous product-photo inspector for online ads and web shops (cars, bikes, furniture, electronics, anything).
        You judge ONE photo and describe what must be improved in presentation and image quality only.
        Never suggest changing the product itself: damage (scratches, dents, cracks, wear, damaged rims), colour, parts, logos,
        text, serial numbers, labels, displays and licence plates must stay exactly as they are.
        Answer only with the requested JSON.
        TXT;
    }

    private function userPrompt(BatchSettings $s): string
    {
        return "Analyse this product photo.\n"
            ."Chosen options (context only): background={$s->background->value}, remove_people=".($s->removePeople ? 'yes' : 'no')
            .", strength={$s->strength->value}.\n"
            ."- product_box: tight box around the complete product (all parts, mirrors, wheels, cables), as fractions 0..1 of width/height.\n"
            ."- corrections: global adjustments on a -1..1 scale (0 = leave as is); keep them modest. warmth>0 = warmer; tint>0 = more magenta.\n"
            ."  rotate_degrees: only for a clearly crooked horizon/vertical, otherwise 0.\n"
            ."- needs_generative_edit: true only if the photo needs more than global adjustments to look clean and professional\n"
            ."  (distracting clutter, reflections, strong noise, heavy shadows), or if the chosen background/people options require it.\n"
            ."- edit_instructions: short, concrete English instructions for an image editor, limited to presentation and image quality.\n"
            ."- restorable=false when the photo is too blurred, shaken, dark or low quality for a reliable improvement.\n"
            .'- people.overlaps_product=true when a person covers part of the product.';
    }

    /** JSON schema for Structured Outputs (all fields required, no extras). */
    public static function schema(): array
    {
        $bool = ['type' => 'boolean'];
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['product', 'product_box', 'issues', 'severity', 'restorable', 'people', 'visible', 'corrections', 'needs_generative_edit', 'edit_instructions'],
            'properties' => [
                'product' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['description', 'category'],
                    'properties' => ['description' => ['type' => 'string'], 'category' => ['type' => 'string']],
                ],
                'product_box' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['x', 'y', 'w', 'h'],
                    'properties' => ['x' => $num, 'y' => $num, 'w' => $num, 'h' => $num],
                ],
                'issues' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['too_dark', 'too_bright', 'blurry', 'motion_blur', 'noisy', 'poor_white_balance', 'low_contrast', 'low_quality', 'crooked', 'distracting_background', 'harsh_shadows'],
                    'properties' => array_fill_keys(['too_dark', 'too_bright', 'blurry', 'motion_blur', 'noisy', 'poor_white_balance', 'low_contrast', 'low_quality', 'crooked', 'distracting_background', 'harsh_shadows'], $bool),
                ],
                'severity' => ['type' => 'string', 'enum' => ['none', 'minor', 'major']],
                'restorable' => $bool,
                'people' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['present', 'count', 'overlaps_product'],
                    'properties' => ['present' => $bool, 'count' => ['type' => 'integer'], 'overlaps_product' => $bool],
                ],
                'visible' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['license_plate', 'text_or_logos', 'damage', 'damage_description'],
                    'properties' => ['license_plate' => $bool, 'text_or_logos' => $bool, 'damage' => $bool, 'damage_description' => ['type' => 'string']],
                ],
                'corrections' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['exposure', 'contrast', 'warmth', 'tint', 'sharpen', 'denoise', 'rotate_degrees'],
                    'properties' => array_fill_keys(['exposure', 'contrast', 'warmth', 'tint', 'sharpen', 'denoise', 'rotate_degrees'], $num),
                ],
                'needs_generative_edit' => $bool,
                'edit_instructions' => ['type' => 'string'],
            ],
        ];
    }

    private function decode(array $response): array
    {
        // Responses API: output[] -> message -> content[] -> output_text; `output_text` is a convenience field in SDKs only.
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new OpenAIException('refused', 'Analysis refused', false);
                }

                if (($content['type'] ?? null) === 'output_text') {
                    $data = json_decode((string) $content['text'], true);

                    if (is_array($data)) {
                        return $data;
                    }
                }
            }
        }

        throw new OpenAIException('invalid_response', 'No JSON in analysis response', true);
    }

    /** Clamp everything to safe ranges; the model output is never trusted blindly. */
    private function normalize(array $d): array
    {
        $clamp = fn ($v, $min, $max) => max($min, min($max, (float) $v));

        $box = $d['product_box'] ?? [];
        $x = $clamp($box['x'] ?? 0, 0, 1);
        $y = $clamp($box['y'] ?? 0, 0, 1);
        $w = $clamp($box['w'] ?? 1, 0.01, 1 - $x);
        $h = $clamp($box['h'] ?? 1, 0.01, 1 - $y);

        $c = $d['corrections'] ?? [];

        return [
            'product' => [
                'description' => mb_substr((string) data_get($d, 'product.description', ''), 0, 300),
                'category' => mb_substr((string) data_get($d, 'product.category', ''), 0, 60),
            ],
            'product_box' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h],
            'issues' => array_map('boolval', (array) ($d['issues'] ?? [])),
            'severity' => in_array($d['severity'] ?? '', ['none', 'minor', 'major'], true) ? $d['severity'] : 'minor',
            'restorable' => (bool) ($d['restorable'] ?? true),
            'people' => [
                'present' => (bool) data_get($d, 'people.present', false),
                'count' => max(0, (int) data_get($d, 'people.count', 0)),
                'overlaps_product' => (bool) data_get($d, 'people.overlaps_product', false),
            ],
            'visible' => [
                'license_plate' => (bool) data_get($d, 'visible.license_plate', false),
                'text_or_logos' => (bool) data_get($d, 'visible.text_or_logos', false),
                'damage' => (bool) data_get($d, 'visible.damage', false),
                'damage_description' => mb_substr((string) data_get($d, 'visible.damage_description', ''), 0, 300),
            ],
            'corrections' => [
                'exposure' => $clamp($c['exposure'] ?? 0, -1, 1),
                'contrast' => $clamp($c['contrast'] ?? 0, -1, 1),
                'warmth' => $clamp($c['warmth'] ?? 0, -1, 1),
                'tint' => $clamp($c['tint'] ?? 0, -1, 1),
                'sharpen' => $clamp($c['sharpen'] ?? 0, 0, 1),
                'denoise' => $clamp($c['denoise'] ?? 0, 0, 1),
                'rotate_degrees' => $clamp($c['rotate_degrees'] ?? 0, -5, 5),
            ],
            'needs_generative_edit' => (bool) ($d['needs_generative_edit'] ?? false),
            'edit_instructions' => mb_substr((string) ($d['edit_instructions'] ?? ''), 0, 1200),
        ];
    }
}
