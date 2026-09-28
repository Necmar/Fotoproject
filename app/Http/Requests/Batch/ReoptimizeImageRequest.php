<?php

namespace App\Http\Requests\Batch;

use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Models\Image;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Opnieuw optimaliseren": strength, background and people can be chosen again per photo. */
class ReoptimizeImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $image = $this->route('image');

        return $image instanceof Image && $this->user()->can('update', $image);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'strength' => ['required', Rule::enum(OptimizationStrength::class)],
            'background' => ['required', Rule::enum(BackgroundOption::class)],
            'remove_people' => ['required', 'boolean'],
        ];
    }
}
