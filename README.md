# ControlResell PHP SDK

PHP client for the [ControlResell](https://controlresell.com) public API (PHP 8.1+, Guzzle 7).

## Install

```sh
composer require controlresell/sdk
```

```php
use ControlResell\ControlResell;
use ControlResell\Exception\NotFoundException;
use ControlResell\Generated\Model\ItemCreateRequest;
use ControlResell\Generated\Model\PublishRequest;

$client = new ControlResell(apiKey: 'cr_live_...'); // or set CONTROLRESELL_API_KEY

$me = $client->me->getMe();
foreach ($client->listAllItems(sold: false) as $item) {
    echo $item->getId(), ' ', $item->getName(), "\n";
}
foreach ($client->listAllOrders(bucket: 'to_send') as $order) { // needs the orders:read scope
    echo $order->getId(), "\n";
}
foreach ($client->listAllCatalogBrands(q: 'nik') as $brand) { // the identifiers an item is written with (items:read)
    echo $brand->getId(), ' ', $brand->getName(), "\n";
}

// Writes (API key with `items:write` / `conversations:write`). Each POST gets an Idempotency-Key, the same on every
// retry; idempotent() chooses it, to retry a write yourself later without doing it twice.
$item = $client->items->createItem(new ItemCreateRequest(['name' => 'Jacket']));
$client->idempotent('publish-' . $item->getId(), fn () => $client->items->publishItem(
    $item->getId(),
    new PublishRequest(['account_ids' => ['<account id>'], 'scheduled_at' => new DateTime('+1 day')]),
));
// publishItem, dropItemPrice and republishItem take an optional `scheduled_at` (a DateTime, at most 30 days ahead): the
// action starts then instead of now (tomorrow above). The 202 receipt carries the date.

try {
    $client->items->getItem(42);
} catch (NotFoundException $e) {
    echo $e->errorCode, ' ', $e->requestId, "\n"; // give the requestId to the support
}

// Webhooks (`webhooks:read` / `webhooks:write`): listAllWebhookEvents catches up the last 7 days.
foreach ($client->listAllWebhookEvents(types: ['item.sold']) as $event) {
    echo $event->getType(), "\n";
}
```

Built in:

- **Retries**: 2 by default, exponential delay with jitter; a 429 waits for its `Retry-After`; 502, 503, 504 and network
  errors are retried, a 500 never is. `new ControlResell(retry: new RetryPolicy(maxRetries: 0))` turns them off.
- **Idempotency**: every `POST` carries an `Idempotency-Key` (UUID v4), the same on each retry of the call.
- **Pagination**: `listAll...()` returns a generator that reads the pages one after another, with the same filters;
  `list...()` reads one page (`getData()`, `getNextCursor()`).
- **Typed errors**: `ControlResellError` (`status`, `errorCode`, `getMessage()`, `params`, `requestId`) and one class per
  status: `BadRequestException`, `AuthenticationException`, `PaymentRequiredException`, `PermissionDeniedException`,
  `NotFoundException`, `ConflictException`, `UnprocessableEntityException`, `RateLimitException` (`retryAfterSeconds`),
  `ServerException`; `ControlResellConnectionException` when the API cannot be reached. `errorCode` is an open string.
- Amounts keep their decimal string (`getAmount()`), an unknown enumeration value is kept as is.

`baseUrl` points the client elsewhere (`new ControlResell(apiKey: '...', baseUrl: 'https://...')`); `guzzleOptions`
passes Guzzle options (timeout, proxy).

The client in `src/Generated` is generated from the API's OpenAPI document by
[OpenAPI Generator](https://openapi-generator.tech) (`scripts/generate.sh`); the rest of `src` is written by hand.

Licensed under the Apache License 2.0.
