<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 429: the rate limit of the API key is reached. */
final class RateLimitException extends ControlResellError
{
    /**
     * @param array<string, string> $params
     * @param int|null $retryAfterSeconds The `Retry-After` the API answered, when it did.
     */
    public function __construct(string $code, string $message, array $params, ?string $requestId, public readonly ?int $retryAfterSeconds)
    {
        parent::__construct(429, $code, $message, $params, $requestId);
    }
}
