<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Shared helpers for backed enums: value lists for validation and
 * translation-aware option lists for the frontend.
 */
trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Translation key used by both Laravel and the React app. */
    public function translationKey(): string
    {
        return 'enums.'.self::translationGroup().'.'.$this->value;
    }

    public function label(): string
    {
        return __($this->translationKey());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    protected static function translationGroup(): string
    {
        return Str::snake(class_basename(self::class));
    }
}
