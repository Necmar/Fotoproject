<?php

namespace App\Services\OpenAI;

/** Token usage of one OpenAI call plus an estimated cost (USD) from config pricing. */
final class Usage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $inputImageTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /** Works for both the Responses API and the Images API usage objects. */
    public static function from(string $model, ?array $usage): self
    {
        $usage ??= [];
        $imageTokens = (int) data_get($usage, 'input_tokens_details.image_tokens', 0);

        return new self(
            $model,
            (int) ($usage['input_tokens'] ?? 0),
            $imageTokens,
            (int) ($usage['output_tokens'] ?? 0),
        );
    }

    public function costUsd(): ?float
    {
        $price = $this->priceFor($this->model);

        if ($price === null) {
            return null;
        }

        $textTokens = max(0, $this->inputTokens - $this->inputImageTokens);

        return round(
            ($textTokens * $price['input_text'] + $this->inputImageTokens * $price['input_image'] + $this->outputTokens * $price['output']) / 1_000_000,
            5,
        );
    }

    /** Exact model or dated snapshot (gpt-image-2-2026-04-21 -> gpt-image-2). */
    private function priceFor(string $model): ?array
    {
        $pricing = (array) config('services.openai.pricing', []);

        if (isset($pricing[$model])) {
            return $pricing[$model];
        }

        foreach ($pricing as $name => $price) {
            if (str_starts_with($model, $name.'-')) {
                return $price;
            }
        }

        return null;
    }
}
