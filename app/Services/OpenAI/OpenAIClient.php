<?php

namespace App\Services\OpenAI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Low-level HTTP access to the OpenAI API with error handling and retry.
 *
 * - The API key is only read from config (.env) and never logged.
 * - Request bodies (which contain images) are never logged; only model,
 *   status and OpenAI's error type/message.
 * - 429, 5xx and timeouts are retried a few times with exponential backoff.
 */
class OpenAIClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * POST /responses (JSON).
     *
     * @return array<string, mixed>
     */
    public function responses(array $payload): array
    {
        return $this->send('responses', fn (PendingRequest $http) => $http
            ->timeout((int) config('services.openai.timeouts.analysis'))
            ->post('responses', $payload), $payload['model'] ?? null);
    }

    /**
     * POST /images/edits (multipart).
     *
     * @param  array<string, scalar>  $fields
     * @param  array<string, string>  $files  field name => local path
     * @return array<string, mixed>
     */
    public function imageEdit(array $fields, array $files): array
    {
        return $this->send('images/edits', function (PendingRequest $http) use ($fields, $files) {
            $http = $http->timeout((int) config('services.openai.timeouts.edit'))->asMultipart();

            foreach ($files as $name => $path) {
                $http = $http->attach($name, fopen($path, 'rb'), basename($path));
            }

            return $http->post('images/edits', $fields);
        }, $fields['model'] ?? null);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(string $endpoint, callable $call, ?string $model): array
    {
        if (! $this->isConfigured()) {
            throw new OpenAIException('not_configured', 'OpenAI API key missing', false);
        }

        $delays = (array) config('services.openai.retry_delays', [2, 6]);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $call($this->http());

                if ($response->successful()) {
                    return $response->json() ?? [];
                }

                $error = $this->errorFrom($response);
            } catch (ConnectionException $e) {
                $error = new OpenAIException('timeout', 'Connection problem or timeout', true);
            }

            Log::warning('OpenAI request failed', [
                'endpoint' => $endpoint,
                'model' => $model,
                'attempt' => $attempt,
                'reason' => $error->reason,
                'status' => $error->httpStatus,
                'message' => mb_substr($error->getMessage(), 0, 300),
            ]);

            if (! $error->retryable || $attempt > count($delays)) {
                throw $error;
            }

            Sleep::for((int) $delays[$attempt - 1])->seconds();
        }
    }

    private function http(): PendingRequest
    {
        $http = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/').'/')
            ->withToken((string) config('services.openai.key'))
            ->acceptJson()
            ->connectTimeout(15);

        if ($org = config('services.openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => $org]);
        }

        return $http;
    }

    private function errorFrom(Response $response): OpenAIException
    {
        $status = $response->status();
        $body = $response->json('error') ?? [];
        $message = (string) ($body['message'] ?? 'HTTP '.$status);
        $code = (string) ($body['code'] ?? $body['type'] ?? '');

        return match (true) {
            $status === 429 && $code === 'insufficient_quota' => new OpenAIException('quota', $message, false, $status),
            $status === 429 => new OpenAIException('rate_limited', $message, true, $status),
            $status >= 500 => new OpenAIException('server_error', $message, true, $status),
            $status === 401 || $status === 403 => new OpenAIException('auth', $message, false, $status),
            str_contains($code, 'moderation') || str_contains($code, 'safety') || str_contains($code, 'content_policy') => new OpenAIException('refused', $message, false, $status),
            default => new OpenAIException('invalid_request', $message, false, $status),
        };
    }
}
