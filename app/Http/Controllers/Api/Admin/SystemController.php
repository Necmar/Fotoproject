<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Http\Resources\Admin\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\ImageProcessingRecord;
use App\Services\ActivityLogger;
use App\Services\CompanyStatsService;
use App\Services\OpenAI\OpenAIClient;
use App\Services\Processing\QueueHealth;
use App\Services\OpenAI\ConnectionTester;
use App\Services\System\HealthCheck;
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

    /** Installation check for the Super Admin (hosting without SSH). */
    public function health(HealthCheck $health): JsonResponse
    {
        $checks = $health->run();

        return response()->json(['data' => ['status' => $health->worstStatus($checks), 'checks' => $checks]]);
    }

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
            'meta' => [
                'limits' => config('bora.limits'),
                'default_retouch_prompt' => \App\Services\OpenAI\EditInstructionBuilder::defaultRetouchPrompt(),
                // Read-only; configured in .env. Never includes the key itself.
                'openai' => [
                    'configured' => app(OpenAIClient::class)->isConfigured(),
                    'analysis_model' => config('services.openai.analysis_model'),
                    'image_model' => config('services.openai.image_model'),
                    'edit_policy' => config('services.openai.edit_policy'),
                    'verify_edits' => (bool) config('services.openai.verify_edits'),
                    'last_error' => app(ConnectionTester::class)->lastError(),
                ],
            ],
        ]);
    }

    /** "Verbinding testen" in Systeem > OpenAI. The edit test costs about one cent. */
    public function testOpenAI(Request $request, ConnectionTester $tester): JsonResponse
    {
        $request->validate(['edit' => ['sometimes', 'boolean']]);
        @set_time_limit(360);

        return response()->json(['data' => $tester->run($request->boolean('edit'))]);
    }

    public function updateSettings(UpdateSystemSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $oldRetention = $this->settings->retentionDays();
        $this->settings->update($data);

        // Keep the "available until" dates shown to users in line with the new retention period.
        if (isset($data['retention_days']) && (int) $data['retention_days'] !== $oldRetention) {
            Batch::query()->select(['id', 'created_at', 'started_at'])->chunkById(200, function ($batches) use ($data) {
                foreach ($batches as $batch) {
                    Batch::query()->whereKey($batch->id)->update(['expires_at' => ($batch->started_at ?? $batch->created_at)->copy()->addDays((int) $data['retention_days'])]);
                }
            });
        }
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
