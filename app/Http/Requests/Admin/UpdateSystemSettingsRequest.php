<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $l = config('bora.limits');

        return [
            'retention_days' => ['sometimes', 'integer', "between:{$l['retention_days_min']},{$l['retention_days_max']}"],
            'registration_enabled' => ['sometimes', 'boolean'],
            'jpg_quality' => ['sometimes', 'integer', "between:{$l['jpg_quality_min']},{$l['jpg_quality_max']}"],
            'max_images_per_batch' => ['sometimes', 'integer', "between:1,{$l['max_images_per_batch']}"],
            'max_upload_mb' => ['sometimes', 'integer', "between:1,{$l['max_upload_mb_max']}"],
            'ai_enabled' => ['sometimes', 'boolean'],
            'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
