<?php

namespace App\Support;

use App\Enums\AspectRatio;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\OutputFormat;
use App\Enums\Resolution;
use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use App\Models\CompanySetting;
use Illuminate\Validation\Rule;

/**
 * Typed view of the settings JSON stored on a batch (and, for re-optimize,
 * the per-image overrides). Single source of truth for keys, defaults and
 * validation rules.
 */
final class BatchSettings
{
    public function __construct(
        public OutputFormat $outputFormat = OutputFormat::Jpg,
        public Resolution $resolution = Resolution::Standard,
        public AspectRatio $aspectRatio = AspectRatio::Original,
        public OptimizationStrength $strength = OptimizationStrength::Normal,
        public BackgroundOption $background = BackgroundOption::Keep,
        public bool $removePeople = false,
        public WatermarkMode $watermarkMode = WatermarkMode::None,
        public WatermarkPosition $watermarkPosition = WatermarkPosition::BottomRight,
        public int $watermarkOpacity = 70,
    ) {}

    /** Validation rules for a settings payload (all keys optional, merged onto current values). */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'output_format' => ['sometimes', Rule::enum(OutputFormat::class)],
            $prefix.'resolution' => ['sometimes', Rule::enum(Resolution::class)],
            $prefix.'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
            $prefix.'strength' => ['sometimes', Rule::enum(OptimizationStrength::class)],
            $prefix.'background' => ['sometimes', Rule::enum(BackgroundOption::class)],
            $prefix.'remove_people' => ['sometimes', 'boolean'],
            $prefix.'watermark_mode' => ['sometimes', Rule::enum(WatermarkMode::class)],
            $prefix.'watermark_position' => ['sometimes', Rule::enum(WatermarkPosition::class)],
            $prefix.'watermark_opacity' => ['sometimes', 'integer', 'between:10,100'],
        ];
    }

    public static function fromCompanyDefaults(CompanySetting $s, bool $hasLogo): self
    {
        return new self(
            outputFormat: $s->default_output_format,
            resolution: $s->default_resolution,
            aspectRatio: $s->default_aspect_ratio,
            strength: $s->default_strength,
            background: $s->default_background,
            removePeople: false,
            // Without a logo a watermark is impossible, whatever the default says.
            watermarkMode: $hasLogo ? $s->default_watermark_mode : WatermarkMode::None,
            watermarkPosition: $s->default_watermark_position,
            watermarkOpacity: $s->default_watermark_opacity,
        );
    }

    public static function fromArray(?array $data): self
    {
        $d = new self;
        $data ??= [];

        return new self(
            outputFormat: OutputFormat::tryFrom((string) ($data['output_format'] ?? '')) ?? $d->outputFormat,
            resolution: Resolution::tryFrom((string) ($data['resolution'] ?? '')) ?? $d->resolution,
            aspectRatio: AspectRatio::tryFrom((string) ($data['aspect_ratio'] ?? '')) ?? $d->aspectRatio,
            strength: OptimizationStrength::tryFrom((string) ($data['strength'] ?? '')) ?? $d->strength,
            background: BackgroundOption::tryFrom((string) ($data['background'] ?? '')) ?? $d->background,
            removePeople: (bool) ($data['remove_people'] ?? $d->removePeople),
            watermarkMode: WatermarkMode::tryFrom((string) ($data['watermark_mode'] ?? '')) ?? $d->watermarkMode,
            watermarkPosition: WatermarkPosition::tryFrom((string) ($data['watermark_position'] ?? '')) ?? $d->watermarkPosition,
            watermarkOpacity: (int) ($data['watermark_opacity'] ?? $d->watermarkOpacity),
        );
    }

    /** Returns a copy with the given (validated) keys applied. */
    public function merge(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /** @return array<string, string|int|bool> */
    public function toArray(): array
    {
        return [
            'output_format' => $this->outputFormat->value,
            'resolution' => $this->resolution->value,
            'aspect_ratio' => $this->aspectRatio->value,
            'strength' => $this->strength->value,
            'background' => $this->background->value,
            'remove_people' => $this->removePeople,
            'watermark_mode' => $this->watermarkMode->value,
            'watermark_position' => $this->watermarkPosition->value,
            'watermark_opacity' => $this->watermarkOpacity,
        ];
    }
}
