<?php

declare(strict_types=1);

namespace Dev1\Pey;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Validation\Clabe;

/**
 * SDK configuration.
 *
 * The API key is wrapped in a Secret, so dumping, exporting or serializing a
 * Config never reveals it. TLS certificate verification is always on and cannot
 * be disabled; the base URL must use HTTPS. Bank account registration is
 * blocked unless the CLABE is in $allowedClabes (see BankAccountGuard).
 */
final readonly class Config
{
    /**
     * Pey's production API. Pey has no sandbox: every request moves real money.
     */
    public const DEFAULT_BASE_URL = 'https://api.pey.mx';

    public const DEFAULT_CONNECT_TIMEOUT = 5.0;

    public const DEFAULT_TIMEOUT = 15.0;

    private const MAX_TIMEOUT = 300.0;

    private Secret $apiKey;

    private string $baseUrl;

    private float $connectTimeout;

    private float $timeout;

    /**
     * @var list<string>
     */
    private array $allowedClabes;

    private RetryPolicy $retryPolicy;

    /**
     * @param string|Secret $apiKey         the Pey API key; never logged or shown in errors
     * @param string        $baseUrl        HTTPS base URL. Override it only for a Pey environment other than production, if Pey releases one.
     * @param float         $connectTimeout seconds allowed to establish the connection
     * @param float         $timeout        seconds allowed for the whole request
     * @param list<string>  $allowedClabes  CLABEs this application may register with Pey. Empty (the default) blocks every registration.
     * @param RetryPolicy   $retryPolicy    retries for GET requests only (default: 2 retries). POSTs are never retried.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        #[\SensitiveParameter]
        string|Secret $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        float $timeout = self::DEFAULT_TIMEOUT,
        array $allowedClabes = [],
        RetryPolicy $retryPolicy = new RetryPolicy(),
    ) {
        $this->apiKey = self::validateApiKey($apiKey);
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);

        self::assertTimeout('connectTimeout', $connectTimeout);
        self::assertTimeout('timeout', $timeout);
        if ($connectTimeout > $timeout) {
            throw ConfigurationException::because('connectTimeout must not be greater than timeout.');
        }

        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->allowedClabes = self::validateAllowedClabes($allowedClabes);
        $this->retryPolicy = $retryPolicy;
    }

    public function apiKey(): Secret
    {
        return $this->apiKey;
    }

    /**
     * The normalized base URL, without a trailing slash.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    /**
     * @return list<string>
     */
    public function allowedClabes(): array
    {
        return $this->allowedClabes;
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy;
    }

    /**
     * @param array<array-key, mixed> $clabes
     *
     * @return list<string>
     *
     * @throws ConfigurationException
     */
    private static function validateAllowedClabes(array $clabes): array
    {
        $valid = [];
        foreach ($clabes as $index => $clabe) {
            if (!\is_string($clabe) || !Clabe::isValid($clabe)) {
                throw ConfigurationException::because(\sprintf(
                    'allowedClabes[%s] (%s) must be an 18-digit CLABE with a correct check digit.',
                    $index,
                    \is_string($clabe) ? Clabe::mask($clabe) : get_debug_type($clabe),
                ));
            }
            $valid[$clabe] = $clabe;
        }

        return array_values($valid);
    }

    /**
     * @throws ConfigurationException
     */
    private static function validateApiKey(#[\SensitiveParameter] string|Secret $apiKey): Secret
    {
        $value = $apiKey instanceof Secret ? $apiKey->reveal() : $apiKey;

        // Visible ASCII only: anything else could break or inject into the
        // Authorization header, and an invalid header would make the PSR-7
        // implementation throw with the key in its trace arguments.
        if (preg_match('/^[\x21-\x7E]+$/D', $value) !== 1) {
            throw ConfigurationException::because('The Pey API key must be a non-empty string of visible ASCII characters without spaces.');
        }

        return $apiKey instanceof Secret ? $apiKey : new Secret($value);
    }

    /**
     * @throws ConfigurationException
     */
    private static function normalizeBaseUrl(string $baseUrl): string
    {
        // The URL is not echoed in errors: it could carry credentials.
        $parts = parse_url($baseUrl);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw ConfigurationException::because('The base URL must be an absolute URL such as https://api.pey.mx.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw ConfigurationException::because('The base URL must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ConfigurationException::because('The base URL must not contain credentials, a query string or a fragment.');
        }

        if (preg_match('/[\x00-\x20\x7F]/', $baseUrl) === 1) {
            throw ConfigurationException::because('The base URL must not contain whitespace or control characters.');
        }

        $url = 'https://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        return $url . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * @throws ConfigurationException
     */
    private static function assertTimeout(string $name, float $seconds): void
    {
        if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > self::MAX_TIMEOUT) {
            throw ConfigurationException::because(\sprintf('%s must be greater than 0 and at most %d seconds.', $name, (int) self::MAX_TIMEOUT));
        }
    }
}
