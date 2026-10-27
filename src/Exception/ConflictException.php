<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 409: an Idempotency-Key used with another request, or still in flight. */
final class ConflictException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(409, $code, $message, $params, $requestId);
    }
}
