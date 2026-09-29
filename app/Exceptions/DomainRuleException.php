<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business rule rejected the request (e.g. an upload). Rendered as a 422
 * with a stable machine-readable code and a translated message.
 */
class DomainRuleException extends RuntimeException
{
    /** @param array<string, scalar> $replace */
    public function __construct(
        public readonly string $errorCode,
        array $replace = [],
        public readonly int $status = 422,
    ) {
        parent::__construct(__('messages.domain.'.$errorCode, $replace));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
