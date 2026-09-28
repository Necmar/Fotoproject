<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Batch\UploadImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Batch;
use App\Models\Image;
use App\Services\Images\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageController extends Controller
{
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

    /**
     * Streams a stored file through Laravel after an ownership check. Files
     * live on a private disk and never have a public, predictable URL.
     */
    public function file(Image $image, string $variant): StreamedResponse
    {
        $this->authorize('view', $image);

        $path = match ($variant) {
            'original' => $image->original_path,
            'thumbnail' => $image->thumbnail_path,
            'optimized' => $image->optimized_path,
        };

        $disk = Storage::disk(config('bora.disk'));
        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
