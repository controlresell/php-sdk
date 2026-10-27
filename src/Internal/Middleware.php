<?php

declare(strict_types=1);

namespace ControlResell\Internal;

use ControlResell\Exception\ControlResellConnectionException;
use ControlResell\Exception\ControlResellError;
use ControlResell\RetryPolicy;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @internal The Guzzle middlewares of the client: headers, idempotency keys, retries and typed errors. */
final class Middleware
{
    public const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';

    private const RETRIED_STATUSES = [429, 502, 503, 504];

    private const IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';

    private const WEBHOOK_TEST_PATH = '#/webhook-endpoints/[^/]+/test/?$#';

    /** Sets the `Authorization` and `User-Agent` of every call. */
    public static function headers(string $apiKey, string $userAgent): callable
    {
        return GuzzleMiddleware::mapRequest(
            static fn (RequestInterface $request) => $request
                ->withHeader('Authorization', 'Bearer ' . $apiKey)
                ->withHeader('User-Agent', $userAgent),
        );
    }

    /**
     * Gives each `POST` an `Idempotency-Key` when it has none: the one $chosenKey returns, else a random UUID v4. It runs
     * once per call, outside the retries, so every retry of the call sends the same key.
     *
     * @param callable(): ?string $chosenKey
     */
    public static function idempotencyKeys(callable $chosenKey): callable
    {
        return GuzzleMiddleware::mapRequest(
            static function (RequestInterface $request) use ($chosenKey): RequestInterface {
                if ($request->getMethod() !== 'POST' || $request->hasHeader(self::IDEMPOTENCY_KEY_HEADER)) {
                    return $request;
                }

                return $request->withHeader(self::IDEMPOTENCY_KEY_HEADER, $chosenKey() ?? self::uuid4());
            },
        );
    }

    /** Throws a typed ControlResellError for an answer of 400 or more, and a connection exception after the retries. */
    public static function errors(): callable
    {
        return static fn (callable $handler) => static fn (RequestInterface $request, array $options) => $handler($request, $options)->then(
            static function (ResponseInterface $response): ResponseInterface {
                if ($response->getStatusCode() >= 400) {
                    throw ControlResellError::fromResponse($response);
                }

                return $response;
            },
            static function (mixed $reason) {
                if ($reason instanceof ConnectException) {
                    throw new ControlResellConnectionException($reason);
                }

                return Create::rejectionFor($reason);
            },
        );
    }

    /** Retries a call as RetryPolicy says. */
    public static function retries(RetryPolicy $policy): callable
    {
        $decider = static function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, mixed $reason = null) use ($policy): bool {
            if ($retries >= $policy->maxRetries) {
                return false;
            }
            $webhookTest = self::isWebhookTest($request);
            if ($response === null) {
                // A refused connection and a request that reached the server look the same: never replay a webhook test.
                return $reason instanceof ConnectException && !$webhookTest;
            }
            $status = $response->getStatusCode();
            if ($status === 429) {
                return true;
            }
            if ($webhookTest) {
                return false;
            }
            if (in_array($status, self::RETRIED_STATUSES, true)) {
                return true;
            }

            return $status === 409 && (ControlResellError::envelope($response)['code'] ?? null) === self::IDEMPOTENCY_IN_PROGRESS;
        };
        $delay = static fn (int $retries, ?ResponseInterface $response = null): int => $policy->delayMillis(
            $retries,
            $response === null ? null : ControlResellError::retryAfter($response),
        );

        return GuzzleMiddleware::retry($decider, $delay);
    }

    /**
     * `POST /webhook-endpoints/{id}/test` sends a real event and the API ignores its `Idempotency-Key`: only a 429
     * (answered before the event leaves) is retried.
     */
    private static function isWebhookTest(RequestInterface $request): bool
    {
        return $request->getMethod() === 'POST' && preg_match(self::WEBHOOK_TEST_PATH, $request->getUri()->getPath()) === 1;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
