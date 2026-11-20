<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

use Dev1\Pey\Exception\ConfigurationException;
use Random\Randomizer;

/**
 * When and how long to wait before retrying a request.
 *
 * Only **GET** requests are ever retried. Pey has no idempotency keys, so a
 * retried POST could create a second charge, payment link or bank account.
 * GETs are retried only after a transient network error, HTTP 429 or HTTP
 * 503. A 500, any other 4xx, or a request the HTTP client rejected as invalid
 * is not retried.
 *
 * The delay before retry n is exponential with equal jitter: half of
 * min(maxDelayMs, baseDelayMs × 2^(n−1)) plus a random amount up to the other
 * half. Each retry therefore waits at least half its backoff, and concurrent
 * clients spread out. A `Retry-After` header (seconds or an HTTP date)
 * replaces the computed delay. When it asks for longer than maxDelayMs, the
 * request is not retried and the error is returned to the caller, whose
 * exception exposes retryAfter().
 */
final readonly class RetryPolicy
{
    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_BASE_DELAY_MS = 500;

    public const DEFAULT_MAX_DELAY_MS = 10_000;

    private const MAX_RETRIES_LIMIT = 10;

    private const MAX_DELAY_LIMIT_MS = 60_000;

    /**
     * @param int $maxRetries  retries after the first attempt; 0 disables retries
     * @param int $baseDelayMs backoff before the first retry
     * @param int $maxDelayMs  upper bound for any wait, including Retry-After
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        public int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        public int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {
        if ($maxRetries < 0 || $maxRetries > self::MAX_RETRIES_LIMIT) {
            throw ConfigurationException::because(\sprintf('maxRetries must be between 0 and %d.', self::MAX_RETRIES_LIMIT));
        }

        if ($baseDelayMs < 1 || $maxDelayMs < $baseDelayMs || $maxDelayMs > self::MAX_DELAY_LIMIT_MS) {
            throw ConfigurationException::because(\sprintf(
                'Retry delays must satisfy 1 <= baseDelayMs <= maxDelayMs <= %d.',
                self::MAX_DELAY_LIMIT_MS,
            ));
        }
    }

    public static function disabled(): self
    {
        return new self(0);
    }

    /**
     * Only GET requests are safe to retry.
     */
    public function allowsMethod(string $method): bool
    {
        return $method === 'GET';
    }

    public function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 503;
    }

    /**
     * The wait before retry number $retry (1-based), or null when no retry
     * should happen (retries exhausted, or Retry-After asks for longer than
     * maxDelayMs).
     */
    public function delayBeforeRetry(int $retry, ?string $retryAfter, \DateTimeImmutable $now, Randomizer $random): ?int
    {
        if ($retry < 1 || $retry > $this->maxRetries) {
            return null;
        }

        $requested = $retryAfter === null ? null : self::parseRetryAfter($retryAfter, $now);
        if ($requested !== null) {
            return $requested <= $this->maxDelayMs ? $requested : null;
        }

        // Exponent capped so the multiplication cannot overflow.
        $backoff = min($this->maxDelayMs, $this->baseDelayMs * (2 ** min($retry - 1, 20)));
        $half = intdiv($backoff, 2);

        return $backoff - $half + $random->getInt(0, $half);
    }

    /**
     * Retry-After as milliseconds from now, or null when it is absent or
     * malformed. Delta-seconds and IMF-fixdate (RFC 9110) are supported. A date
     * in the past means "retry now".
     */
    private static function parseRetryAfter(string $value, \DateTimeImmutable $now): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,9}$/D', $value) === 1) {
            return (int) $value * 1000;
        }

        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        $milliseconds = ($date->getTimestamp() - $now->getTimestamp()) * 1000;

        return max(0, $milliseconds);
    }
}
