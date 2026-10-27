<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 401: no credential, or an unknown, revoked or expired one. */
final class AuthenticationException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(401, $code, $message, $params, $requestId);
    }
}
