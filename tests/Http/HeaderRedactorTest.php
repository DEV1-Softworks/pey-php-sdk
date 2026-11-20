<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Http\HeaderRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderRedactor::class)]
final class HeaderRedactorTest extends TestCase
{
    public function testItRedactsTheBearerTokenAndKeepsTheScheme(): void
    {
        $redacted = HeaderRedactor::redact(['Authorization' => ['Bearer pey_test_not_a_real_value']]);

        self::assertSame(['Authorization' => ['Bearer [REDACTED]']], $redacted);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideSensitiveHeaders(): iterable
    {
        yield 'lower-case authorization' => ['authorization', 'Bearer abc', 'Bearer [REDACTED]'];
        yield 'upper-case authorization' => ['AUTHORIZATION', 'Basic dXNlcjpwYXNz', 'Basic [REDACTED]'];
        yield 'bare token' => ['Authorization', 'pey_test_not_a_real_value', '[REDACTED]'];
        yield 'scheme only' => ['Authorization', 'Bearer', '[REDACTED]'];
        yield 'proxy authorization' => ['Proxy-Authorization', 'Basic abc', 'Basic [REDACTED]'];
        yield 'cookie' => ['Cookie', 'session=abc', '[REDACTED]'];
        yield 'set-cookie' => ['Set-Cookie', 'session=abc; Secure', '[REDACTED]'];
    }

    #[DataProvider('provideSensitiveHeaders')]
    public function testItRedactsEverySensitiveHeader(string $name, string $value, string $expected): void
    {
        self::assertSame([$name => [$expected]], HeaderRedactor::redact([$name => [$value]]));
    }

    public function testItKeepsOtherHeadersAndAllValues(): void
    {
        $headers = [
            'Accept' => ['application/json'],
            'User-Agent' => ['dev1-pey-php-sdk/0.1.0'],
            'Authorization' => ['Bearer one', 'Bearer two'],
        ];

        self::assertSame([
            'Accept' => ['application/json'],
            'User-Agent' => ['dev1-pey-php-sdk/0.1.0'],
            'Authorization' => ['Bearer [REDACTED]', 'Bearer [REDACTED]'],
        ], HeaderRedactor::redact($headers));
    }
}
