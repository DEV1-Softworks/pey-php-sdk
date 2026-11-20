<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Http\HttpClientFactory;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\RecordingLogger;
use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

#[CoversClass(HttpClientFactory::class)]
#[UsesClass(Config::class)]
#[UsesClass(\Dev1\Pey\Http\RetryPolicy::class)]
#[UsesClass(Secret::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ConfigurationException::class)]
final class HttpClientFactoryTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config('pey_test_not_a_real_value', connectTimeout: 3.0, timeout: 20.0);
    }

    public function testItPrefersGuzzleBecauseItSupportsAConnectTimeout(): void
    {
        $client = (new HttpClientFactory())->create($this->config);

        self::assertInstanceOf(GuzzleClient::class, $client);
    }

    public function testGuzzleGetsTheConfiguredTimeoutsNoRedirectsAndTlsVerification(): void
    {
        self::assertSame([
            'connect_timeout' => 3.0,
            'timeout' => 20.0,
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
        ], HttpClientFactory::guzzleOptions($this->config));
    }

    public function testItFallsBackToSymfonyWhenGuzzleIsNotInstalled(): void
    {
        $factory = new HttpClientFactory(classExists: static fn(string $class): bool => $class !== GuzzleClient::class);

        self::assertInstanceOf(SymfonyPsr18Client::class, $factory->create($this->config));
    }

    public function testSymfonyGetsTheTotalTimeoutNoRedirectsAndTlsVerification(): void
    {
        // Symfony has no connect timeout; a 3 s idle timeout would also abort
        // slow responses, so both idle and total use the total timeout.
        self::assertSame([
            'timeout' => 20.0,
            'max_duration' => 20.0,
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
        ], HttpClientFactory::symfonyOptions($this->config));
    }

    public function testItFallsBackToDiscoveryWithAWarningWhenNeitherIsInstalled(): void
    {
        $logger = new RecordingLogger();
        $discovered = new MockHttpClient();
        $factory = new HttpClientFactory(
            $logger,
            static fn(string $class): bool => false,
            static fn(): MockHttpClient => $discovered,
        );

        self::assertSame($discovered, $factory->create($this->config));

        $warnings = $logger->recordsAt('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('timeouts cannot be configured', $warnings[0]['message']);
        self::assertSame(['client' => MockHttpClient::class], $warnings[0]['context']);
    }

    public function testItUsesPhpHttpDiscoveryByDefault(): void
    {
        $logger = new RecordingLogger();
        $factory = new HttpClientFactory($logger, static fn(string $class): bool => false);

        // php-http/discovery finds one of the dev dependencies on its own.
        $client = $factory->create($this->config);

        self::assertNotInstanceOf(MockHttpClient::class, $client);
        self::assertSame(['client' => $client::class], $logger->recordsAt('warning')[0]['context'] ?? null);
    }

    public function testItExplainsWhatToInstallWhenNoClientExists(): void
    {
        $factory = new HttpClientFactory(
            classExists: static fn(string $class): bool => false,
            discover: static fn(): never => throw new NotFoundException('none'),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Install guzzlehttp/guzzle or symfony/http-client');

        $factory->create($this->config);
    }
}
