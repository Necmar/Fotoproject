<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes audit entries to activity_logs. Sensitive keys are stripped before
 * storage, so callers can pass request payloads without leaking secrets.
 * Logging never breaks the calling flow.
 */
class ActivityLogger
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'remember_token',
        'api_key', 'secret', 'authorization', 'openai_api_key',
    ];

    /** @param array<string, mixed> $properties */
    public function log(
        ActivityAction $action,
        ?Model $subject = null,
        array $properties = [],
        ?User $user = null,
        Company|int|null $company = null,
    ): ?ActivityLog {
        try {
            $user ??= auth()->user();
            $companyId = $company instanceof Company ? $company->id : ($company ?? $user?->company_id);
            $request = app()->runningInConsole() ? null : request();

            return ActivityLog::query()->create([
                'company_id' => $companyId,
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'properties' => $this->sanitize($properties) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Activity log write failed', ['action' => $action->value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
