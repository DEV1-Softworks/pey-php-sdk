<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

/**
 * Builds the PSR-18 client used when the caller does not supply one.
 *
 * PSR-18 has no way to configure timeouts, so known clients are built directly
 * with the configured timeouts, redirects disabled (the API key must never
 * follow a redirect) and TLS verification on:
 *
 * 1. Guzzle (7 or 8): exact connect and total timeouts.
 * 2. Symfony HttpClient: it has no separate connect timeout, so the connect
 *    phase is bounded by the total timeout. Its idle timeout is also set to the
 *    total, because a shorter one would abort slow responses mid-request and
 *    leave a POST's outcome unknown.
 * 3. Any other client found by php-http/discovery. Its timeouts cannot be set,
 *    so a warning is logged.
 *
 * Neither Guzzle nor Symfony is a dependency of this package; each is used only
 * when installed. A client passed in by the caller is used as-is: its timeout
 * and TLS settings are the caller's responsibility.
 *
 * @internal
 */
final class HttpClientFactory
{
    /**
     * @var \Closure(class-string): bool
     */
    private readonly \Closure $classExists;

    /**
     * @var \Closure(): ClientInterface
     */
    private readonly \Closure $discover;

    /**
     * @param (\Closure(class-string): bool)|null $classExists for tests: decides whether an optional client is installed
     * @param (\Closure(): ClientInterface)|null  $discover    for tests: replaces php-http/discovery
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        ?\Closure $classExists = null,
        ?\Closure $discover = null,
    ) {
        $this->classExists = $classExists ?? static fn(string $class): bool => class_exists($class);
        $this->discover = $discover ?? static fn(): ClientInterface => Psr18ClientDiscovery::find();
    }

    /**
     * @throws ConfigurationException when no PSR-18 client is installed
     */
    public function create(Config $config): ClientInterface
    {
        if (($this->classExists)(GuzzleClient::class)) {
            return new GuzzleClient(self::guzzleOptions($config));
        }

        if (($this->classExists)(SymfonyPsr18Client::class) && ($this->classExists)(SymfonyHttpClient::class)) {
            return new SymfonyPsr18Client(SymfonyHttpClient::create(self::symfonyOptions($config)));
        }

        try {
            $client = ($this->discover)();
        } catch (DiscoveryException $e) {
            throw ConfigurationException::because(
                'No PSR-18 HTTP client found. Install guzzlehttp/guzzle or symfony/http-client, or pass a configured PSR-18 client.',
            );
        }

        $this->logger->warning(
            'Pey SDK: using a discovered PSR-18 client ({client}) whose timeouts cannot be configured. Install guzzlehttp/guzzle or symfony/http-client, or pass a client configured with timeouts.',
            ['client' => $client::class],
        );

        return $client;
    }

    /**
     * @return array{connect_timeout: float, timeout: float, allow_redirects: false, verify: true, http_errors: false}
     */
    public static function guzzleOptions(Config $config): array
    {
        return [
            'connect_timeout' => $config->connectTimeout(),
            'timeout' => $config->timeout(),
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
        ];
    }

    /**
     * @return array{timeout: float, max_duration: float, max_redirects: 0, verify_peer: true, verify_host: true}
     */
    public static function symfonyOptions(Config $config): array
    {
        return [
            'timeout' => $config->timeout(),
            'max_duration' => $config->timeout(),
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
        ];
    }
}
