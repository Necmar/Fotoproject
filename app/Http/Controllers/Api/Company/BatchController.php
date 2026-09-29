<?php

namespace App\Http\Controllers\Api\Company;

use App\Services\Processing\QueueKicker;

use App\Enums\BatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Batch\SaveBatchRequest;
use App\Http\Resources\BatchResource;
use App\Models\Batch;
use App\Services\BatchService;
use App\Services\Storage\BatchDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    /** Own batches, newest first. ?status=draft|active|finished, ?limit for the dashboard. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Batch::class);
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'active', 'finished'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $batches = Batch::query()
            ->forCompany($request->user()->company_id)
            ->with('cover')
            // Past the retention period = about to be cleaned up; never shown.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // Drafts without photos are abandoned "new batch" screens; don't list them.
            ->where(fn ($q) => $q->where('status', '!=', BatchStatus::Draft)->orWhere('images_count', '>', 0))
            ->when($request->input('status') === 'draft', fn ($q) => $q->where('status', BatchStatus::Draft))
            ->when($request->input('status') === 'active', fn ($q) => $q->active())
            ->when($request->input('status') === 'finished', fn ($q) => $q->whereIn('status', [
                BatchStatus::Completed, BatchStatus::CompletedWithErrors, BatchStatus::Failed,
            ]))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return BatchResource::collection($batches);
    }

    public function store(SaveBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $batch = $this->batches->createDraft($request->user(), $data['name'] ?? null, Arr::except($data, ['name']));

        return BatchResource::make($batch->load('images'))->response()->setStatusCode(201);
    }

    /** Polled by the React app every few seconds while the batch is processing. */
    public function show(Batch $batch): JsonResponse
    {
        $this->authorize('view', $batch);

        // Safety net: keeps the queue moving if the cron has stalled.
        if ($batch->status->isActive()) {
            app(QueueKicker::class)->kickIfStalled();
        }

        return BatchResource::make($batch->load('images'))->response();
    }

    public function update(SaveBatchRequest $request, Batch $batch): JsonResponse
    {
        $batch = $this->batches->update($batch, $request->validated());

        return BatchResource::make($batch->load('images'))->response();
    }

    public function start(Batch $batch): JsonResponse
    {
        $this->authorize('update', $batch);
        $batch = $this->batches->start($batch);
        // Start right away (after this response), not at the next cron minute.
        app(QueueKicker::class)->kick();

        return BatchResource::make($batch->load('images'))->response();
    }

    public function destroy(Batch $batch, BatchDeletionService $deleter): JsonResponse
    {
        $this->authorize('delete', $batch);
        $deleter->delete($batch);

        return response()->json(['message' => __('messages.batch.deleted')]);
    }
}
