<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivityAction;
use App\Enums\BatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\BatchResource;
use App\Models\Batch;
use App\Services\ActivityLogger;
use App\Services\Storage\BatchDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Super Admin view on all batches of all companies. */
class BatchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', Rule::enum(BatchStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $batches = Batch::query()
            ->with('company:id,name')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return BatchResource::collection($batches);
    }

    public function destroy(Batch $batch, BatchDeletionService $deleter, ActivityLogger $activity): JsonResponse
    {
        $this->authorize('delete', $batch);

        $deleter->delete($batch, logActivity: false);
        $activity->log(ActivityAction::AdminStorageDeleted, $batch, ['batch' => $batch->id], company: $batch->company_id);

        return response()->json(['message' => __('messages.admin.batch_deleted')]);
    }
}
