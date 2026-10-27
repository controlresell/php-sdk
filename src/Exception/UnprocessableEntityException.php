<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 422: the request is well formed but refused. */
final class UnprocessableEntityException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(422, $code, $message, $params, $requestId);
    }
}
