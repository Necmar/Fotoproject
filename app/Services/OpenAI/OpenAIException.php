<?php

namespace App\Services\OpenAI;

use RuntimeException;

/**
 * A failed OpenAI call. `retryable` tells the queue whether trying again
 * later makes sense (rate limit, timeout, server error) or not (refusal,
 * invalid request, wrong key).
 */
class OpenAIException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
