<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Dto;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Money\Amount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Payload::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Json::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class PayloadTest extends TestCase
{
    private static function payload(string $json): Payload
    {
        return new Payload(Json::decode($json), 'Thing');
    }

    public function testStrings(): void
    {
        $payload = self::payload('{"id":"abc","none":null,"number":123}');

        self::assertSame('abc', $payload->string('id'));
        self::assertNull($payload->optionalString('missing'));
        self::assertNull($payload->optionalString('none'));
        // JSON numbers arrive as decimal strings and are accepted as strings.
        self::assertSame('123', $payload->string('number'));
        self::assertTrue($payload->has('id'));
        self::assertFalse($payload->has('none'), 'null counts as absent');
        self::assertFalse($payload->has('missing'));
    }

    public function testIntegers(): void
    {
        $payload = self::payload('{"ts":1704067200,"negative":-5,"none":null}');

        self::assertSame(1704067200, $payload->int('ts'));
        self::assertSame(-5, $payload->int('negative'));
        self::assertNull($payload->optionalInt('none'));
        self::assertSame(7, (new Payload(['n' => 7], 'Thing'))->int('n'));
    }

    public function testBooleans(): void
    {
        $payload = self::payload('{"yes":true,"no":false}');

        self::assertTrue($payload->bool('yes'));
        self::assertFalse($payload->bool('no'));
    }

    public function testAmountsNeverPassThroughAFloat(): void
    {
        $payload = self::payload('{"amount":118.5,"base":100,"none":null}');

        self::assertSame(11850, $payload->amount('amount')->centavos());
        self::assertSame(10000, $payload->amount('base')->centavos());
        self::assertNull($payload->optionalAmount('none'));
        // Hand-built arrays may carry integer pesos.
        self::assertSame(200, (new Payload(['amount' => 2], 'Thing'))->amount('amount')->centavos());
    }

    public function testTimestampsAreUtcDates(): void
    {
        $date = self::payload('{"dateCreated":1704067200}')->timestamp('dateCreated');

        self::assertSame('2024-01-01T00:00:00+00:00', $date->format(\DATE_ATOM));
        self::assertNull(self::payload('{}')->optionalTimestamp('datePaid'));
    }

    public function testNestedObjectsKeepTheirPath(): void
    {
        $nested = self::payload('{"breakdown":{"fee":"oops"}}')->object('breakdown');

        try {
            $nested->amount('fee');
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame('Unexpected Pey response: Thing field "breakdown.fee" must be a non-negative decimal amount.', $e->getMessage());
        }
    }

    public function testListsOfObjectsKeepTheirIndex(): void
    {
        $items = self::payload('{"items":[{"id":"a"},{"id":5},{}]}')->objectList('items');

        self::assertCount(3, $items);
        self::assertSame('a', $items[0]->string('id'));
        self::assertSame('5', $items[1]->string('id'));

        try {
            $items[2]->string('id');
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringContainsString('"items[2].id" must be a string', $e->getMessage());
        }
    }

    public function testAbsentListsAndMapsAreEmpty(): void
    {
        $payload = self::payload('{"nothing":null}');

        self::assertSame([], $payload->objectList('items'));
        self::assertSame([], $payload->stringList('paymentMethods'));
        self::assertSame([], $payload->stringMap('metadata'));
        self::assertSame([], $payload->stringList('nothing'));
        self::assertNull($payload->optionalObject('nothing'));
    }

    public function testStringLists(): void
    {
        self::assertSame(['CODI', 'CARD'], self::payload('{"paymentMethods":["CODI","CARD"]}')->stringList('paymentMethods'));
    }

    public function testStringMapsFollowPeysStringConversion(): void
    {
        $map = self::payload('{"metadata":{"orderId":"12345","count":3,"gift":false,"yes":true,"none":null,"7":"seven"}}')->stringMap('metadata');

        self::assertSame(['orderId' => '12345', 'count' => '3', 'gift' => 'false', 'yes' => 'true', 'none' => 'null', '7' => 'seven'], $map);
    }

    public function testAnEmptyObjectIsAnObject(): void
    {
        self::assertFalse(self::payload('{"metadata":{}}')->object('metadata')->has('x'));
        self::assertSame([], self::payload('{"metadata":{}}')->stringMap('metadata'));
    }

    /**
     * @return iterable<string, array{string, \Closure(Payload): mixed, string}>
     */
    public static function provideInvalidFields(): iterable
    {
        yield 'missing string' => ['{}', static fn(Payload $p): mixed => $p->string('id'), '"id" must be a string'];
        yield 'string is a boolean' => ['{"id":true}', static fn(Payload $p): mixed => $p->string('id'), '"id" must be a string'];
        yield 'string is an object' => ['{"id":{}}', static fn(Payload $p): mixed => $p->optionalString('id'), '"id" must be a string'];
        yield 'missing integer' => ['{}', static fn(Payload $p): mixed => $p->int('ts'), '"ts" must be an integer'];
        yield 'decimal integer' => ['{"ts":1.5}', static fn(Payload $p): mixed => $p->int('ts'), '"ts" must be an integer'];
        yield 'integer from text' => ['{"ts":"soon"}', static fn(Payload $p): mixed => $p->int('ts'), '"ts" must be an integer'];
        yield 'integer is a boolean' => ['{"ts":true}', static fn(Payload $p): mixed => $p->int('ts'), '"ts" must be an integer'];
        yield 'integer overflow' => ['{"ts":99999999999999999999}', static fn(Payload $p): mixed => $p->int('ts'), '"ts" must be an integer'];
        yield 'missing boolean' => ['{}', static fn(Payload $p): mixed => $p->bool('hasTip'), '"hasTip" must be a boolean'];
        yield 'boolean as string' => ['{"hasTip":"false"}', static fn(Payload $p): mixed => $p->bool('hasTip'), '"hasTip" must be a boolean'];
        yield 'missing amount' => ['{}', static fn(Payload $p): mixed => $p->amount('amount'), '"amount" must be a decimal amount'];
        yield 'negative amount' => ['{"amount":-1}', static fn(Payload $p): mixed => $p->amount('amount'), '"amount" must be a non-negative decimal amount'];
        yield 'exponent amount' => ['{"amount":1e3}', static fn(Payload $p): mixed => $p->amount('amount'), '"amount" must be a non-negative decimal amount'];
        yield 'boolean amount' => ['{"amount":true}', static fn(Payload $p): mixed => $p->amount('amount'), '"amount" must be a decimal amount'];
        yield 'missing timestamp' => ['{}', static fn(Payload $p): mixed => $p->timestamp('datePaid'), '"datePaid" must be a Unix timestamp'];
        yield 'negative timestamp' => ['{"datePaid":-1}', static fn(Payload $p): mixed => $p->timestamp('datePaid'), '"datePaid" must be a Unix timestamp'];
        yield 'missing object' => ['{}', static fn(Payload $p): mixed => $p->object('metadata'), '"metadata" must be an object'];
        yield 'object is a list' => ['{"metadata":[1]}', static fn(Payload $p): mixed => $p->object('metadata'), '"metadata" must be an object'];
        yield 'object is a string' => ['{"metadata":"x"}', static fn(Payload $p): mixed => $p->optionalObject('metadata'), '"metadata" must be an object'];
        yield 'list is an object' => ['{"items":{"a":1}}', static fn(Payload $p): mixed => $p->objectList('items'), '"items" must be a list of objects'];
        yield 'list item is a scalar' => ['{"items":[1]}', static fn(Payload $p): mixed => $p->objectList('items'), '"items[0]" must be an object'];
        yield 'list item is a list' => ['{"items":[[1]]}', static fn(Payload $p): mixed => $p->objectList('items'), '"items[0]" must be an object'];
        yield 'string list item is an object' => ['{"paymentMethods":["CODI",{}]}', static fn(Payload $p): mixed => $p->stringList('paymentMethods'), '"paymentMethods[1]" must be a string'];
        yield 'string list is a string' => ['{"paymentMethods":"CODI"}', static fn(Payload $p): mixed => $p->stringList('paymentMethods'), '"paymentMethods" must be a list of strings'];
        yield 'map is a list' => ['{"metadata":["a"]}', static fn(Payload $p): mixed => $p->stringMap('metadata'), '"metadata" must be an object'];
        yield 'map value is nested' => ['{"metadata":{"a":{"b":1}}}', static fn(Payload $p): mixed => $p->stringMap('metadata'), '"metadata.a" must be a string'];
    }

    /**
     * @param \Closure(Payload): mixed $read
     */
    #[DataProvider('provideInvalidFields')]
    public function testInvalidFieldsAreReportedWithTheirPath(string $json, \Closure $read, string $expected): void
    {
        try {
            $read(self::payload($json));
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringStartsWith('Unexpected Pey response: Thing field ', $e->getMessage());
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertNull($e->httpStatus());
        }
    }

    public function testErrorsNeverEchoTheValue(): void
    {
        try {
            self::payload('{"clabe":{"value":"012180001234567899"}}')->string('clabe');
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertStringNotContainsString('012180001234567899', $e->getMessage());
        }
    }
}
