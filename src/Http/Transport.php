<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\AuthenticationException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\ForbiddenException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\RateLimitedException;
use Dev1\Pey\Exception\ServerException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Version;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sends authenticated JSON requests to Pey and maps responses to arrays or
 * exceptions.
 *
 * Security properties:
 * - the Authorization header is added to the request only in send(), right
 *   before it goes to the client. It is redacted in logs and never stored;
 * - PSR-18 exceptions are not chained: they carry the request and therefore the
 *   header. Their messages are scrubbed of the key;
 * - request and response bodies are never logged, because they contain CLABEs,
 *   names, phone numbers and metadata.
 *
 * This class sends each request exactly once: it never retries.
 *
 * @internal
 */
final class Transport
{
    private const MAX_CAUSE_MESSAGE_LENGTH = 300;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Builds a transport, discovering whatever the caller does not supply.
     * Without a client, HttpClientFactory builds one with the configured timeouts.
     *
     * @throws ConfigurationException when no PSR-17 factories or PSR-18 client can be found
     */
    public static function create(
        Config $config,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        $logger ??= new NullLogger();

        try {
            $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        } catch (DiscoveryException) {
            throw ConfigurationException::because('No PSR-17 HTTP factories found. Install nyholm/psr7 or guzzlehttp/psr7, or pass the factories explicitly.');
        }

        $httpClient ??= (new HttpClientFactory($logger))->create($config);

        return new self($config, $httpClient, $requestFactory, $streamFactory, $logger);
    }

    /**
     * @param string                $pathTemplate path with `{name}` placeholders, for example `/transaction/{id}`
     * @param array<string, string> $pathParams   values for the placeholders (URL-encoded here)
     * @param array<string, string> $query        query string parameters
     *
     * @return array<array-key, mixed> the decoded JSON body, with numbers as decimal strings
     *
     * @throws PeyException
     */
    public function get(string $pathTemplate, array $pathParams = [], array $query = []): array
    {
        return $this->send('GET', $pathTemplate, $pathParams, $query, null);
    }

