<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BatchResource;
use App\Http\Resources\ImageResource;
use App\Models\Batch;
use App\Models\Image;
use App\Services\DownloadService;
use App\Support\BatchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Step 5 "Downloaden": watermark choices (changeable at any time, they only
 * affect downloads) and the actual file downloads.
 */
class DownloadController extends Controller
{
    public function __construct(private readonly DownloadService $downloads) {}

    public function image(Image $image): BinaryFileResponse
    {
        $this->authorize('download', $image);
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $file = $this->downloads->single($image);

        return $this->send($file['path'], $file['name'], $file['temporary']);
    }

    public function batch(Batch $batch): BinaryFileResponse
    {
        $this->authorize('download', $batch);
        // A ZIP with watermarks renders every photo: more than the default 30-60 s.
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $file = $this->downloads->zip($batch);

        return $this->send($file['path'], $file['name'], false);
    }

    public function watermark(Request $request, Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch);
        $data = $request->validate([
            'watermark_mode' => ['required', Rule::enum(WatermarkMode::class)],
            'watermark_position' => ['required', Rule::enum(WatermarkPosition::class)],
            'watermark_opacity' => ['required', 'integer', 'between:10,100'],
        ]);

        if ($data['watermark_mode'] !== WatermarkMode::None->value && ! $batch->company->logo_path) {
            throw new DomainRuleException('watermark_needs_logo');
        }

        $batch->update(['settings' => BatchSettings::fromArray($batch->settings)->merge($data)->toArray()]);

        return BatchResource::make($batch->load('images'))->response();
    }

    /** For "logo alleen op geselecteerde foto's". */
    public function toggle(Request $request, Image $image): JsonResponse
    {
        $this->authorize('update', $image);
        $data = $request->validate(['apply' => ['required', 'boolean']]);
        $image->forceFill(['apply_watermark' => $data['apply']])->save();

        return ImageResource::make($image)->response();
    }

    private function send(string $path, string $name, bool $temporary): BinaryFileResponse
    {
        return response()
            ->download($path, $name, [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend($temporary);
    }
}
