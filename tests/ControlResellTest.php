<?php

declare(strict_types=1);

namespace ControlResell\Tests;

use ControlResell\ControlResell;
use ControlResell\Exception\AuthenticationException;
use ControlResell\Exception\ConflictException;
use ControlResell\Exception\ControlResellConnectionException;
use ControlResell\Exception\ControlResellError;
use ControlResell\Exception\NotFoundException;
use ControlResell\Exception\RateLimitException;
use ControlResell\Exception\ServerException;
use ControlResell\Generated\Model\Item;
use ControlResell\Generated\Model\ItemCreateRequest;
use ControlResell\Generated\Model\PriceDropRequest;
use ControlResell\Generated\Model\PublishRequest;
use ControlResell\Generated\Model\RepublishRequest;
use ControlResell\RetryPolicy;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ControlResellTest extends TestCase
{
    /** @var list<RequestInterface> Every attempt that reached the transport. */
    private array $sent = [];

    /** A client over a mock transport answering $answers in order, with retries that do not wait. */
    private function client(array $answers, int $maxRetries = 2): ControlResell
    {
        $mock = new MockHandler($answers);
        $recording = function (RequestInterface $request, array $options) use ($mock) {
            $this->sent[] = $request;
            unset($options['delay']);

            return $mock($request, $options);
        };

        return new ControlResell(
            apiKey: 'cr_live_test',
            baseUrl: 'https://api.test',
            retry: new RetryPolicy(maxRetries: $maxRetries, baseDelayMillis: 0, maxDelayMillis: 0),
            handler: $recording,
        );
    }

    private static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function error(int $status, string $code, array $headers = []): Response
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => "Error $code", 'params' => ['id' => '42'], 'requestId' => 'req_1']], $headers);
    }

    private static function item(int $id, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'name' => "Jacket $id", 'photos' => [], 'colorIds' => [], 'labels' => [], 'sold' => false,
            'materialIds' => [], 'enumValues' => [], 'attributes' => [], 'customFields' => [],
            'createdAt' => '2026-10-20T10:00:00Z', 'updatedAt' => '2026-10-21T10:00:00Z',
        ];
    }

    public function testSendsTheKeyTheUserAgentAndReadsAnItem(): void
    {
        $client = $this->client([self::json(200, self::item(42, [
            'sellingPrice' => ['amount' => '12.50', 'currency' => 'EUR'],
            'estimatedPrice' => null,
            'sex' => 'A_VALUE_ADDED_LATER',
            'aFieldAddedLater' => true,
        ]))]);

        $item = $client->items->getItem(42);

        self::assertInstanceOf(Item::class, $item);
        self::assertSame(42, $item->getId());
        self::assertSame('12.50', $item->getSellingPrice()->getAmount());
        self::assertNull($item->getEstimatedPrice());
        self::assertSame('A_VALUE_ADDED_LATER', $item->getSex());
        self::assertSame('Bearer cr_live_test', $this->sent[0]->getHeaderLine('Authorization'));
        self::assertSame('controlresell-php/1.0.0', $this->sent[0]->getHeaderLine('User-Agent'));
        self::assertSame('https://api.test/api/public/v1/items/42', (string) $this->sent[0]->getUri());
        self::assertFalse($this->sent[0]->hasHeader('Idempotency-Key'));
    }

    public function testReadsTheKeyFromTheEnvironment(): void
    {
        putenv('CONTROLRESELL_API_KEY=cr_live_env');
        try {
            $mock = new MockHandler([self::json(200, ['id' => 1, 'email' => 'a@b.c'])]);
            $sent = [];
            $client = new ControlResell(handler: function ($request, $options) use ($mock, &$sent) {
                $sent[] = $request;

                return $mock($request, $options);
            });
            $client->httpClient->get('/api/public/v1/me');
            self::assertSame('Bearer cr_live_env', $sent[0]->getHeaderLine('Authorization'));
        } finally {
            putenv('CONTROLRESELL_API_KEY');
        }
    }

    public function testRefusesAClientWithoutKey(): void
    {
        putenv('CONTROLRESELL_API_KEY');
        $this->expectException(\InvalidArgumentException::class);
        new ControlResell();
    }

    public function testAPostKeepsTheSameIdempotencyKeyAcrossRetries(): void
    {
        $client = $this->client([self::error(503, 'unavailable'), self::error(502, 'bad_gateway'), self::json(201, self::item(7))]);

        $item = $client->items->createItem(new ItemCreateRequest(['name' => 'Jacket']));

        self::assertSame(7, $item->getId());
        self::assertCount(3, $this->sent);
        $key = $this->sent[0]->getHeaderLine('Idempotency-Key');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        foreach ($this->sent as $attempt) {
            self::assertSame($key, $attempt->getHeaderLine('Idempotency-Key'));
        }
    }

    public function testPublishPriceDropAndRepublishCarryScheduledAt(): void
    {
        $receipt = ['itemId' => 7, 'action' => 'publish', 'accountIds' => ['3f2c1c0e-5b7a-4a3e-9d1e-0a1b2c3d4e5f'], 'scheduledAt' => '2026-11-01T09:30:00Z'];
        $client = $this->client([self::json(202, $receipt), self::json(202, $receipt), self::json(202, $receipt)]);
        $at = new \DateTime('2026-11-01T09:30:00Z');

        $result = $client->items->publishItem(7, new PublishRequest(['account_ids' => ['3f2c1c0e-5b7a-4a3e-9d1e-0a1b2c3d4e5f'], 'scheduled_at' => $at]));
        $client->items->dropItemPrice(7, new PriceDropRequest(['type' => 'percent', 'value' => '10', 'scheduled_at' => $at]));
        $client->items->republishItem(7, new RepublishRequest(['scheduled_at' => $at]));

        self::assertSame(7, $result->getItemId());
        self::assertCount(3, $this->sent);
        self::assertSame(['publish', 'price-drop', 'republish'], array_map(fn ($r) => basename($r->getUri()->getPath()), $this->sent));
        foreach ($this->sent as $request) {
            self::assertSame('POST', $request->getMethod());
            $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('scheduledAt', $body);
            self::assertMatchesRegularExpression('/^2026-11-01T09:30:00(\+00:00|Z)$/', $body['scheduledAt']);
        }
    }

    public function testTwoPostsGetTwoKeys(): void
    {
        $client = $this->client([self::json(201, self::item(1)), self::json(201, self::item(2))]);
        $client->items->createItem(new ItemCreateRequest(['name' => 'A']));
        $client->items->createItem(new ItemCreateRequest(['name' => 'B']));
        self::assertNotSame($this->sent[0]->getHeaderLine('Idempotency-Key'), $this->sent[1]->getHeaderLine('Idempotency-Key'));
    }

    public function testTheCallerChoosesTheKey(): void
    {
        $client = $this->client([self::json(201, self::item(1)), self::json(201, self::item(2))]);
        $client->idempotent('order-1234', fn () => $client->items->createItem(new ItemCreateRequest(['name' => 'A'])));
        $client->items->createItem(new ItemCreateRequest(['name' => 'B']));
        self::assertSame('order-1234', $this->sent[0]->getHeaderLine('Idempotency-Key'));
        self::assertNotSame('order-1234', $this->sent[1]->getHeaderLine('Idempotency-Key'));
    }

    public function testARateLimitIsRetriedThenThrownWithItsRetryAfter(): void
    {
        $client = $this->client([
            self::error(429, 'rate_limited', ['Retry-After' => '3']),
            self::error(429, 'rate_limited', ['Retry-After' => '3']),
            self::error(429, 'rate_limited', ['Retry-After' => '7']),
        ]);
        try {
            $client->me->getMe();
            self::fail('No exception');
        } catch (RateLimitException $e) {
            self::assertSame(7, $e->retryAfterSeconds);
            self::assertSame(429, $e->status);
            self::assertSame('rate_limited', $e->errorCode);
        }
        self::assertCount(3, $this->sent);
    }

    public function testTheDelayFollowsRetryAfterAndTheBackoff(): void
    {
        $policy = new RetryPolicy(maxRetries: 2, baseDelayMillis: 500, maxDelayMillis: 30_000);
        self::assertSame(3000, $policy->delayMillis(1, 3));
        self::assertSame(30_000, $policy->delayMillis(1, 3600));
        $first = $policy->delayMillis(1, null);
        self::assertGreaterThanOrEqual(500, $first);
        self::assertLessThanOrEqual(750, $first);
        $second = $policy->delayMillis(2, null);
        self::assertGreaterThanOrEqual(1000, $second);
        self::assertLessThanOrEqual(1250, $second);
    }

    public function testA500IsNeverRetried(): void
    {
        $client = $this->client([self::error(500, 'internal'), self::json(201, self::item(1))]);
        try {
            $client->items->createItem(new ItemCreateRequest(['name' => 'A']));
            self::fail('No exception');
        } catch (ServerException $e) {
            self::assertSame(500, $e->status);
        }
        self::assertCount(1, $this->sent);
    }

    public function testAnIdempotencyInProgressIsRetriedButNotAnother409(): void
    {
        $client = $this->client([self::error(409, 'idempotency_in_progress'), self::json(201, self::item(1))]);
        self::assertSame(1, $client->items->createItem(new ItemCreateRequest(['name' => 'A']))->getId());
        self::assertCount(2, $this->sent);

        $this->sent = [];
        $client = $this->client([self::error(409, 'idempotency_key_reused'), self::json(201, self::item(1))]);
        $this->expectException(ConflictException::class);
        try {
            $client->items->createItem(new ItemCreateRequest(['name' => 'A']));
        } finally {
            self::assertCount(1, $this->sent);
        }
    }

    public function testAWebhookTestIsOnlyRetriedOnARateLimit(): void
    {
        $client = $this->client([self::error(503, 'unavailable'), self::json(200, ['delivered' => true])]);
        try {
            $client->webhooks->testWebhookEndpoint('11111111-1111-4111-8111-111111111111');
            self::fail('No exception');
        } catch (ServerException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(1, $this->sent);
    }

    public function testANetworkErrorIsRetriedThenThrown(): void
    {
        $request = new Request('GET', 'https://api.test/api/public/v1/me');
        $client = $this->client([
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
        ]);
        try {
            $client->me->getMe();
            self::fail('No exception');
        } catch (ControlResellConnectionException $e) {
            self::assertStringContainsString('Connection refused', $e->getMessage());
            self::assertStringNotContainsString('cr_live_test', $e->getMessage());
        }
        self::assertCount(3, $this->sent);
    }

    public function testAnErrorIsTypedWithItsEnvelope(): void
    {
        $client = $this->client([self::error(404, 'item_not_found')]);
        try {
            $client->items->getItem(42);
            self::fail('No exception');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('item_not_found', $e->errorCode);
            self::assertSame('Error item_not_found', $e->getMessage());
            self::assertSame(['id' => '42'], $e->params);
            self::assertSame('req_1', $e->requestId);
            self::assertStringNotContainsString('cr_live_test', $e->getMessage());
        }
    }

    public function testAnErrorWithoutEnvelopeKeepsItsStatus(): void
    {
        $client = $this->client([new Response(401, [], '<html>nope</html>')]);
        try {
            $client->me->getMe();
            self::fail('No exception');
        } catch (AuthenticationException $e) {
            self::assertSame('http_401', $e->errorCode);
            self::assertNull($e->requestId);
        }

        $this->sent = [];
        $client = $this->client([self::error(418, 'teapot')]);
        $this->expectException(ControlResellError::class);
        $client->me->getMe();
    }

    public function testIteratesEveryPageWithTheSameFilters(): void
    {
        $client = $this->client([
            self::json(200, ['data' => [self::item(1), self::item(2)], 'nextCursor' => 'c2']),
            self::json(200, ['data' => [self::item(3)], 'nextCursor' => null]),
        ]);

        $ids = array_map(static fn (Item $item) => $item->getId(), iterator_to_array($client->listAllItems(sold: false, pageSize: 2), false));

        self::assertSame([1, 2, 3], $ids);
        self::assertCount(2, $this->sent);
        parse_str($this->sent[0]->getUri()->getQuery(), $first);
        parse_str($this->sent[1]->getUri()->getQuery(), $second);
        self::assertSame(['limit' => '2', 'sold' => 'false'], $first);
        self::assertSame(['limit' => '2', 'cursor' => 'c2', 'sold' => 'false'], $second);
    }

    public function testTheEventTypesAreJoined(): void
    {
        $client = $this->client([self::json(200, ['data' => [], 'nextCursor' => null])]);
        iterator_to_array($client->listAllWebhookEvents(types: ['item.sold', 'order.created']));
        parse_str($this->sent[0]->getUri()->getQuery(), $query);
        self::assertSame('item.sold,order.created', $query['type']);
    }

    public function testRetriesCanBeTurnedOff(): void
    {
        $client = $this->client([self::error(503, 'unavailable')], maxRetries: 0);
        $this->expectException(ServerException::class);
        try {
            $client->me->getMe();
        } finally {
            self::assertCount(1, $this->sent);
        }
    }

    public function testAnEventKeepsItsFreeFormData(): void
    {
        $client = $this->client([self::json(200, ['data' => [[
            'id' => '11111111-1111-4111-8111-111111111111', 'type' => 'item.sold', 'createdAt' => '2026-10-20T10:00:00Z',
            'apiVersion' => '1', 'sellerId' => 9, 'data' => ['itemId' => 42, 'price' => '12.50', 'tags' => ['a'], 'buyer' => null],
        ]], 'nextCursor' => null])]);

        $events = iterator_to_array($client->listAllWebhookEvents(), false);

        self::assertCount(1, $events);
        $data = (array) $events[0]->getData();
        self::assertSame(42, $data['itemId']);
        self::assertSame('12.50', $data['price']);
    }
}
