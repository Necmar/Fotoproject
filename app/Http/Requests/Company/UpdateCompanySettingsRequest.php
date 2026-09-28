<?php

namespace App\Http\Requests\Company;

use App\Enums\AspectRatio;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\OutputFormat;
use App\Enums\Resolution;
use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->user()?->company;

        return $company instanceof Company && $this->user()->can('update', $company);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'default_output_format' => ['required', Rule::enum(OutputFormat::class)],
            'default_resolution' => ['required', Rule::enum(Resolution::class)],
            'default_aspect_ratio' => ['required', Rule::enum(AspectRatio::class)],
            'default_strength' => ['required', Rule::enum(OptimizationStrength::class)],
            'default_background' => ['required', Rule::enum(BackgroundOption::class)],
            'default_watermark_mode' => ['required', Rule::enum(WatermarkMode::class)],
            'default_watermark_position' => ['required', Rule::enum(WatermarkPosition::class)],
            'default_watermark_opacity' => ['required', 'integer', 'between:10,100'],
            // Only lowercase letters, digits and hyphens; it becomes part of file names.
            'filename_prefix' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('filename_prefix')) {
            $this->merge(['filename_prefix' => Str::slug((string) $this->input('filename_prefix'))]);
        }
    }
}
