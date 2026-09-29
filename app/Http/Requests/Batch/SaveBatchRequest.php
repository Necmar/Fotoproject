<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Support\BatchSettings;
use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) or update (PATCH) a draft batch: optional name + settings. */
class SaveBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $batch = $this->route('batch');

        return $batch instanceof Batch
            ? $this->user()->can('update', $batch)
            : $this->user()->can('create', Batch::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(
            ['name' => ['sometimes', 'nullable', 'string', 'max:120']],
            BatchSettings::rules(),
        );
    }
}
