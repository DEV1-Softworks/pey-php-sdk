<?php

declare(strict_types=1);

namespace Dev1\Pey\Idempotency;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RequestBody;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\IdempotencyInProgressException;
use Dev1\Pey\Exception\IdempotencyKeyReusedException;
use Dev1\Pey\Exception\IdempotencyOutcomeUnknownException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Violations;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Makes charge-creating requests idempotent: for each key, Pey is called at
 * most once.
 *
 * Pey has no idempotency keys, so a double click, a queue retry or a retry
 * after a timeout would otherwise create a second charge. Each call:
 *
 * 1. atomically acquires the key in the store, recording a fingerprint of the
 *    operation and request. If the key is already taken:
 *    - same request, completed → the stored result is returned and nothing is sent;
 *    - same request, still in progress → IdempotencyInProgressException;
 *    - same request, outcome unknown or stale → IdempotencyOutcomeUnknownException;
 *    - different request → IdempotencyKeyReusedException;
 * 2. sends the request once;
 * 3. on success, stores the result for replay;
 * 4. on failure, releases the key only if Pey certainly did not act (local
 *    validation, or a 4xx from Pey). Any other failure (a network or HTTP
 *    client error of any kind, a 5xx, an unreadable response, an unexpected
 *    error) keeps the key blocked as "outcome unknown" until you reconcile
 *    it and call release().
 *
 * Every doubtful case blocks: a corrupt record, a store that cannot be read,
 * or a stale in-progress attempt. Keys are hashed before they reach the store.
 */
final class IdempotentRequests
{
    /**
     * How long results and blocks are kept: 7 days.
     */
    public const DEFAULT_RETENTION_SECONDS = 604_800;

    /**
     * After this long, an attempt that never finished is treated as a crash
     * whose outcome is unknown. Must exceed your HTTP timeout.
     */
    public const DEFAULT_STALE_AFTER_SECONDS = 300;

    private const KEY_FIELD = 'idempotencyKey';

    private const MAX_KEY_LENGTH = 255;

    /**
     * @param int $retentionSeconds  how long results are replayed and unknown outcomes stay blocked.
     *                               Keep it longer than any retry of your jobs or users.
     * @param int $staleAfterSeconds an attempt older than this that never finished is treated as
     *                               "outcome unknown". Keep it longer than your HTTP timeout.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        private readonly PeyClientInterface $pey,
        private readonly IdempotencyStore $store,
        private readonly int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
        private readonly int $staleAfterSeconds = self::DEFAULT_STALE_AFTER_SECONDS,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($staleAfterSeconds < 60 || $retentionSeconds < $staleAfterSeconds) {
            throw ConfigurationException::because('Idempotency requires staleAfterSeconds >= 60 and retentionSeconds >= staleAfterSeconds.');
        }
    }

    /**
     * Creates a CoDi QR at most once per key.
     *
     * @param string $idempotencyKey your key for this charge, for example "order-12345:qr"
     *
     * @throws PeyException
     */
    public function createQr(string $idempotencyKey, CreateQrRequest $request): QrCode
    {
        return $this->run(
            Operation::CodiQr,
            $idempotencyKey,
            $request,
            fn(): QrCode => $this->pey->codi()->createQr($request),
            QrCode::fromArray(...),
            static fn(QrCode $qr): array => $qr->toArray(),
        );
    }

    /**
     * Sends a CoDi push notification at most once per key.
     *
     * @param string $idempotencyKey your key for this charge, for example "order-12345:pn"
     *
     * @throws PeyException
     */
    public function createPushNotification(string $idempotencyKey, CreatePushNotificationRequest $request): PushNotification
    {
        return $this->run(
            Operation::CodiPushNotification,
            $idempotencyKey,
            $request,
            fn(): PushNotification => $this->pey->codi()->createPushNotification($request),
            PushNotification::fromArray(...),
            static fn(PushNotification $push): array => $push->toArray(),
        );
    }

