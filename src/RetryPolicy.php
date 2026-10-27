<?php

declare(strict_types=1);

namespace ControlResell;

/**
 * How a call is retried.
 *
 * Retried: a rate limit (429, after its `Retry-After`), a gateway or availability error (502, 503, 504), a network
 * error, and a 409 `idempotency_in_progress`. Never a 500: a `POST` may have been applied, and its key would replay the
 * same 500.
 */
final class RetryPolicy
{
    /**
     * @param int $maxRetries Retries after the first attempt (2 by default); 0 turns them off.
     * @param int $baseDelayMillis The first delay, doubled at each retry, with a random part; a `Retry-After` takes precedence.
     * @param int $maxDelayMillis The longest delay between two attempts.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly int $baseDelayMillis = 500,
        public readonly int $maxDelayMillis = 30_000,
    ) {
    }

    /**
     * The delay before the retry number $retries (1 for the first retry), in milliseconds.
     *
     * @param int|null $retryAfterSeconds The `Retry-After` of the answer, when it had one.
     */
    public function delayMillis(int $retries, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return min($retryAfterSeconds * 1000, $this->maxDelayMillis);
        }
        $backoff = $this->baseDelayMillis * (1 << max(0, $retries - 1));
        $jitter = random_int(0, intdiv($this->baseDelayMillis, 2));

        return min($backoff + $jitter, $this->maxDelayMillis);
    }
}
