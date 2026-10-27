<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 403: the API key does not grant the scope the route requires. */
final class PermissionDeniedException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(403, $code, $message, $params, $requestId);
    }
}
