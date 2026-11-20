<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Resources;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Pagination\Paginator;
use Dev1\Pey\Resources\Transactions;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Validation\OpaqueId;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transactions::class)]
#[UsesClass(Paginator::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Transport::class)]
#[UsesClass(Json::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(TransactionPage::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(AmountBreakdown::class)]
#[UsesClass(TransactionStatus::class)]
#[UsesClass(PaymentMethod::class)]
#[UsesClass(PayableType::class)]
#[UsesClass(OpaqueId::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Violations::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ValidationException::class)]
final class TransactionsTest extends TestCase
{
    private const TX = '01M3QR8H0RH0XBCKYYD242YXNV';

    private const BANK_ACCOUNT = '01JBAVWE4NS5TGXHWGMX3KH23Q';

    private MockHttpClient $http;

    private Transactions $transactions;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $factory = new Psr17Factory();
        $this->transactions = new Transactions(new Transport(new Config('pey_test_not_a_real_value'), $this->http, $factory, $factory));
    }

    private static function transactionJson(string $id, string $status = 'PAID'): string
    {
        return \sprintf(
            '{"id":"%s","status":"%s","amount":102.5,"amountBreakdown":{"base":100,"fee":2.5},"currency":"MXN","paymentMethod":"CODI",'
            . '"payableType":"API_CODI_PN","payableId":"01HXYZ1234567890ABCDEFGH00","dateCreated":1704067200,"datePaid":1704067260,"metadata":{}}',
            $id,
            $status,
        );
    }

    /**
     * @param list<string> $ids
     */
    private static function pageJson(array $ids, ?string $nextToken): string
    {
        return \sprintf(
            '{"items":[%s],"nextToken":%s}',
            implode(',', array_map(static fn(string $id): string => self::transactionJson($id), $ids)),
            $nextToken === null ? 'null' : '"' . $nextToken . '"',
        );
    }

    public function testGetRetrievesATransactionToConfirmAPayment(): void
    {
        $this->http->willRespond(200, self::transactionJson(self::TX));

        $transaction = $this->transactions->get(self::TX);

        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame('https://api.pey.mx/transaction/' . self::TX, (string) $this->http->lastRequest()->getUri());
        self::assertTrue($transaction->isPaid());
        self::assertSame('100.00', $transaction->amountBreakdown?->base->toDecimalString());
        self::assertSame(PayableType::CodiPushNotification, $transaction->payableType);
    }

    public function testGetMapsUnknownTransactionsToNotFound(): void
    {
        $this->http->willRespond(404, '{"error":{"message":"Transaction not found","type":"NOT_FOUND","requestId":"r"}}');

        $this->expectException(NotFoundException::class);

        $this->transactions->get(self::TX);
    }

    public function testListForApiKeyRequestsTheFirstPageWithoutCursor(): void
    {
        $this->http->willRespond(200, self::pageJson([self::TX], 'eyJhIjoxfQ=='));

        $page = $this->transactions->listForApiKey();

        self::assertSame('https://api.pey.mx/transaction/api-key', (string) $this->http->lastRequest()->getUri());
        self::assertCount(1, $page->items);
        self::assertSame('eyJhIjoxfQ==', $page->nextToken);
    }

    public function testListingsPassTheCursorAsAnEncodedQueryParameter(): void
    {
        $this->http->willRespond(200, self::pageJson([], null));

        $this->transactions->listForApiKey('eyJhIjoxfQ+/==');

        self::assertSame('nextToken=eyJhIjoxfQ%2B%2F%3D%3D', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testListForBankAccount(): void
    {
        $this->http->willRespond(200, self::pageJson([self::TX], null));

        $this->transactions->listForBankAccount(self::BANK_ACCOUNT, 'next');

        self::assertSame('https://api.pey.mx/transaction/bank-account/' . self::BANK_ACCOUNT . '?nextToken=next', (string) $this->http->lastRequest()->getUri());
    }

    public function testListForCodiQrUsesTheBanxicoId(): void
    {
        $this->http->willRespond(200, self::pageJson([self::TX], null));

        $this->transactions->listForCodiQr('35a13c183a');

        self::assertSame('https://api.pey.mx/transaction/codi/qr/35a13c183a', (string) $this->http->lastRequest()->getUri());
    }

    public function testAllForApiKeyFollowsEveryCursor(): void
    {
        $this->http
            ->willRespond(200, self::pageJson(['01M3QR8H0RH0XBCKYYD242YXN1', '01M3QR8H0RH0XBCKYYD242YXN2'], 'cursor-2'))
            ->willRespond(200, self::pageJson(['01M3QR8H0RH0XBCKYYD242YXN3'], null));

        $ids = array_map(static fn(Transaction $t): string => $t->id, iterator_to_array($this->transactions->allForApiKey()));

        self::assertSame(['01M3QR8H0RH0XBCKYYD242YXN1', '01M3QR8H0RH0XBCKYYD242YXN2', '01M3QR8H0RH0XBCKYYD242YXN3'], $ids);
        self::assertCount(2, $this->http->requests);
        self::assertSame('', $this->http->requests[0]->getUri()->getQuery());
        self::assertSame('nextToken=cursor-2', $this->http->requests[1]->getUri()->getQuery());
    }

    public function testAllForBankAccountAndCodiQrPage(): void
    {
        $this->http
            ->willRespond(200, self::pageJson([self::TX], 'c2'))
            ->willRespond(200, self::pageJson([self::TX], null))
            ->willRespond(200, self::pageJson([self::TX], null));

        self::assertCount(2, iterator_to_array($this->transactions->allForBankAccount(self::BANK_ACCOUNT)));
        self::assertCount(1, iterator_to_array($this->transactions->allForCodiQr('AB12CD34EF')));
        self::assertArrayHasKey(2, $this->http->requests);
        self::assertArrayHasKey(1, $this->http->requests);
        self::assertSame('https://api.pey.mx/transaction/bank-account/' . self::BANK_ACCOUNT . '?nextToken=c2', (string) $this->http->requests[1]->getUri());
        self::assertSame('https://api.pey.mx/transaction/codi/qr/AB12CD34EF', (string) $this->http->requests[2]->getUri());
    }

    /**
     * @return iterable<string, array{\Closure(Transactions): mixed, string}>
     */
    public static function provideInvalidArguments(): iterable
    {
        yield 'transaction id is not a ULID' => [static fn(Transactions $t): mixed => $t->get('AB12CD34EF'), 'id'];
        yield 'empty cursor' => [static fn(Transactions $t): mixed => $t->listForApiKey(''), 'nextToken'];
        yield 'cursor with a space' => [static fn(Transactions $t): mixed => $t->listForApiKey('a b'), 'nextToken'];
        yield 'bank account id is not a ULID' => [static fn(Transactions $t): mixed => $t->listForBankAccount('../api-key'), 'bankAccountId'];
        yield 'empty QR id' => [static fn(Transactions $t): mixed => $t->listForCodiQr(''), 'codiQrId'];
        yield 'QR id with a line break' => [static fn(Transactions $t): mixed => $t->listForCodiQr("AB12\nCD"), 'codiQrId'];
        yield 'oversized QR id' => [static fn(Transactions $t): mixed => $t->listForCodiQr(str_repeat('A', 256)), 'codiQrId'];
        yield 'all for a bad bank account' => [static fn(Transactions $t): mixed => $t->allForBankAccount('nope'), 'bankAccountId'];
        yield 'all for a bad QR id' => [static fn(Transactions $t): mixed => $t->allForCodiQr(' '), 'codiQrId'];
    }

    /**
     * @param \Closure(Transactions): mixed $call
     */
    #[DataProvider('provideInvalidArguments')]
    public function testInvalidArgumentsAreRejectedEagerlyWithoutRequests(\Closure $call, string $field): void
    {
        try {
            // Not iterated: all*() must validate when called, not on first iteration.
            $call($this->transactions);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->errors()));
        }

        self::assertSame([], $this->http->requests);
    }
}
