<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(SecretExposureException::class)]
#[UsesClass(\Dev1\Pey\Validation\Clabe::class)]
final class ConfigTest extends TestCase
{
    private const API_KEY = 'pey_test_not_a_real_value';

    public function testDefaults(): void
    {
        $config = new Config(self::API_KEY);

        self::assertSame('https://api.pey.mx', $config->baseUrl());
        self::assertSame(5.0, $config->connectTimeout());
        self::assertSame(15.0, $config->timeout());
        self::assertSame(self::API_KEY, $config->apiKey()->reveal());
    }

    public function testNoClabeIsAllowlistedByDefault(): void
    {
        self::assertSame([], (new Config(self::API_KEY))->allowedClabes());
    }

    public function testAllowlistedClabesAreKeptOnceEach(): void
    {
        $config = new Config(self::API_KEY, allowedClabes: ['032180000118359719', '002010077777777771', '032180000118359719']);

        self::assertSame(['032180000118359719', '002010077777777771'], $config->allowedClabes());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideInvalidAllowlistEntries(): iterable
    {
        yield 'wrong check digit' => ['032180000118359710', '**************9710'];
        yield 'too short' => ['03218000011835971', '*************5971'];
        yield 'not a string' => [32180000118359719, 'int'];
    }

    #[DataProvider('provideInvalidAllowlistEntries')]
    public function testAllowlistEntriesMustBeValidClabes(mixed $entry, string $shown): void
    {
        try {
            // Through reflection: the input is deliberately outside the declared list<string>,
            // as it can be when a framework bridge reads untyped configuration.
            (new \ReflectionClass(Config::class))->newInstanceArgs([self::API_KEY, Config::DEFAULT_BASE_URL, 5.0, 15.0, [$entry]]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('allowedClabes[0] (' . $shown . ')', $e->getMessage());
            self::assertStringNotContainsString('0321800001183597', $e->getMessage());
        }
    }

    public function testItAcceptsASecret(): void
    {
        $secret = new Secret(self::API_KEY);

        self::assertSame($secret, (new Config($secret))->apiKey());
    }

    public function testTimeoutsAreConfigurable(): void
    {
        $config = new Config(self::API_KEY, connectTimeout: 2.5, timeout: 30.0);

        self::assertSame(2.5, $config->connectTimeout());
        self::assertSame(30.0, $config->timeout());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideBaseUrls(): iterable
    {
        yield 'production' => ['https://api.pey.mx', 'https://api.pey.mx'];
        yield 'trailing slash' => ['https://api.pey.mx/', 'https://api.pey.mx'];
        yield 'upper-case scheme and host' => ['HTTPS://API.PEY.MX', 'https://api.pey.mx'];
        yield 'port' => ['https://localhost:8443', 'https://localhost:8443'];
        yield 'path prefix' => ['https://gateway.example.com/pey/', 'https://gateway.example.com/pey'];
    }

    #[DataProvider('provideBaseUrls')]
    public function testItNormalizesTheBaseUrl(string $baseUrl, string $expected): void
    {
        self::assertSame($expected, (new Config(self::API_KEY, $baseUrl))->baseUrl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidBaseUrls(): iterable
    {
        yield 'plain http' => ['http://api.pey.mx'];
        yield 'no scheme' => ['api.pey.mx'];
        yield 'relative' => ['/v1'];
        yield 'empty' => [''];
        yield 'other scheme' => ['ftp://api.pey.mx'];
        yield 'credentials' => ['https://user:pass@api.pey.mx'];
        yield 'query' => ['https://api.pey.mx?debug=1'];
        yield 'fragment' => ['https://api.pey.mx#x'];
        yield 'whitespace' => ['https://api.pey.mx/ v1'];
        yield 'malformed' => ['https://'];
    }

    #[DataProvider('provideInvalidBaseUrls')]
    public function testItRejectsInvalidBaseUrls(string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(self::API_KEY, $baseUrl);
    }

    public function testBaseUrlErrorsDoNotEchoTheUrl(): void
    {
        try {
            new Config(self::API_KEY, 'https://user:hunter2@api.pey.mx');
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidApiKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space inside' => ['pey key'];
        yield 'leading space' => [' pey_key'];
        yield 'line break' => ["pey_key\r\nX-Injected: 1"];
        yield 'trailing line break' => ["pey_key\n"];
        yield 'trailing carriage return and line break' => ["pey_key\r\n"];
        yield 'tab' => ["pey\tkey"];
        yield 'non-ASCII' => ['pey_kéy'];
    }

    #[DataProvider('provideInvalidApiKeys')]
    public function testItRejectsApiKeysThatAreUnsafeInAHeader(string $apiKey): void
    {
        $this->expectException(ConfigurationException::class);

        new Config($apiKey);
    }

    public function testItValidatesSecretsPassedDirectly(): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(new Secret("pey_key\nX-Injected: 1"));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function provideInvalidTimeouts(): iterable
    {
        yield 'zero connect' => [0.0, 15.0];
        yield 'negative connect' => [-1.0, 15.0];
        yield 'zero total' => [5.0, 0.0];
        yield 'connect above total' => [20.0, 15.0];
        yield 'too long' => [5.0, 301.0];
        yield 'infinite' => [5.0, \INF];
        yield 'not a number' => [\NAN, 15.0];
    }

    #[DataProvider('provideInvalidTimeouts')]
    public function testItRejectsInvalidTimeouts(float $connectTimeout, float $timeout): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(self::API_KEY, connectTimeout: $connectTimeout, timeout: $timeout);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDumpMechanisms(): iterable
    {
        return Dumps::mechanisms();
    }

    #[DataProvider('provideDumpMechanisms')]
    public function testTheApiKeyNeverAppearsInDumps(string $mechanism): void
    {
        $output = Dumps::via($mechanism, new Config(self::API_KEY));

        self::assertStringNotContainsString(self::API_KEY, $output);
    }

    public function testItCannotBeSerialized(): void
    {
        self::assertStringStartsWith('serialize threw', Dumps::via('serialize', new Config(self::API_KEY)));
    }

    public function testTheApiKeyParameterIsMarkedSensitive(): void
    {
        $parameter = new \ReflectionParameter([Config::class, '__construct'], 'apiKey');

        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testTheApiKeyNeverAppearsInConfigurationErrors(): void
    {
        try {
            new Config(self::API_KEY, 'http://insecure.example.com');
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString(self::API_KEY, $output, $where);
            }
        }
    }

    public function testAnInvalidApiKeyIsNotEchoedInTheError(): void
    {
        $invalid = "pey_SECRET_part\nsecond_line";

        try {
            new Config($invalid);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString('pey_SECRET_part', $output, $where);
            }
        }
    }
}
