<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 400: the request is malformed. */
final class BadRequestException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(400, $code, $message, $params, $requestId);
    }
}
