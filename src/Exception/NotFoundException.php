<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 404: no such resource for this seller. */
final class NotFoundException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(404, $code, $message, $params, $requestId);
    }
}
