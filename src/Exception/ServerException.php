<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 5xx: an error of the API; retry later. */
final class ServerException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(int $status, string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct($status, $code, $message, $params, $requestId);
    }
}
