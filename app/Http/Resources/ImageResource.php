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
            'height' => $this->height,
            'status' => $this->status->value,
            'warnings' => $this->warnings ?? [],
            'error' => $this->error_message,
            'duplicate_of' => $this->duplicate_of_id,
            'output_filename' => $this->output_filename,
            'apply_watermark' => $this->apply_watermark,
            'urls' => [
                'original' => $originalUrl,
                // Real thumbnails are generated in phase 3; until then the original is used when the browser can show it.
                'thumbnail' => $this->thumbnail_path
                    ? route('api.company.images.file', [$this->id, 'thumbnail'])
                    : ($browserCanShowOriginal ? $originalUrl : null),
                'optimized' => $this->optimized_path ? route('api.company.images.file', [$this->id, 'optimized']) : null,
            ],
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
