<?php

namespace App\Http\Resources\Admin;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->owner;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'has_logo' => $this->logo_path !== null,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'locale' => $owner->locale,
                'email_verified' => $owner->email_verified_at !== null,
                'last_login_at' => $owner->last_login_at?->toIso8601String(),
            ] : null,
            'stats' => [
                'batches_current' => $this->whenCounted('batches'),
                'batches_active' => $this->when(isset($this->active_batches_count), fn () => (int) $this->active_batches_count),
                'batches_total' => $this->batches_total,
                'images_processed_total' => $this->images_processed_total,
                'images_failed_total' => $this->images_failed_total,
                'images_failed_current' => $this->when(isset($this->failed_images_count), fn () => (int) $this->failed_images_count),
                'storage_bytes' => $this->storage_bytes,
                'ai_cost_usd' => $this->when(array_key_exists('ai_cost_usd', $this->getAttributes()), fn () => round((float) $this->ai_cost_usd, 4)),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
