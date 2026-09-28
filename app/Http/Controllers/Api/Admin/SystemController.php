<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Http\Resources\Admin\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\ImageProcessingRecord;
use App\Services\ActivityLogger;
use App\Services\CompanyStatsService;
use App\Services\Processing\QueueHealth;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SystemController extends Controller
{
    public function __construct(
        private readonly SystemSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function dashboard(CompanyStatsService $stats, QueueHealth $queue): JsonResponse
    {
        $recentFailures = ImageProcessingRecord::query()
            ->with('company:id,name')
            ->where('status', ImageProcessingRecord::STATUS_FAILED)
            // Attempts that will be retried are not failures (yet).
            ->where(fn ($q) => $q->whereNull('error_code')->orWhere('error_code', '!=', 'retrying'))
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (ImageProcessingRecord $r) => [
                'id' => $r->id,
                'company' => $r->company?->only(['id', 'name']),
                'type' => $r->type->value,
                'model' => $r->model,
                'error_code' => $r->error_code,
                'error_message' => $r->error_message,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'totals' => $stats->platform(),
                'queue' => $queue->snapshot(),
                'recent_failures' => $recentFailures,
            ],
        ]);
    }

    public function showSettings(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->all(),
            'meta' => ['limits' => config('bora.limits')],
        ]);
    }

    public function updateSettings(UpdateSystemSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->settings->update($data);
        $this->activity->log(ActivityAction::AdminSettingsUpdated, properties: $data);

        return $this->showSettings();
    }

    public function activity(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'company_id' => ['nullable', 'integer'],
            'action' => ['nullable', Rule::enum(ActivityAction::class)],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);

        $logs = ActivityLog::query()
            ->with(['company:id,name', 'user:id,name,email'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ActivityLogResource::collection($logs);
    }
}
