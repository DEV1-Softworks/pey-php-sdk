<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\RateLimitedException;
use Dev1\Pey\Exception\ServerException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\FakePsr18Exceptions;
use Dev1\Pey\Tests\Support\FrozenClock;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\RecordingLogger;
use Dev1\Pey\Tests\Support\RecordingSleeper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Retry behavior of the transport. Nothing here really sleeps: waits are
 * recorded, the clock is frozen and jitter is seeded.
 */
#[CoversClass(Transport::class)]
#[UsesClass(RetryPolicy::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(NetworkException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(UnexpectedResponseException::class)]
final class TransportRetryTest extends TestCase
{
    private MockHttpClient $http;

    private RecordingSleeper $sleeper;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->sleeper = new RecordingSleeper();
        $this->logger = new RecordingLogger();
    }

    private function transport(RetryPolicy $policy = new RetryPolicy()): Transport
    {
        $factory = new Psr17Factory();

        return new Transport(
            new Config('pey_test_not_a_real_value', retryPolicy: $policy),
            $this->http,
            $factory,
            $factory,
            $this->logger,
            $this->sleeper,
            new Randomizer(new Mt19937(7)),
            new FrozenClock('2026-11-20T12:00:00+00:00'),
        );
    }

    private static function unavailable(): string
    {
        return '{"error":{"message":"This endpoint is under maintenance, please try again later","type":"SERVICE_UNAVAILABLE","requestId":"r"}}';
    }

    public function testAGetIsRetriedAfter503AndSucceeds(): void
    {
        $this->http->willRespond(503, self::unavailable())->willRespond(503, self::unavailable())->willRespond(200, '{"ok":true}');

        self::assertSame(['ok' => true], $this->transport()->get('/transaction/api-key'));
        self::assertCount(3, $this->http->requests);
        self::assertCount(2, $this->sleeper->sleeps);
        self::assertGreaterThanOrEqual(250, $this->sleeper->sleeps[0]);
        self::assertLessThanOrEqual(500, $this->sleeper->sleeps[0]);
        self::assertGreaterThanOrEqual(500, $this->sleeper->sleeps[1]);
        self::assertLessThanOrEqual(1_000, $this->sleeper->sleeps[1]);
    }

    public function testRetryAfterIsRespected(): void
    {
        $this->http->willRespond(429, '', ['Retry-After' => '3'])->willRespond(200, '{}');

        $this->transport()->get('/transaction/api-key');

        self::assertSame([3_000], $this->sleeper->sleeps);
    }

    public function testAnHttpDateRetryAfterUsesTheClock(): void
    {
        $this->http->willRespond(503, '', ['Retry-After' => 'Fri, 20 Nov 2026 12:00:04 GMT'])->willRespond(200, '{}');

        $this->transport()->get('/transaction/api-key');

        self::assertSame([4_000], $this->sleeper->sleeps);
    }

    public function testARetryAfterLongerThanTheMaximumIsNotWaitedFor(): void
    {
        $this->http->willRespond(429, '', ['Retry-After' => '120']);

        try {
            $this->transport()->get('/transaction/api-key');
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $e) {
            self::assertSame('120', $e->retryAfter());
        }

        self::assertSame([], $this->sleeper->sleeps);
        self::assertCount(1, $this->http->requests);
    }

    public function testRetriesAreBoundedByMaxRetries(): void
    {
        $this->http->willRespond(503, '')->willRespond(503, '')->willRespond(503, '')->willRespond(200, '{}');

        try {
            $this->transport()->get('/transaction/api-key');
            self::fail('Expected a ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
        }

        self::assertCount(3, $this->http->requests, 'One attempt plus the default two retries.');
        self::assertCount(2, $this->sleeper->sleeps);
    }

    public function testMaxRetriesIsConfigurable(): void
    {
        $this->http->willRespond(503, '')->willRespond(503, '')->willRespond(503, '')->willRespond(503, '')->willRespond(200, '{}');

        $this->transport(new RetryPolicy(maxRetries: 4))->get('/transaction/api-key');

        self::assertCount(5, $this->http->requests);
    }

    public function testRetriesCanBeDisabled(): void
    {
        $this->http->willRespond(503, '');

        try {
            $this->transport(RetryPolicy::disabled())->get('/transaction/api-key');
            self::fail('Expected a ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testATransientNetworkErrorOnAGetIsRetried(): void
    {
        $this->http
            ->willReturn(FakePsr18Exceptions::network('Connection reset', new Request('GET', '/')))
            ->willRespond(200, '{"ok":true}');

        self::assertSame(['ok' => true], $this->transport()->get('/transaction/api-key'));
        self::assertCount(2, $this->http->requests);
    }

    public function testARequestTheClientRejectedIsNotRetried(): void
    {
        $this->http->willReturn(FakePsr18Exceptions::request('Invalid URI', new Request('GET', '/')));

        try {
            $this->transport()->get('/transaction/api-key');
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertFalse($e->isTransient());
        }

        self::assertCount(1, $this->http->requests);
    }

    /**
     * @return iterable<string, array{ResponseInterface|\Throwable}>
     */
    public static function provideRetryableFailures(): iterable
    {
        $factory = new Psr17Factory();

        yield 'HTTP 503' => [$factory->createResponse(503)];
        yield 'HTTP 429' => [$factory->createResponse(429)];
        yield '429 with Retry-After 0' => [$factory->createResponse(429)->withHeader('Retry-After', '0')];
        yield 'timeout' => [FakePsr18Exceptions::network('Operation timed out', new Request('POST', '/'))];
        yield 'connection refused' => [FakePsr18Exceptions::network('Connection refused', new Request('POST', '/'))];
    }

    /**
     * The core rule: Pey has no idempotency keys, so a POST is sent exactly
     * once whatever happens. A retry could create a second charge.
     */
    #[DataProvider('provideRetryableFailures')]
    public function testAPostIsNeverRetried(ResponseInterface|\Throwable $failure): void
    {
        $this->http->willReturn($failure)->willRespond(201, '{"id":"AB12CD34EF","qrString":"x"}');

        try {
            $this->transport(new RetryPolicy(maxRetries: 10))->post('/codi/qr', ['amount' => new JsonNumber('150.00')]);
            self::fail('Expected a PeyException.');
        } catch (PeyException) {
        }

        self::assertCount(1, $this->http->requests);
        self::assertSame([], $this->sleeper->sleeps);
    }

    /**
     * @return iterable<string, array{int, class-string<PeyException>}>
     */
    public static function provideNonRetryableStatuses(): iterable
    {
        yield 'HTTP 400' => [400, ValidationException::class];
        yield 'HTTP 404' => [404, NotFoundException::class];
        yield 'HTTP 500' => [500, ServerException::class];
        yield 'HTTP 502' => [502, ServerException::class];
        yield 'HTTP 504' => [504, ServerException::class];
    }

    /**
     * @param class-string<PeyException> $expected
     */
    #[DataProvider('provideNonRetryableStatuses')]
    public function testOtherErrorsAreNotRetried(int $status, string $expected): void
    {
        $this->http->willRespond($status, '')->willRespond(200, '{}');

        try {
            $this->transport()->get('/transaction/api-key');
            self::fail('Expected an exception.');
        } catch (PeyException $e) {
            self::assertInstanceOf($expected, $e);
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testAMalformedSuccessIsNotRetried(): void
    {
        $this->http->willRespond(200, '<html>')->willRespond(200, '{}');

        try {
            $this->transport()->get('/transaction/api-key');
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException) {
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testEachRetryIsLoggedWithoutSecrets(): void
    {
        $this->http
            ->willRespond(503, '')
            ->willReturn(FakePsr18Exceptions::network('Bearer pey_test_not_a_real_value', new Request('GET', '/')))
            ->willRespond(200, '{}');

        $this->transport()->get('/transaction/{id}', ['id' => '01M3QR8H0RH0XBCKYYD242YXNV']);

        $retries = array_values(array_filter(
            $this->logger->recordsAt('warning'),
            static fn(array $record): bool => str_contains($record['message'], 'retrying'),
        ));
        self::assertCount(2, $retries);
        self::assertArrayHasKey(1, $this->sleeper->sleeps);
        $base = ['method' => 'GET', 'path' => '/transaction/{id}'];
        self::assertSame([
            $base + ['reason' => 'HTTP 503', 'delay_ms' => $this->sleeper->sleeps[0], 'retry' => 1, 'max_retries' => 2],
            $base + ['reason' => 'network error', 'delay_ms' => $this->sleeper->sleeps[1], 'retry' => 2, 'max_retries' => 2],
        ], array_map(static fn(array $record): array => $record['context'], $retries));
        self::assertStringNotContainsString('pey_test_not_a_real_value', print_r($this->logger->records, true));
    }

    public function testEveryAttemptIsAFreshlyAuthenticatedRequest(): void
    {
        $this->http->willRespond(503, '')->willRespond(200, '{}');

        $this->transport()->get('/transaction/api-key', [], ['nextToken' => 'cursor-2']);

        self::assertCount(2, $this->http->requests);
        foreach ($this->http->requests as $request) {
            self::assertSame('Bearer pey_test_not_a_real_value', $request->getHeaderLine('Authorization'));
            self::assertSame('https://api.pey.mx/transaction/api-key?nextToken=cursor-2', (string) $request->getUri());
        }
    }
}
