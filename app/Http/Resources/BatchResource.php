<?php

namespace App\Http\Resources;

use App\Enums\ImageStatus;
use App\Models\Batch;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Company-side batch, including images and progress (used for polling). @mixin Batch */
class BatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $images = $this->whenLoaded('images');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'filename_base' => $this->filename_base,
            'status' => $this->status->value,
            'settings' => $this->settings,
            'images_count' => $this->images_count,
            'progress' => $this->relationLoaded('images') ? $this->progress() : null,
            'cover_url' => $this->whenLoaded('cover', fn () => $this->cover ? ImageResource::make($this->cover)->toArray($request)['urls']['thumbnail'] : null),
            'images' => ImageResource::collection($images),
            'storage_bytes' => $this->storage_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int|null> */
    private function progress(): array
    {
        $total = $this->images->count();
        $completed = $this->images->where('status', ImageStatus::Completed)->count();
        $failed = $this->images->where('status', ImageStatus::Failed)->count();
        $current = $this->images->first(fn (Image $i) => $i->status->isBusy());

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'processed' => $completed + $failed,
            'percent' => $total > 0 ? (int) floor(($completed + $failed) / $total * 100) : 0,
            'current_position' => $current?->position,
        ];
    }
}
