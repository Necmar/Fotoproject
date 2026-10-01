<?php

namespace App\Http\Controllers\Api\Company;

use App\Services\Processing\QueueKicker;

use App\Http\Controllers\Concerns\ServesStoredFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Batch\ReoptimizeImageRequest;
use App\Http\Requests\Batch\UploadImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Batch;
use App\Models\Image;
use App\Services\Images\ImageUploadService;
use App\Services\Processing\ReoptimizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ImageController extends Controller
{
    use ServesStoredFiles;

    public function __construct(private readonly ImageUploadService $uploads) {}

    public function store(UploadImageRequest $request, Batch $batch): JsonResponse
    {
        $image = $this->uploads->store($batch, $request->file('file'));

        return ImageResource::make($image)->response()->setStatusCode(201);
    }

    public function destroy(Batch $batch, Image $image): JsonResponse
    {
        $this->authorize('delete', $image);
        $this->uploads->delete($batch, $image);

        return response()->json(['message' => __('messages.batch.image_removed')]);
    }

    public function reoptimize(ReoptimizeImageRequest $request, Image $image, ReoptimizeService $service): JsonResponse
    {
        $image = $service->reoptimize($image, $request->validated());
        app(QueueKicker::class)->kick();

        return ImageResource::make($image->load('batch'))->response()->setStatusCode(202);
    }

    /**
     * Streams a stored file through Laravel after an ownership check. Files
     * live on a private disk and never have a public, predictable URL.
     */
    public function file(Request $request, Image $image, string $variant): Response
    {
        $this->authorize('view', $image);

        $path = match ($variant) {
            'original' => $image->original_path,
            // Oriented, metadata-free copy of the original: used for before/after (works for HEIC too).
            'working' => $image->working_path ?? $image->original_path,
            'thumbnail' => $image->thumbnail_path,
            'optimized' => $image->optimized_path,
            'preview' => $image->preview_path ?? $image->optimized_path,
        };

        $disk = Storage::disk(config('bora.disk'));
        abort_if($path === null || ! $disk->exists($path), 404);

        // Original, working copy and thumbnail never change for an image. Results do,
        // but their URLs carry a version (?v=) that changes with every new result.
        $immutable = in_array($variant, ['original', 'working', 'thumbnail'], true) || $request->filled('v');

        return $this->cachedFile($request, $disk, $path, $immutable ? 'private, max-age=604800, immutable' : 'private, max-age=60', [
            'Content-Disposition' => 'inline',
        ]);
    }
}
