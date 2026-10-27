<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** 402: the seller's plan does not include this. */
final class PaymentRequiredException extends ControlResellError
{
    /** @param array<string, string> $params */
    public function __construct(string $code, string $message, array $params, ?string $requestId)
    {
        parent::__construct(402, $code, $message, $params, $requestId);
    }
}