    /**
     * @param array<array-key, mixed> $body JSON object body. Use JsonNumber for amounts.
     * @param array<string, string>   $pathParams
     *
     * @return array<array-key, mixed>
     *
     * @throws PeyException
     */
    public function post(string $pathTemplate, #[\SensitiveParameter] array $body, array $pathParams = []): array
    {
        return $this->send('POST', $pathTemplate, $pathParams, [], $body);
    }

    /**
     * @param array<string, string>        $pathParams
     * @param array<string, string>        $query
     * @param array<array-key, mixed>|null $body
     *
     * @return array<array-key, mixed>
     *
     * @throws PeyException
     */
    private function send(string $method, string $pathTemplate, array $pathParams, array $query, #[\SensitiveParameter] ?array $body): array
    {
        $uri = $this->config->baseUrl() . self::renderPath($pathTemplate, $pathParams);
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', Version::USER_AGENT);

        if ($body !== null) {
            try {
                $json = Json::encodeObject($body);
            } catch (\JsonException $e) {
                throw ValidationException::forField('body', 'The request body cannot be encoded as JSON: ' . $e->getMessage());
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->apiKey()->reveal());

        $this->logger->debug('Pey API request {method} {path}', [
            'method' => $method,
            'path' => $pathTemplate,
            'uri' => $uri,
            'headers' => HeaderRedactor::redact($request->getHeaders()),
        ]);

        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->sendRequest($request);
            $contents = (string) $response->getBody();
        } catch (ClientExceptionInterface|\RuntimeException $e) {
            // \RuntimeException covers a response stream that fails while being read.
            throw $this->networkFailure($method, $pathTemplate, $e);
        }

        $status = $response->getStatusCode();

        $this->logger->debug('Pey API response {status} for {method} {path}', [
            'method' => $method,
            'path' => $pathTemplate,
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        if ($status >= 200 && $status < 300) {
            return $this->decodeSuccess($method, $pathTemplate, $status, $contents);
        }

        throw $this->errorFor($method, $pathTemplate, $response, $contents);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeSuccess(string $method, string $pathTemplate, int $status, string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        try {
            $data = Json::decode($contents);
        } catch (\JsonException) {
            throw UnexpectedResponseException::malformedBody($method, $pathTemplate, $status);
        }

        if ($data !== [] && array_is_list($data)) {
            throw UnexpectedResponseException::malformedBody($method, $pathTemplate, $status);
        }

        return $data;
    }

    private function errorFor(string $method, string $pathTemplate, ResponseInterface $response, string $contents): PeyException
    {
        $status = $response->getStatusCode();
        $error = ApiError::fromResponseBody($contents);
        $retryAfter = $response->hasHeader('Retry-After') ? trim($response->getHeaderLine('Retry-After')) : null;

        $exception = match (true) {
            $status === 400 => ValidationException::fromApiResponse($method, $pathTemplate, $error),
            $status === 401 => AuthenticationException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status === 403 => ForbiddenException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status === 404 => NotFoundException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status === 409 => ConflictException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status === 429 => RateLimitedException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status === 503 => ServiceUnavailableException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            $status >= 500 && $status < 600 => ServerException::fromResponse($method, $pathTemplate, $status, $error, $retryAfter),
            default => UnexpectedResponseException::unexpectedStatus($method, $pathTemplate, $status, $error),
        };

        $this->logger->warning('Pey API error {status} ({type}) for {method} {path}', [
            'method' => $method,
            'path' => $pathTemplate,
            'status' => $status,
            'type' => $error->type,
            'request_id' => $error->requestId,
            'exception' => $exception::class,
        ]);

        return $exception;
    }

    /**
     * $cause is a sensitive parameter: a PSR-18 exception holds the request,
     * including its Authorization header, and would otherwise be recorded in
     * the trace arguments of the NetworkException created here.
     */
    private function networkFailure(string $method, string $pathTemplate, #[\SensitiveParameter] \Throwable $cause): NetworkException
    {
        // A request the client rejected before sending cannot have been processed.
        // Anything else (timeouts included) may have reached Pey after the request was sent.
        $mayHaveBeenProcessed = $method !== 'GET' && !$cause instanceof RequestExceptionInterface;

        // get_debug_type() rather than ::class: an anonymous class name contains a
        // NUL byte and the absolute path of the file that defines it.
        $causeType = get_debug_type($cause);

        $exception = NetworkException::create(
            $method,
            $pathTemplate,
            $mayHaveBeenProcessed,
            $causeType,
            $this->sanitizeCauseMessage($cause->getMessage()),
        );

        $this->logger->error('Pey API network error for {method} {path}: {cause}', [
            'method' => $method,
            'path' => $pathTemplate,
            'cause' => $causeType,
            'request_may_have_been_processed' => $mayHaveBeenProcessed,
        ]);

        return $exception;
    }

    private function sanitizeCauseMessage(string $message): string
    {
        $message = str_replace($this->config->apiKey()->reveal(), '[REDACTED]', $message);
        $message = (string) preg_replace('/(Bearer)\s+\S+/i', '$1 [REDACTED]', $message);
        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message));

        return \strlen($message) > self::MAX_CAUSE_MESSAGE_LENGTH
            ? substr($message, 0, self::MAX_CAUSE_MESSAGE_LENGTH) . '...'
            : $message;
    }

    /**
     * @param array<string, string> $params
     */
    private static function renderPath(string $template, array $params): string
    {
        $path = $template;

        foreach ($params as $name => $value) {
            if ($value === '') {
                throw ValidationException::forField($name, 'must not be empty.');
            }

            $placeholder = '{' . $name . '}';
            if (!str_contains($path, $placeholder)) {
                throw new \LogicException(\sprintf('Path template %s has no placeholder %s.', $template, $placeholder));
            }

            $path = str_replace($placeholder, rawurlencode($value), $path);
        }

        if (!str_starts_with($path, '/') || preg_match('/\{[^}]*\}/', $path) === 1) {
            throw new \LogicException(\sprintf('Path template %s is invalid or has unfilled placeholders.', $template));
        }

        return $path;
    }
}
