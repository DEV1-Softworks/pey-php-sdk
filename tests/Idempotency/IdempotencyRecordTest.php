<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Idempotency;

use Dev1\Pey\Idempotency\IdempotencyRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyRecord::class)]
final class IdempotencyRecordTest extends TestCase
{
    public function testStartedRecordsRoundTrip(): void
    {
        $record = IdempotencyRecord::fromJson(IdempotencyRecord::started('fp', 1_795_000_000)->toJson());

        self::assertNotNull($record);
        self::assertSame(IdempotencyRecord::STARTED, $record->state);
        self::assertSame(1_795_000_000, $record->startedAt);
        self::assertSame('fp', $record->fingerprint);
        self::assertNull($record->response);
    }

    public function testCompletedRecordsKeepTheResponse(): void
    {
        $json = IdempotencyRecord::started('fp', 1)->completed(['id' => 'AB12CD34EF', 'qrImage' => null])->toJson();
        $record = IdempotencyRecord::fromJson($json);

        self::assertNotNull($record);
        self::assertSame(IdempotencyRecord::COMPLETED, $record->state);
        self::assertSame(['id' => 'AB12CD34EF', 'qrImage' => null], $record->response);
        self::assertSame('fp', $record->fingerprint);
    }

    public function testUnknownRecordsKeepTheFingerprintAndStart(): void
    {
        $record = IdempotencyRecord::fromJson(IdempotencyRecord::started('fp', 7)->unknown()->toJson());

        self::assertNotNull($record);
        self::assertSame(IdempotencyRecord::UNKNOWN, $record->state);
        self::assertSame(7, $record->startedAt);
        self::assertSame('fp', $record->fingerprint);
    }

    /**
     * Anything unexpected parses as null, which callers treat as "outcome unknown".
     *
     * @return iterable<string, array{string}>
     */
    public static function provideCorruptRecords(): iterable
    {
        yield 'not JSON' => ['garbage'];
        yield 'JSON scalar' => ['"started"'];
        yield 'missing version' => ['{"state":"started","startedAt":1,"fingerprint":"fp","response":null}'];
        yield 'future version' => ['{"v":2,"state":"started","startedAt":1,"fingerprint":"fp","response":null}'];
        yield 'unknown state' => ['{"v":1,"state":"done","startedAt":1,"fingerprint":"fp","response":null}'];
        yield 'string timestamp' => ['{"v":1,"state":"started","startedAt":"1","fingerprint":"fp","response":null}'];
        yield 'missing fingerprint' => ['{"v":1,"state":"started","startedAt":1,"response":null}'];
        yield 'completed without response' => ['{"v":1,"state":"completed","startedAt":1,"fingerprint":"fp","response":null}'];
        yield 'completed with an empty response' => ['{"v":1,"state":"completed","startedAt":1,"fingerprint":"fp","response":{}}'];
        yield 'completed with a list response' => ['{"v":1,"state":"completed","startedAt":1,"fingerprint":"fp","response":["x"]}'];
        yield 'completed with a nested value' => ['{"v":1,"state":"completed","startedAt":1,"fingerprint":"fp","response":{"id":{"x":1}}}'];
        yield 'completed with a number' => ['{"v":1,"state":"completed","startedAt":1,"fingerprint":"fp","response":{"id":5}}'];
        yield 'started with a response' => ['{"v":1,"state":"started","startedAt":1,"fingerprint":"fp","response":{"id":"x"}}'];
        yield 'too deep' => ['{"v":1,"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":{}}}}}}}}}'];
    }

    #[DataProvider('provideCorruptRecords')]
    public function testCorruptRecordsAreRejected(string $json): void
    {
        self::assertNull(IdempotencyRecord::fromJson($json));
    }
}
