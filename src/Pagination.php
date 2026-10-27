<?php

declare(strict_types=1);

namespace ControlResell;

use ControlResell\Generated\Model\Account;
use ControlResell\Generated\Model\CatalogBrand;
use ControlResell\Generated\Model\CatalogCategory;
use ControlResell\Generated\Model\CatalogSize;
use ControlResell\Generated\Model\CatalogValue;
use ControlResell\Generated\Model\Conversation;
use ControlResell\Generated\Model\Customer;
use ControlResell\Generated\Model\Favorite;
use ControlResell\Generated\Model\Item;
use ControlResell\Generated\Model\Message;
use ControlResell\Generated\Model\Order;
use ControlResell\Generated\Model\OrderLabel;
use ControlResell\Generated\Model\WebhookDelivery;
use ControlResell\Generated\Model\WebhookEvent;

/**
 * Every element of a list paginated by cursor, page after page, as you iterate. The filters are sent again with each
 * page; the cursor is opaque. A `listAll...` method takes the filters of its `list...` operation.
 */
trait Pagination
{
    /**
     * @template T
     * @param callable(?string): object $fetch Reads the page of a cursor (null for the first one); the page has
     *     getData() and getNextCursor().
     * @return \Generator<int, T>
     */
    public static function paginate(callable $fetch): \Generator
    {
        $cursor = null;
        do {
            $page = $fetch($cursor);
            yield from $page->getData() ?? [];
            $cursor = $page->getNextCursor();
        } while ($cursor !== null && $cursor !== '');
    }

    /** @return \Generator<int, Account> */
    public function listAllAccounts(int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->accounts->listAccounts($pageSize, $cursor));
    }

    /** @return \Generator<int, Item> */
    public function listAllItems(?bool $sold = null, ?string $accountId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->items->listItems($pageSize, $cursor, $sold, $accountId));
    }

    /**
     * $from is inclusive and $to exclusive, on the last message; $offerStatus is `PENDING`, `ACCEPTED`, `REJECTED` or
     * `CANCELED`.
     *
     * @return \Generator<int, Conversation>
     */
    public function listAllConversations(
        ?bool $unread = null,
        ?string $accountId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $buyerId = null,
        ?int $itemId = null,
        ?string $offerStatus = null,
        int $pageSize = 50,
    ): \Generator {
        return self::paginate(fn (?string $cursor) => $this->conversations->listConversations(
            $pageSize, $cursor, $unread, $accountId, $from, $to, $buyerId, $itemId, $offerStatus,
        ));
    }

    /** @return \Generator<int, Message> */
    public function listAllConversationMessages(string $id, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->conversations->listConversationMessages($id, $pageSize, $cursor, $from, $to));
    }

    /** @return \Generator<int, OrderLabel> */
    public function listAllLabelsToShip(int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->orders->listLabelsToShip($pageSize, $cursor));
    }

    /**
     * $bucket is `to_send` or `to_pickup`; $from is inclusive and $to exclusive.
     *
     * @return \Generator<int, Order>
     */
    public function listAllOrders(
        ?string $bucket = null,
        ?string $platform = null,
        ?string $accountId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $pageSize = 50,
    ): \Generator {
        return self::paginate(fn (?string $cursor) => $this->orders->listOrders($pageSize, $cursor, $bucket, $platform, $accountId, $from, $to));
    }

    /** @return \Generator<int, Customer> */
    public function listAllCustomers(?string $accountId = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->customers->listCustomers($pageSize, $cursor, $from, $to, $accountId));
    }

    /** @return \Generator<int, Favorite> */
    public function listAllFavorites(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $accountId = null,
        ?int $itemId = null,
        int $pageSize = 50,
    ): \Generator {
        return self::paginate(fn (?string $cursor) => $this->favorites->listFavorites($pageSize, $cursor, $from, $to, $accountId, $itemId));
    }

    /** @return \Generator<int, Favorite> */
    public function listAllItemFavorites(int $id, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->favorites->listItemFavorites($id, $pageSize, $cursor, $from, $to));
    }

    /** @return \Generator<int, CatalogCategory> */
    public function listAllCatalogCategories(?int $parentId = null, ?string $platform = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogCategories($pageSize, $cursor, $parentId, $platform));
    }

    /** @return \Generator<int, CatalogBrand> */
    public function listAllCatalogBrands(?string $q = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogBrands($pageSize, $cursor, $q));
    }

    /** @return \Generator<int, CatalogSize> */
    public function listAllCatalogSizes(?int $categoryId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogSizes($pageSize, $cursor, $categoryId));
    }

    /** @return \Generator<int, CatalogValue> */
    public function listAllCatalogColors(?int $categoryId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogColors($pageSize, $cursor, $categoryId));
    }

    /** @return \Generator<int, CatalogValue> */
    public function listAllCatalogConditions(?int $categoryId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogConditions($pageSize, $cursor, $categoryId));
    }

    /** @return \Generator<int, CatalogValue> */
    public function listAllCatalogPackageSizes(?int $categoryId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogPackageSizes($pageSize, $cursor, $categoryId));
    }

    /** @return \Generator<int, CatalogValue> */
    public function listAllCatalogMaterials(?int $categoryId = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->catalogs->listCatalogMaterials($pageSize, $cursor, $categoryId));
    }

    /**
     * $status is `pending`, `delivered` or `failed`.
     *
     * @return \Generator<int, WebhookDelivery>
     */
    public function listAllWebhookDeliveries(string $id, ?string $status = null, ?string $type = null, int $pageSize = 50): \Generator
    {
        return self::paginate(fn (?string $cursor) => $this->webhooks->listWebhookDeliveries($id, $status, $type, $pageSize, $cursor));
    }

    /**
     * Every event of the last 7 days from $since, the oldest first. $types keeps these event types.
     *
     * @param list<string>|null $types
     * @return \Generator<int, WebhookEvent>
     */
    public function listAllWebhookEvents(?\DateTimeInterface $since = null, ?array $types = null, int $pageSize = 50): \Generator
    {
        $type = $types === null || $types === [] ? null : implode(',', $types);

        return self::paginate(fn (?string $cursor) => $this->webhooks->listWebhookEvents($since, $type, $pageSize, $cursor));
    }
}
