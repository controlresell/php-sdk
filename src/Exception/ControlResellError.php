<?php

declare(strict_types=1);

namespace ControlResell\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * An error answered by the API, read from its envelope `{"error": {code, message, params, requestId}}`.
 *
 * `code` is an open string: match the codes you handle and treat any other one by its status.
 */
class ControlResellError extends \RuntimeException
{
    /**
     * @param int $status The HTTP status.
     * @param string $errorCode The error's code (`item_not_found`, `rate_limited`...).
     * @param array<string, string> $params The error's parameters.
     * @param string|null $requestId The identifier to give the support.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $params,
        public readonly ?string $requestId,
    ) {
        parent::__construct($message, $status);
    }

    /** The typed error of a response whose status is 400 or more. */
    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $error = self::envelope($response);
        $code = is_string($error['code'] ?? null) ? $error['code'] : "http_$status";
        $message = is_string($error['message'] ?? null) ? $error['message'] : "HTTP $status";
        $params = [];
        foreach ((is_array($error['params'] ?? null) ? $error['params'] : []) as $key => $value) {
            $params[(string) $key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }
        $requestId = is_string($error['requestId'] ?? null) ? $error['requestId'] : null;

        return match (true) {
            $status === 400 => new BadRequestException($code, $message, $params, $requestId),
            $status === 401 => new AuthenticationException($code, $message, $params, $requestId),
            $status === 402 => new PaymentRequiredException($code, $message, $params, $requestId),
            $status === 403 => new PermissionDeniedException($code, $message, $params, $requestId),
            $status === 404 => new NotFoundException($code, $message, $params, $requestId),
            $status === 409 => new ConflictException($code, $message, $params, $requestId),
            $status === 422 => new UnprocessableEntityException($code, $message, $params, $requestId),
            $status === 429 => new RateLimitException($code, $message, $params, $requestId, self::retryAfter($response)),
            $status >= 500 && $status <= 599 => new ServerException($status, $code, $message, $params, $requestId),
            default => new self($status, $code, $message, $params, $requestId),
        };
    }

    /** @return array<string, mixed> The `error` object of the body, empty when the body is not the envelope. */
    public static function envelope(ResponseInterface $response): array
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $text = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    }

    /** The `Retry-After` of a response in seconds, when it is a number. */
    public static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        return ctype_digit($value) ? (int) $value : null;
    }
}
