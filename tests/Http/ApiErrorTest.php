<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\Json;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiError::class)]
#[UsesClass(Json::class)]
final class ApiErrorTest extends TestCase
{
    public function testItParsesTheDocumentedErrorFormat(): void
    {
        $error = ApiError::fromResponseBody(
            '{"error":{"message":"Amount must be greater than 0","type":"INVALID_AMOUNT","requestId":"abc123-def456-ghi789"}}',
        );

        self::assertSame('INVALID_AMOUNT', $error->type);
        self::assertSame('Amount must be greater than 0', $error->message);
        self::assertSame('abc123-def456-ghi789', $error->requestId);
    }

    public function testANullRequestIdIsAllowed(): void
    {
        // Documented for "Error getting request ID" responses.
        $error = ApiError::fromResponseBody('{"error":{"message":"Error getting request ID","type":"INTERNAL_SERVER_ERROR","requestId":null}}');

        self::assertSame('INTERNAL_SERVER_ERROR', $error->type);
        self::assertNull($error->requestId);
    }

    public function testMissingFieldsFallBackToSafeDefaults(): void
    {
        $error = ApiError::fromResponseBody('{"error":{}}');

        self::assertSame(ApiError::UNKNOWN_TYPE, $error->type);
        self::assertSame('', $error->message);
        self::assertNull($error->requestId);
    }

    public function testNonStringFieldsAreIgnored(): void
    {
        $error = ApiError::fromResponseBody('{"error":{"message":["x"],"type":{"a":1},"requestId":true}}');

        self::assertSame(ApiError::UNKNOWN_TYPE, $error->type);
        self::assertSame('', $error->message);
        self::assertNull($error->requestId);
    }

    public function testAGatewayStyleBodyKeepsItsTopLevelMessage(): void
    {
        $error = ApiError::fromResponseBody('{"message":"Unauthorized"}');

        self::assertSame(ApiError::UNKNOWN_TYPE, $error->type);
        self::assertSame('Unauthorized', $error->message);
        self::assertNull($error->requestId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnparseableBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'html' => ['<html><body>502 Bad Gateway</body></html>'];
        yield 'json scalar' => ['"oops"'];
        yield 'json list' => ['[1,2]'];
    }

    #[DataProvider('provideUnparseableBodies')]
    public function testItNeverThrowsOnUnparseableBodies(string $body): void
    {
        $error = ApiError::fromResponseBody($body);

        self::assertSame(ApiError::UNKNOWN_TYPE, $error->type);
        self::assertSame('', $error->message);
        self::assertNull($error->requestId);
    }

    public function testControlCharactersAreRemovedToPreventLogInjection(): void
    {
        $error = ApiError::fromResponseBody(Json::encodeObject([
            'error' => ['message' => "bad\r\n[CRITICAL] forged log line\u{2028}x", 'type' => "INVALID\nAMOUNT"],
        ]));

        self::assertSame('bad [CRITICAL] forged log line x', $error->message);
        self::assertSame('INVALID AMOUNT', $error->type);
    }

    public function testLongMessagesAreTruncatedWithoutBreakingUtf8(): void
    {
        $error = ApiError::fromResponseBody(Json::encodeObject(['error' => ['message' => str_repeat('é', 600), 'type' => 'X']]));

        self::assertSame(str_repeat('é', 500) . '…', $error->message);
        self::assertSame(1, preg_match('//u', $error->message));
    }

    public function testNumericRequestIdsAreKeptAsStrings(): void
    {
        $error = ApiError::fromResponseBody('{"error":{"type":"X","message":"m","requestId":12345}}');

        self::assertSame('12345', $error->requestId);
    }
}
