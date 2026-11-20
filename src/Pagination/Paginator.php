<?php

declare(strict_types=1);

namespace Dev1\Pey\Pagination;

use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;

/**
 * Lazily walks Pey's `nextToken` pagination.
 *
 * Pages are fetched only as the generator is consumed, so breaking out of a
 * foreach stops further requests. If Pey ever returns a cursor it already
 * returned, paging stops with an UnexpectedResponseException instead of
 * looping forever.
 */
final class Paginator
{
    /**
     * @param \Closure(?string): TransactionPage $fetchPage receives the cursor (null for the first page)
     *
     * @return \Generator<int, TransactionPage>
     *
     * @throws PeyException
     */
    public static function pages(\Closure $fetchPage, ?string $startToken = null): \Generator
    {
        $token = $startToken;
        $seen = $token === null ? [] : [$token => true];

        while (true) {
            $page = $fetchPage($token);
            yield $page;

            if (!$page->hasMore()) {
                return;
            }

            $token = (string) $page->nextToken;
            if (isset($seen[$token])) {
                throw UnexpectedResponseException::repeatedCursor();
            }
            $seen[$token] = true;
        }
    }

    /**
     * Every transaction across all pages, newest first, with sequential keys.
     *
     * @param \Closure(?string): TransactionPage $fetchPage
     *
     * @return \Generator<int, Transaction>
     *
     * @throws PeyException
     */
    public static function items(\Closure $fetchPage, ?string $startToken = null): \Generator
    {
        foreach (self::pages($fetchPage, $startToken) as $page) {
            foreach ($page->items as $item) {
                yield $item;
            }
        }
    }
}
