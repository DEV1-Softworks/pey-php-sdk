<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing\Internal;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Testing\RecordedCall;
use Psr\Clock\ClockInterface;

/**
 * In-memory state shared by the fake resources.
 *
 * @internal
 */
final class FakeState
{
    /**
     * The API key id that fake webhooks and bank accounts report.
     */
    public const API_KEY_ID = '01JFAKE0000000000000000000';

    /**
     * @var array<string, array{request: CreateQrRequest, createdAt: \DateTimeImmutable}>
     */
    public array $qrs = [];

    /**
     * @var array<string, CreatePushNotificationRequest> keyed by transaction id
     */
    public array $pushNotifications = [];

    /**
     * @var array<string, array{request: RegisterBankAccountRequest, status: CodiStatus, createdAt: \DateTimeImmutable}>
     */
    public array $bankAccounts = [];

    /**
     * @var array<string, array{request: CreatePaymentLinkRequest, amount: Amount, status: PaymentLinkStatus, createdAt: \DateTimeImmutable, transactions: list<TransactionSummary>}>
     */
    public array $paymentLinks = [];

    /**
     * @var array<string, Transaction> in creation order
     */
    public array $transactions = [];

    /**
     * @var array<string, string> transaction id => bank account id that received it
     */
    public array $transactionBankAccounts = [];

    /**
     * @var array<string, string> transaction id => QR id (IDC)
     */
    public array $transactionQrs = [];

    /**
     * @var list<RecordedCall>
     */
    public array $calls = [];

    /**
     * @var list<array{error: \Throwable, afterProcessing: bool}>
     */
    public array $failures = [];

    private int $sequence = 0;

    public function __construct(
        public readonly BankAccountGuard $guard,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Records the call, then applies a queued failure before or after running the operation.
     *
     * @template T
     *
     * @param list<mixed>   $arguments
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function call(string $method, array $arguments, \Closure $operation): mixed
    {
        $this->calls[] = new RecordedCall($method, $arguments);
        $failure = array_shift($this->failures);

        if ($failure !== null && !$failure['afterProcessing']) {
            throw $failure['error'];
        }

        $result = $operation();

        if ($failure !== null) {
            throw $failure['error'];
        }

        return $result;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
    }

    /**
     * A valid ULID such as 01JFAKE0000000000000000001.
     */
    public function nextUlid(): string
    {
        return \sprintf('01JFAKE%019d', ++$this->sequence);
    }

    /**
     * A 10-character id shaped like Banxico's IDC, such as FAKE000002.
     */
    public function nextQrId(): string
    {
        return \sprintf('FAKE%06d', ++$this->sequence);
    }

    /**
     * A 20-digit CoDi folio.
     */
    public function nextFolio(): string
    {
        return \sprintf('%020d', ++$this->sequence);
    }

    public static function notFound(string $method, string $pathTemplate, string $type): NotFoundException
    {
        return NotFoundException::fromResponse($method, $pathTemplate, 404, new ApiError($type, 'Not found (FakePeyClient).', null));
    }

    public static function rejected(string $method, string $pathTemplate, string $type, string $message): ValidationException
    {
        return ValidationException::fromApiResponse($method, $pathTemplate, new ApiError($type, $message, null));
    }

    /**
     * Metadata as Pey returns it: every value as a string.
     *
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, string>
     */
    public static function storedMetadata(array $metadata): array
    {
        $stored = [];
        foreach ($metadata as $key => $value) {
            $stored[(string) $key] = match (true) {
                $value === null => 'null',
                \is_bool($value) => $value ? 'true' : 'false',
                \is_int($value), \is_float($value) => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
                \is_string($value) => $value,
                default => throw new \LogicException('Metadata values are validated as scalars.'),
            };
        }

        return $stored;
    }

    /**
     * Paginates newest first with an opaque cursor, like Pey.
     *
     * @param list<Transaction> $items oldest first
     */
    public function page(array $items, ?string $cursor, int $pageSize, string $pathTemplate): TransactionPage
    {
        $offset = 0;
        if ($cursor !== null) {
            if (preg_match('/^fake-cursor-(\d{1,9})$/D', $cursor, $match) !== 1) {
                throw self::rejected('GET', $pathTemplate, 'INVALID_PARAMETERS', 'Invalid nextToken');
            }
            $offset = (int) $match[1];
        }

        $newestFirst = array_reverse($items);
        $slice = \array_slice($newestFirst, $offset, $pageSize);
        $next = $offset + $pageSize < \count($newestFirst) ? 'fake-cursor-' . ($offset + $pageSize) : null;

        return new TransactionPage($slice, $next);
    }
}
