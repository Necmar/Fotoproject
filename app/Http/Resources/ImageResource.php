<?php

namespace App\Http\Resources;

use App\Models\Image;
use App\Services\Images\ImageTypeDetector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Image */
class ImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $browserCanShowOriginal = ! in_array($this->original_mime, [ImageTypeDetector::HEIC, ImageTypeDetector::HEIF], true);
        $originalUrl = $this->original_path ? route('api.company.images.file', [$this->id, 'original']) : null;

        return [
            'id' => $this->id,
            'position' => $this->position,
            'original_filename' => $this->original_filename,
            'original_size' => $this->original_size,
            'original_mime' => $this->original_mime,
            'width' => $this->width,
            'output_width' => $this->output_width,
            'output_height' => $this->output_height,
            'output_size' => $this->output_size,
            'height' => $this->height,
            'status' => $this->status->value,
            'ai_status' => $this->ai_status,
            'warnings' => array_map(fn (array $w) => [
                'code' => $w['code'],
                'source' => $w['source'] ?? null,
                'params' => $w['params'] ?? [],
                'message' => __('messages.warnings.'.$w['code'], $w['params'] ?? []),
            ], $this->warnings ?? []),
            'error' => $this->error_message,
            'duplicate_of' => $this->duplicate_of_id,
            'output_filename' => $this->output_filename,
            'apply_watermark' => $this->apply_watermark,
            'settings' => $this->relationLoaded('batch') ? $this->effectiveSettings() : null,
            'reoptimized' => $this->settings_override !== null,
            // null = no limit configured.
            'reoptimize_left' => ($max = (int) config('bora.limits.reoptimize_per_image')) > 0 ? max(0, $max - (int) $this->reoptimize_count) : null,
            'urls' => [
                'original' => $originalUrl,
                // Thumbnails are made at upload; the original is only a fallback for older records.
                'thumbnail' => $this->thumbnail_path
                    ? route('api.company.images.file', [$this->id, 'thumbnail'])
                    : ($browserCanShowOriginal ? $originalUrl : null),
                'before' => $this->working_path ? route('api.company.images.file', [$this->id, 'working']) : ($browserCanShowOriginal ? $originalUrl : null),
                'download' => $this->optimized_path ? route('api.company.images.download', $this->id) : null,
                'optimized' => $this->optimized_path ? route('api.company.images.file', [$this->id, 'optimized']) : null,
            ],
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
