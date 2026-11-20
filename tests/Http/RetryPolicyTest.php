<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Http\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(RetryPolicy::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(PeyException::class)]
final class RetryPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-20T12:00:00+00:00');
    }

    private static function random(): Randomizer
    {
        return new Randomizer(new Mt19937(42));
    }

    public function testDefaults(): void
    {
        $policy = new RetryPolicy();

        self::assertSame(2, $policy->maxRetries);
        self::assertSame(500, $policy->baseDelayMs);
        self::assertSame(10_000, $policy->maxDelayMs);
    }

    public function testOnlyGetIsEverRetried(): void
    {
        $policy = new RetryPolicy();

        self::assertTrue($policy->allowsMethod('GET'));
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'get', 'HEAD'] as $method) {
            self::assertFalse($policy->allowsMethod($method), $method);
        }
    }

    public function testOnly429And503AreRetryableStatuses(): void
    {
        $policy = new RetryPolicy();

        self::assertTrue($policy->isRetryableStatus(429));
        self::assertTrue($policy->isRetryableStatus(503));
        foreach ([400, 401, 403, 404, 409, 500, 502, 504] as $status) {
            self::assertFalse($policy->isRetryableStatus($status), (string) $status);
        }
    }

    public function testBackoffGrowsExponentiallyWithEqualJitter(): void
    {
        $policy = new RetryPolicy(maxRetries: 5, baseDelayMs: 100, maxDelayMs: 1_000);
        $random = self::random();

        // Retry n waits between half and all of min(1000, 100 * 2^(n-1)).
        $expectedRanges = [1 => [50, 100], 2 => [100, 200], 3 => [200, 400], 4 => [400, 800], 5 => [500, 1_000]];

        foreach ($expectedRanges as $retry => [$min, $max]) {
            for ($sample = 0; $sample < 200; ++$sample) {
                $delay = $policy->delayBeforeRetry($retry, null, $this->now, $random);
                self::assertNotNull($delay);
                self::assertGreaterThanOrEqual($min, $delay, "retry {$retry}");
                self::assertLessThanOrEqual($max, $delay, "retry {$retry}");
            }
        }
    }

    public function testJitterActuallyVaries(): void
    {
        $policy = new RetryPolicy(maxRetries: 1, baseDelayMs: 1_000, maxDelayMs: 1_000);
        $random = self::random();

        $delays = [];
        for ($i = 0; $i < 50; ++$i) {
            $delays[] = $policy->delayBeforeRetry(1, null, $this->now, $random);
        }

        self::assertGreaterThan(10, \count(array_unique($delays)));
    }

    public function testNoDelayOnceRetriesAreExhausted(): void
    {
        $policy = new RetryPolicy(maxRetries: 2);

        self::assertNotNull($policy->delayBeforeRetry(2, null, $this->now, self::random()));
        self::assertNull($policy->delayBeforeRetry(3, null, $this->now, self::random()));
        self::assertNull($policy->delayBeforeRetry(0, null, $this->now, self::random()));
    }

    public function testADisabledPolicyNeverRetries(): void
    {
        self::assertNull(RetryPolicy::disabled()->delayBeforeRetry(1, null, $this->now, self::random()));
        self::assertSame(0, RetryPolicy::disabled()->maxRetries);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function provideRetryAfter(): iterable
    {
        yield 'seconds' => ['2', 2_000];
        yield 'seconds with spaces' => [' 3 ', 3_000];
        yield 'zero' => ['0', 0];
        yield 'at the limit' => ['10', 10_000];
        yield 'beyond the limit means do not retry' => ['11', null];
        yield 'HTTP date in 5 seconds' => ['Fri, 20 Nov 2026 12:00:05 GMT', 5_000];
        yield 'HTTP date in the past means now' => ['Fri, 20 Nov 2026 11:59:00 GMT', 0];
        yield 'HTTP date too far ahead' => ['Fri, 20 Nov 2026 13:00:00 GMT', null];
    }

    #[DataProvider('provideRetryAfter')]
    public function testRetryAfterReplacesTheBackoff(string $retryAfter, ?int $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->delayBeforeRetry(1, $retryAfter, $this->now, self::random()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedRetryAfter(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'decimal' => ['1.5'];
        yield 'words' => ['soon'];
        yield 'too many digits' => ['9999999999'];
        yield 'RFC 850 date' => ['Friday, 20-Nov-26 12:00:05 GMT'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideMalformedRetryAfter')]
    public function testAMalformedRetryAfterFallsBackToBackoff(string $retryAfter): void
    {
        $delay = (new RetryPolicy(baseDelayMs: 100, maxDelayMs: 100))->delayBeforeRetry(1, $retryAfter, $this->now, self::random());

        self::assertNotNull($delay);
        self::assertGreaterThanOrEqual(50, $delay);
        self::assertLessThanOrEqual(100, $delay);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideInvalidSettings(): iterable
    {
        yield 'negative retries' => [-1, 500, 10_000];
        yield 'too many retries' => [11, 500, 10_000];
        yield 'zero base delay' => [2, 0, 10_000];
        yield 'base above max' => [2, 2_000, 1_000];
        yield 'max above one minute' => [2, 500, 60_001];
    }

    #[DataProvider('provideInvalidSettings')]
    public function testInvalidSettingsAreRejected(int $maxRetries, int $baseDelayMs, int $maxDelayMs): void
    {
        $this->expectException(ConfigurationException::class);

        new RetryPolicy($maxRetries, $baseDelayMs, $maxDelayMs);
    }

    public function testTheBackoffDoesNotOverflowForLargeRetryNumbers(): void
    {
        $delay = (new RetryPolicy(maxRetries: 10, baseDelayMs: 60_000, maxDelayMs: 60_000))->delayBeforeRetry(10, null, $this->now, self::random());

        self::assertNotNull($delay);
        self::assertLessThanOrEqual(60_000, $delay);
    }
}
