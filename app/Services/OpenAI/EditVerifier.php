<?php

namespace App\Services\OpenAI;

/**
 * Integrity check after a generative edit: a second vision call compares the
 * original and the edited photo. Any change to the product (colour, damage,
 * text, logos, plate, parts) rejects the edit; the photo then gets local
 * corrections instead. Enabled by default (OPENAI_VERIFY_EDITS).
 */
class EditVerifier
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly ImageInput $input,
    ) {}

    /** @return array{accepted: bool, result: array, usage: Usage} */
    public function verify(string $originalPath, string $editedPath, bool $peopleShouldBeGone): array
    {
        $model = (string) config('services.openai.analysis_model');

        $payload = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => 'You are a strict quality inspector. Compare image A (original) with image B (edited). Only judge the PRODUCT, not the background. Be conservative: when in doubt, report a change.']]],
                ['role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => 'Image A (original):'],
                    ['type' => 'input_image', 'image_url' => $this->input->dataUrl($originalPath), 'detail' => 'high'],
                    ['type' => 'input_text', 'text' => 'Image B (edited):'],
                    ['type' => 'input_image', 'image_url' => $this->input->dataUrl($editedPath), 'detail' => 'high'],
                    ['type' => 'input_text', 'text' => 'Did the edit change the product itself? Check shape, parts added/removed, colour, visible damage (scratches, dents, cracks, wear), text, logos, labels, displays and licence plates. Also report whether people who are not part of the product are still visible in B.'],
                ]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'edit_verification', 'strict' => true, 'schema' => self::schema()]],
        ];

        if ($effort = config('services.openai.analysis_reasoning')) {
            $payload['reasoning'] = ['effort' => $effort];
        }

        $response = $this->client->responses($payload);
        $result = $this->decode($response);

        $productChanged = $result['shape_or_parts_changed'] || $result['colour_changed'] || $result['damage_removed_or_changed']
            || $result['text_or_logos_changed'] || $result['license_plate_changed'] || $result['looks_artificial'];

        return [
            'accepted' => ! $productChanged,
            'result' => $result + ['people_remaining' => $peopleShouldBeGone && $result['people_still_visible']],
            'usage' => Usage::from($model, $response['usage'] ?? null),
        ];
    }

    public static function schema(): array
    {
        $keys = ['shape_or_parts_changed', 'colour_changed', 'damage_removed_or_changed', 'text_or_logos_changed', 'license_plate_changed', 'looks_artificial', 'people_still_visible'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [...$keys, 'notes'],
            'properties' => array_fill_keys($keys, ['type' => 'boolean']) + ['notes' => ['type' => 'string']],
        ];
    }

    private function decode(array $response): array
    {
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_array($data = json_decode((string) $content['text'], true))) {
                    $out = [];
                    foreach (array_keys(self::schema()['properties']) as $key) {
                        $out[$key] = $key === 'notes' ? mb_substr((string) ($data[$key] ?? ''), 0, 500) : (bool) ($data[$key] ?? true);
                    }

                    return $out;
                }
            }
        }

        throw new OpenAIException('invalid_response', 'No JSON in verification response', true);
    }
}
