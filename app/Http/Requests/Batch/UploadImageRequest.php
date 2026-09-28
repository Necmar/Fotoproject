<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Services\SystemSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One file per request. The real type check (magic bytes) happens in
 * ImageUploadService; here we only check that a file arrived and its size.
 */
class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $batch instanceof Batch && $this->user()->can('update', $batch);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = app(SystemSettings::class)->maxUploadMb() * 1024;

        return [
            'file' => ['required', 'file', "max:{$maxKb}"],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => __('messages.domain.file_too_large', ['max' => app(SystemSettings::class)->maxUploadMb()]),
            'file.uploaded' => __('messages.domain.upload_failed_server_limit'),
        ];
    }
}
