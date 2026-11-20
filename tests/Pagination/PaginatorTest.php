<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Pagination;

use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Pagination\Paginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Paginator::class)]
#[UsesClass(TransactionPage::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(Amount::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(PeyException::class)]
final class PaginatorTest extends TestCase
{
    /**
     * @var list<?string>
     */
    private array $requestedTokens = [];

    private static function transaction(string $id): Transaction
    {
        return new Transaction($id, TransactionStatus::Paid, Amount::fromString('1'), null, 'MXN', PaymentMethod::Codi, null, null, null, null, new \DateTimeImmutable('@0'), null, new TransactionMetadata());
    }

    /**
     * @param array<string, TransactionPage> $pagesByToken keyed by the token that fetches them ('' for the first page)
     *
     * @return \Closure(?string): TransactionPage
     */
    private function fetcher(array $pagesByToken): \Closure
    {
        return function (?string $token) use ($pagesByToken): TransactionPage {
            $this->requestedTokens[] = $token;

            return $pagesByToken[$token ?? ''] ?? throw new \LogicException('Unexpected token ' . var_export($token, true));
        };
    }

    public function testItWalksEveryPageInOrder(): void
    {
        $fetch = $this->fetcher([
            '' => new TransactionPage([self::transaction('a'), self::transaction('b')], 'page2'),
            'page2' => new TransactionPage([self::transaction('c')], 'page3'),
            'page3' => new TransactionPage([self::transaction('d')], null),
        ]);

        $ids = array_map(static fn(Transaction $t): string => $t->id, iterator_to_array(Paginator::items($fetch)));

        self::assertSame(['a', 'b', 'c', 'd'], $ids, 'Keys are sequential, so iterator_to_array keeps every item.');
        self::assertSame([null, 'page2', 'page3'], $this->requestedTokens);
    }

    public function testPagesAreFetchedLazily(): void
    {
        $fetch = $this->fetcher([
            '' => new TransactionPage([self::transaction('a'), self::transaction('b')], 'page2'),
            'page2' => new TransactionPage([self::transaction('c')], null),
        ]);

        foreach (Paginator::items($fetch) as $transaction) {
            if ($transaction->id === 'a') {
                break;
            }
        }

        self::assertSame([null], $this->requestedTokens, 'Stopping early must not fetch further pages.');
    }

    public function testAnEmptyFirstPageEndsImmediately(): void
    {
        $fetch = $this->fetcher(['' => new TransactionPage([], null)]);

        self::assertSame([], iterator_to_array(Paginator::items($fetch)));
        self::assertSame([null], $this->requestedTokens);
    }

    public function testAnEmptyCursorEndsPaging(): void
    {
        $fetch = $this->fetcher(['' => new TransactionPage([self::transaction('a')], '')]);

        self::assertCount(1, iterator_to_array(Paginator::items($fetch)));
    }

    public function testPagingCanStartFromACursor(): void
    {
        $fetch = $this->fetcher(['page2' => new TransactionPage([self::transaction('c')], null)]);

        $pages = iterator_to_array(Paginator::pages($fetch, 'page2'));

        self::assertCount(1, $pages);
        self::assertSame(['page2'], $this->requestedTokens);
    }

    public function testARepeatedCursorStopsInsteadOfLoopingForever(): void
    {
        $fetch = $this->fetcher([
            '' => new TransactionPage([self::transaction('a')], 'page2'),
            'page2' => new TransactionPage([self::transaction('b')], 'page2'),
        ]);

        try {
            iterator_to_array(Paginator::items($fetch));
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('cursor (nextToken) was repeated', $e->getMessage());
        }

        self::assertSame([null, 'page2'], $this->requestedTokens);
    }

    public function testACursorPointingBackToTheStartIsAlsoRepeated(): void
    {
        $fetch = $this->fetcher(['start' => new TransactionPage([self::transaction('a')], 'start')]);

        $this->expectException(UnexpectedResponseException::class);

        iterator_to_array(Paginator::pages($fetch, 'start'));
    }
}
