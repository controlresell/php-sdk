<?php

declare(strict_types=1);

namespace ControlResell\Exception;

/** The API could not be reached (network, DNS, TLS), after the retries. */
final class ControlResellConnectionException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Could not reach the ControlResell API: ' . $previous->getMessage(), 0, $previous);
    }
}
