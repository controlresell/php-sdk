<?php

declare(strict_types=1);

namespace ControlResell;

use ControlResell\Generated\Api\AccountsApi;
use ControlResell\Generated\Api\CatalogsApi;
use ControlResell\Generated\Api\ConversationsApi;
use ControlResell\Generated\Api\CustomersApi;
use ControlResell\Generated\Api\FavoritesApi;
use ControlResell\Generated\Api\ItemsApi;
use ControlResell\Generated\Api\MeApi;
use ControlResell\Generated\Api\OrdersApi;
use ControlResell\Generated\Api\WebhooksApi;
use ControlResell\Generated\Configuration;
use ControlResell\Internal\Middleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * A client of the ControlResell public API, for one API key.
 *
 * ```php
 * $client = new ControlResell\ControlResell(apiKey: 'cr_live_...');
 * $me = $client->me->getMe();
 * foreach ($client->listAllItems(sold: false) as $item) echo $item->getName(), "\n";
 * ```
 *
 * Calls are retried on a rate limit (after `Retry-After`), on a gateway or availability error (502, 503, 504) and on a
 * network error; a `POST` always carries the same `Idempotency-Key` across its retries. Any other error is thrown as a
 * ControlResellError. The client holds no global state: create one per API key.
 */
final class ControlResell
{
    use Pagination;

    /** The production address of the API. */
    public const DEFAULT_BASE_URL = 'https://api.controlresell.com';

    /** The environment variable read when no API key is given. */
    public const API_KEY_VARIABLE = 'CONTROLRESELL_API_KEY';

    /** The version of this SDK. */
    public const VERSION = '1.0.0';

    /** The `User-Agent` of every call. */
    public const USER_AGENT = 'controlresell-php/' . self::VERSION;

    /** The seller the API key acts for. */
    public readonly MeApi $me;

    /** The seller's marketplace accounts. */
    public readonly AccountsApi $accounts;

    /** The items of the seller's stock. */
    public readonly ItemsApi $items;

    /** The conversations with buyers. */
    public readonly ConversationsApi $conversations;

    /** The seller's sales and purchases. */
    public readonly OrdersApi $orders;

    /** The other parties of the seller's sales and purchases (needs the `customers:read` scope). */
    public readonly CustomersApi $customers;

    /** The buyers who put the seller's listings in their favorites (needs the `items:read` scope). */
    public readonly FavoritesApi $favorites;

    /** The catalog an item is written with: categories, brands, sizes, colors... (needs the `items:read` scope). */
    public readonly CatalogsApi $catalogs;

    /** The seller's webhook endpoints, their secrets and deliveries, and the events of the last 7 days. */
    public readonly WebhooksApi $webhooks;

    /** The Guzzle client every API of this client uses, with the retries, the key and the error mapping. */
    public readonly Client $httpClient;

    private ?string $nextIdempotencyKey = null;

    /**
     * @param string|null $apiKey The API key; null reads the `CONTROLRESELL_API_KEY` environment variable.
     * @param string $baseUrl The API's address (staging, a test environment...).
     * @param RetryPolicy $retry How calls are retried.
     * @param callable|null $handler A Guzzle handler (a MockHandler in tests); null uses Guzzle's default one.
     * @param array<string, mixed> $guzzleOptions More Guzzle options (timeout, proxy...).
     */
    public function __construct(
        ?string $apiKey = null,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        RetryPolicy $retry = new RetryPolicy(),
        ?callable $handler = null,
        array $guzzleOptions = [],
    ) {
        $environmentKey = getenv(self::API_KEY_VARIABLE);
        $key = $apiKey ?? (is_string($environmentKey) && $environmentKey !== '' ? $environmentKey : null);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException('No API key: pass apiKey or set ' . self::API_KEY_VARIABLE . '.');
        }

        $stack = HandlerStack::create($handler);
        // The first one pushed runs first: headers and key, then errors (read on the final answer), then the retries.
        $stack->push(Middleware::headers($key, self::USER_AGENT), 'controlresell_headers');
        $stack->push(Middleware::idempotencyKeys(fn (): ?string => $this->nextIdempotencyKey), 'controlresell_idempotency');
        $stack->push(Middleware::errors(), 'controlresell_errors');
        if ($retry->maxRetries > 0) {
            $stack->push(Middleware::retries($retry), 'controlresell_retries');
        }
        $this->httpClient = new Client(['handler' => $stack, 'http_errors' => false] + $guzzleOptions);

        // The generated serializer reads the boolean format of the default configuration only: the API reads `true`/`false`.
        // The same for every client, so setting it once holds no per-key state.
        Configuration::getDefaultConfiguration()->setBooleanFormatForQueryString(Configuration::BOOLEAN_FORMAT_STRING);
        $config = (new Configuration())->setHost(rtrim($baseUrl, '/'))->setUserAgent(self::USER_AGENT)
            ->setBooleanFormatForQueryString(Configuration::BOOLEAN_FORMAT_STRING);
        $this->me = new MeApi($this->httpClient, $config);
        $this->accounts = new AccountsApi($this->httpClient, $config);
        $this->items = new ItemsApi($this->httpClient, $config);
        $this->conversations = new ConversationsApi($this->httpClient, $config);
        $this->orders = new OrdersApi($this->httpClient, $config);
        $this->customers = new CustomersApi($this->httpClient, $config);
        $this->favorites = new FavoritesApi($this->httpClient, $config);
        $this->catalogs = new CatalogsApi($this->httpClient, $config);
        $this->webhooks = new WebhooksApi($this->httpClient, $config);
    }

    /**
     * Runs $call with $key as the `Idempotency-Key` of its `POST`s (a key you store to retry a write yourself later):
     *
     * ```php
     * $item = $client->idempotent('order-1234', fn () => $client->items->createItem($request));
     * ```
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public function idempotent(string $key, callable $call): mixed
    {
        $previous = $this->nextIdempotencyKey;
        $this->nextIdempotencyKey = $key;
        try {
            return $call();
        } finally {
            $this->nextIdempotencyKey = $previous;
        }
    }
}