    /**
     * Creates a payment link at most once per clientReferenceId, which is the
     * idempotency key and is therefore required.
     *
     * @throws PeyException
     */
    public function createPaymentLink(CreatePaymentLinkRequest $request): CreatedPaymentLink
    {
        if ($request->clientReferenceId === null) {
            throw ValidationException::forField('clientReferenceId', 'is required for idempotent payment links: it is the idempotency key.');
        }

        return $this->run(
            Operation::PaymentLink,
            $request->clientReferenceId,
            $request,
            fn(): CreatedPaymentLink => $this->pey->paymentLinks()->create($request),
            CreatedPaymentLink::fromArray(...),
            static fn(CreatedPaymentLink $link): array => $link->toArray(),
        );
    }

    /**
     * Unblocks a key, so the next call with it sends to Pey again.
     *
     * Call it only after confirming with Pey that the earlier attempt created
     * nothing. Releasing a key whose charge exists allows a duplicate charge.
     * For payment links, the key is the clientReferenceId.
     *
     * @throws ValidationException when the key is invalid
     */
    public function release(Operation $operation, string $idempotencyKey): void
    {
        self::validateKey($idempotencyKey);
        $this->store->release(self::storeKey($operation, $idempotencyKey));
        $this->logger->notice('Pey idempotency key released by the application.', [
            'operation' => $operation->value,
            'key_hash' => self::keyHash($idempotencyKey),
        ]);
    }

    /**
     * @template T of object
     *
     * @param \Closure(): T                               $send
     * @param \Closure(array<array-key, mixed>): T        $hydrate
     * @param \Closure(T): array<string, string|null>     $dehydrate
     *
     * @return T
     *
     * @throws PeyException
     */
    private function run(Operation $operation, string $key, RequestBody $request, \Closure $send, \Closure $hydrate, \Closure $dehydrate): object
    {
        self::validateKey($key);

        $storeKey = self::storeKey($operation, $key);
        $record = IdempotencyRecord::started(self::fingerprint($operation, $request), $this->clock->now()->getTimestamp());
        $context = ['operation' => $operation->value, 'key_hash' => self::keyHash($key)];

        // A store that cannot be reached throws here, and nothing is sent.
        if (!$this->store->acquire($storeKey, $record->toJson(), $this->retentionSeconds)) {
            return $this->resolveExisting($operation, $storeKey, $record->fingerprint, $hydrate, $context);
        }

        try {
            $result = $send();
        } catch (\Throwable $e) {
            $this->recordFailure($storeKey, $record, $e, $context);

            throw $e;
        }

        try {
            $this->store->remember($storeKey, $record->completed($dehydrate($result))->toJson(), $this->retentionSeconds);
        } catch (\Throwable $e) {
            // The charge exists, so return it. The key stays "started", which
            // turns into "outcome unknown" later: it blocks, it never reopens.
            $this->logger->critical('Pey idempotency: the request succeeded but its result could not be stored; the key stays blocked.', $context + [
                'store_error' => get_debug_type($e),
            ]);
        }

        return $result;
    }

    /**
     * @template T of object
     *
     * @param \Closure(array<array-key, mixed>): T $hydrate
     * @param array<string, string>                $context
     *
     * @return T
     *
     * @throws PeyException
     */
    private function resolveExisting(Operation $operation, string $storeKey, string $fingerprint, \Closure $hydrate, array $context): object
    {
        $json = $this->store->recall($storeKey);

        if ($json === null) {
            // The record vanished between acquire() and recall(); it was just
            // released or expired. Fail closed and let the caller try again.
            throw IdempotencyInProgressException::for($operation);
        }

        $existing = IdempotencyRecord::fromJson($json);

        if ($existing === null) {
            $this->logger->error('Pey idempotency: unreadable record; treating the outcome as unknown.', $context);

            throw IdempotencyOutcomeUnknownException::for($operation, 'the stored record is unreadable');
        }

        if (!hash_equals($existing->fingerprint, $fingerprint)) {
            throw IdempotencyKeyReusedException::for($operation);
        }

        if ($existing->state === IdempotencyRecord::UNKNOWN) {
            throw IdempotencyOutcomeUnknownException::for($operation, 'the earlier attempt failed after it may have reached Pey');
        }

        if ($existing->state === IdempotencyRecord::STARTED) {
            if ($this->clock->now()->getTimestamp() - $existing->startedAt >= $this->staleAfterSeconds) {
                throw IdempotencyOutcomeUnknownException::for($operation, 'the earlier attempt never finished');
            }

            throw IdempotencyInProgressException::for($operation);
        }

        try {
            $result = $hydrate((array) $existing->response);
        } catch (UnexpectedResponseException) {
            throw IdempotencyOutcomeUnknownException::for($operation, 'the stored result is unreadable');
        }

        $this->logger->info('Pey idempotency: returning the stored result; nothing was sent.', $context);

        return $result;
    }

