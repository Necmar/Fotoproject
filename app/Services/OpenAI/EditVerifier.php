<?php

namespace App\Services\OpenAI;

/**
 * Integrity check after a generative edit: a second vision call compares the
 * original and the edited photo. A real change to the product (other shape or
 * parts, other paint/material colour, damage hidden, text/logos/plate altered,
 * product looking fake) rejects the edit; the photo then gets the analysis
 * corrections instead. Better light, white balance, contrast, sharpness, less
 * noise and a cleaner background are the purpose of the edit and never count.
 * Enabled by default (OPENAI_VERIFY_EDITS).
 */
class EditVerifier
{
    public const CHECKS = ['product_identity_changed', 'product_colour_changed', 'damage_hidden_or_changed', 'text_or_logos_altered', 'license_plate_altered', 'product_looks_fake', 'people_still_visible'];

    private const SYSTEM = <<<'TXT'
    You check a product photo edit for an online advert. Image A is the original, image B the edited version.
    The edit was ASKED to improve presentation: exposure, brightness, contrast, white balance, colour cast, shadows,
    highlights, sharpness, noise, straightening and (depending on the options) the background or people around the product.
    Those improvements are the goal and are NEVER a problem, even when they make the product look lighter, cleaner or
    differently lit. A car that looks less blue-tinted after white balance correction has NOT changed colour.
    Small re-rendering differences in fine texture are fine, unless they alter a detail that matters to a buyer.
    Cleaning is also asked for: removed dust, lint, hairs, fingerprints, smudges, water spots and stray specks are NOT
    hidden damage. More clarity, crisper edges, deeper blacks and cleaner colours are NOT a changed product.

    Only report a real change to the PRODUCT itself, the kind a buyer would call misleading:
    - product_identity_changed: other shape or model, parts added, removed or reshaped (mirrors, wheels, handles, buttons...).
    - product_colour_changed: the actual paint or material colour is different (e.g. silver became white, red became orange),
      beyond what better lighting or white balance explains.
    - damage_hidden_or_changed: a visible scratch, dent, crack, stain, wear or damaged part in A is gone, smaller or different in B.
    - text_or_logos_altered: letters, digits, logos, labels or displays ON THE PRODUCT that are readable in A are changed,
      garbled, added or removed in B. Tiny or blurry text that cannot be read in A does not count.
    - license_plate_altered: the licence plate characters differ or became unreadable.
    - product_looks_fake: the product looks clearly artificial (CGI, plastic, painted) instead of a real photo.
    - people_still_visible: people who are not part of the product are still visible in B.
    Judge only what you can actually see; do not guess. Background changes never count as product changes.
    TXT;

    private const QUESTION = 'Answer each check. In notes, describe briefly (English) what differs. '
        .'In reason_nl and reason_en give ONE short sentence (Dutch and English) naming the most important product change '
        .'if any check (except people_still_visible) is true, for example "de tekst op het label is veranderd"; otherwise an empty string.';

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
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => self::SYSTEM]]],
                ['role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => 'Image A (original):'],
                    ['type' => 'input_image', 'image_url' => $this->input->dataUrl($originalPath), 'detail' => 'high'],
                    ['type' => 'input_text', 'text' => 'Image B (edited):'],
                    ['type' => 'input_image', 'image_url' => $this->input->dataUrl($editedPath), 'detail' => 'high'],
                    ['type' => 'input_text', 'text' => self::QUESTION],
                ]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'edit_verification', 'strict' => true, 'schema' => self::schema()]],
        ];

        if ($effort = config('services.openai.analysis_reasoning')) {
            $payload['reasoning'] = ['effort' => $effort];
        }

        $response = $this->client->responses($payload);
        $result = $this->decode($response);

        $productChanged = $result['product_identity_changed'] || $result['product_colour_changed'] || $result['damage_hidden_or_changed']
            || $result['text_or_logos_altered'] || $result['license_plate_altered'] || $result['product_looks_fake'];

        return [
            'accepted' => ! $productChanged,
            'result' => $result + ['people_remaining' => $peopleShouldBeGone && $result['people_still_visible']],
            'usage' => Usage::from($model, $response['usage'] ?? null),
        ];
    }

    public static function schema(): array
    {
        $keys = self::CHECKS;

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [...$keys, 'notes', 'reason_nl', 'reason_en'],
            'properties' => array_fill_keys($keys, ['type' => 'boolean']) + [
                'notes' => ['type' => 'string'],
                'reason_nl' => ['type' => 'string'],
                'reason_en' => ['type' => 'string'],
            ],
        ];
    }

    private function decode(array $response): array
    {
        foreach ($response['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_array($data = json_decode((string) $content['text'], true))) {
                    $out = [];
                    foreach (array_keys(self::schema()['properties']) as $key) {
                        $out[$key] = in_array($key, self::CHECKS, true) ? (bool) ($data[$key] ?? true) : mb_substr((string) ($data[$key] ?? ''), 0, 300);
                    }

                    return $out;
                }
            }
        }

        throw new OpenAIException('invalid_response', 'No JSON in verification response', true);
    }
}
