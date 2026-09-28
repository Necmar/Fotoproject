<?php

namespace App\Http\Resources\Admin;

use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Batch */
class BatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'images_count' => $this->images_count,
            'completed_count' => $this->completed_count,
            'failed_count' => $this->failed_count,
            'storage_bytes' => $this->storage_bytes,
            'settings' => $this->settings,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