    /**
     * @param array<string, string> $context
     */
    private function recordFailure(string $storeKey, IdempotencyRecord $record, \Throwable $e, array $context): void
    {
        $context += ['cause' => get_debug_type($e)];

        if (self::peyCertainlyDidNotAct($e)) {
            try {
                $this->store->release($storeKey);
            } catch (\Throwable $storeError) {
                $this->logger->error('Pey idempotency: could not release the key after a failure Pey did not act on; it stays blocked.', $context + [
                    'store_error' => get_debug_type($storeError),
                ]);
            }

            return;
        }

        $this->logger->warning('Pey idempotency: the outcome is unknown; the key stays blocked until it is reconciled and released.', $context);

        try {
            $this->store->remember($storeKey, $record->unknown()->toJson(), $this->retentionSeconds);
        } catch (\Throwable $storeError) {
            // The record stays "started", which becomes "unknown" once stale.
            $this->logger->critical('Pey idempotency: could not mark the outcome as unknown; the key stays blocked as in progress.', $context + [
                'store_error' => get_debug_type($storeError),
            ]);
        }
    }

    /**
     * True only when the SDK stopped the request before sending it (local
     * validation, the bank account guard, configuration), or Pey rejected it
     * with a 4xx. Anything else may have created a charge, including every
     * network or HTTP client failure of these POSTs.
     */
    private static function peyCertainlyDidNotAct(\Throwable $e): bool
    {
        return match (true) {
            $e instanceof NetworkException => !$e->requestMayHaveBeenProcessed(),
            $e instanceof ValidationException,
            $e instanceof BankAccountNotAllowedException,
            $e instanceof ConfigurationException => true,
            $e instanceof ApiException,
            $e instanceof UnexpectedResponseException => self::isClientError($e->httpStatus()),
            default => false,
        };
    }

    private static function isClientError(?int $status): bool
    {
        return $status !== null && $status >= 400 && $status < 500;
    }

    /**
     * @throws ValidationException
     */
    private static function validateKey(string $key): void
    {
        $violations = new Violations();
        Text::checkLength($key, 1, self::MAX_KEY_LENGTH, self::KEY_FIELD, $violations);
        $violations->throwIfAny();
    }

    private static function storeKey(Operation $operation, string $key): string
    {
        return 'pey:idempotency:' . $operation->value . ':' . hash('sha256', $key);
    }

    private static function keyHash(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    /**
     * SHA-256 of the operation and the canonical request body, with object
     * keys sorted, so metadata order does not matter but every value does.
     */
    private static function fingerprint(Operation $operation, RequestBody $request): string
    {
        return hash('sha256', $operation->value . "\n" . Json::encodeObject(self::canonicalize($request->toBody())));
    }

    /**
     * Request bodies hold scalars, JsonNumber and JsonObject (metadata) values,
     * so sorting object keys at each level is enough to make them canonical.
     *
     * @param array<array-key, mixed> $members
     *
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $members): array
    {
        ksort($members, \SORT_STRING);

        foreach ($members as $name => $value) {
            if ($value instanceof JsonObject) {
                $members[$name] = new JsonObject(self::canonicalize($value->members));
            }
        }

        return $members;
    }
}
