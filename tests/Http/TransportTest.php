<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\AuthenticationException;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\ForbiddenException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\RateLimitedException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Exception\ServerException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\HttpClientFactory;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use Dev1\Pey\Tests\Support\FakePsr18Exceptions;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\RecordingLogger;
use Dev1\Pey\Tests\Support\RecordingSleeper;
use Dev1\Pey\Version;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

#[CoversClass(Transport::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(RetryPolicy::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(NetworkException::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(SecretExposureException::class)]
final class TransportTest extends TestCase
{
    private const API_KEY = 'pey_test_not_a_real_value';

    private MockHttpClient $client;

    private RecordingLogger $logger;

    private Transport $transport;

    protected function setUp(): void
    {
        $this->client = new MockHttpClient();
        $this->logger = new RecordingLogger();
        $factory = new Psr17Factory();
        // Single-attempt behavior; retries are covered by TransportRetryTest.
        $this->transport = new Transport(new Config(self::API_KEY, retryPolicy: RetryPolicy::disabled()), $this->client, $factory, $factory, $this->logger, new RecordingSleeper());
    }

    public function testGetSendsAnAuthenticatedJsonRequest(): void
    {
        $this->client->willRespond(200, '{"id":"01JBAVWE4NS5TGXHWGMX3KH23Q"}');

        $data = $this->transport->get('/bank-account/{id}', ['id' => '01JBAVWE4NS5TGXHWGMX3KH23Q']);

        $request = $this->client->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.pey.mx/bank-account/01JBAVWE4NS5TGXHWGMX3KH23Q', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(Version::USER_AGENT, $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame(['id' => '01JBAVWE4NS5TGXHWGMX3KH23Q'], $data);
    }

    public function testPostSendsTheBodyAsJsonWithExactAmounts(): void
    {
        $this->client->willRespond(201, '{"id":"AB12CD34EF","qrString":"x"}');

        $this->transport->post('/codi/qr', ['amount' => new JsonNumber('150.00'), 'numRef' => '0012345']);

        $request = $this->client->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pey.mx/codi/qr', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"amount":150.00,"numRef":"0012345"}', (string) $request->getBody());
    }

    public function testItUsesTheConfiguredBaseUrl(): void
    {
        $factory = new Psr17Factory();
        $transport = new Transport(new Config(self::API_KEY, 'https://dev.example.com/api/'), $this->client, $factory, $factory);
        $this->client->willRespond(200, '{}');

        $transport->get('/transaction/api-key');

        self::assertSame('https://dev.example.com/api/transaction/api-key', (string) $this->client->lastRequest()->getUri());
    }

    public function testPathParametersAreUrlEncoded(): void
    {
        $this->client->willRespond(200, '{}');

        $this->transport->get('/transaction/codi/qr/{id}', ['id' => '../a b/?x=1#']);

        self::assertSame(
            'https://api.pey.mx/transaction/codi/qr/..%2Fa%20b%2F%3Fx%3D1%23',
            (string) $this->client->lastRequest()->getUri(),
        );
    }

    public function testQueryParametersAreEncoded(): void
    {
        $this->client->willRespond(200, '{"items":[],"nextToken":null}');

        $this->transport->get('/transaction/api-key', [], ['nextToken' => 'eyJQSyI6ICJUWE4j+/=']);

        self::assertSame('nextToken=eyJQSyI6ICJUWE4j%2B%2F%3D', $this->client->lastRequest()->getUri()->getQuery());
    }

    public function testAnEmptyPathParameterIsAValidationError(): void
    {
        try {
            $this->transport->get('/transaction/{id}', ['id' => '']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['id' => ['must not be empty.']], $e->errors());
            self::assertSame([], $this->client->requests);
        }
    }

    public function testAMismatchedPathTemplateIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->transport->get('/transaction/{id}', ['other' => 'x']);
    }

    public function testAnUnfilledPlaceholderIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->transport->get('/transaction/{id}');
    }

    public function testResponseNumbersAreDecimalStrings(): void
    {
        $this->client->willRespond(200, '{"amount":102.5,"amountBreakdown":{"base":100,"fee":2.5},"dateCreated":1704067200}');

        self::assertSame(
            ['amount' => '102.5', 'amountBreakdown' => ['base' => '100', 'fee' => '2.5'], 'dateCreated' => '1704067200'],
            $this->transport->get('/transaction/{id}', ['id' => '01HXYZTXN1234567890ABCDE00']),
        );
    }

    public function testAnEmptySuccessBodyIsAnEmptyArray(): void
    {
        $this->client->willRespond(200, "  \n");

        self::assertSame([], $this->transport->get('/transaction/api-key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedSuccessBodies(): iterable
    {
        yield 'html' => ['<html>OK</html>'];
        yield 'json list' => ['[{"id":"x"}]'];
        yield 'json scalar' => ['true'];
    }

    #[DataProvider('provideMalformedSuccessBodies')]
    public function testAMalformedSuccessBodyIsAnUnexpectedResponse(string $body): void
    {
        $this->client->willRespond(200, $body);

        try {
            $this->transport->get('/transaction/api-key');
            self::fail('Expected an UnexpectedResponseException.');
        } catch (UnexpectedResponseException $e) {
            self::assertSame(200, $e->httpStatus());
            self::assertNull($e->apiError());
        }
    }

    public function testAnUnencodableBodyIsAValidationErrorAndIsNeverSent(): void
    {
        try {
            $this->transport->post('/codi/qr', ['concept' => "\xB1\x31"]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->errors());
            self::assertFalse($e->wasRejectedByPey());
            self::assertSame([], $this->client->requests);
        }
    }

    /**
     * @return iterable<string, array{int, class-string<PeyException>}>
     */
    public static function provideErrorStatuses(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, ForbiddenException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '429' => [429, RateLimitedException::class];
        yield '500' => [500, ServerException::class];
        yield '502' => [502, ServerException::class];
        yield '503' => [503, ServiceUnavailableException::class];
        yield '504' => [504, ServerException::class];
        yield '301' => [301, UnexpectedResponseException::class];
        yield '405' => [405, UnexpectedResponseException::class];
        yield '418' => [418, UnexpectedResponseException::class];
    }

    /**
     * @param class-string<PeyException> $expected
     */
    #[DataProvider('provideErrorStatuses')]
    public function testErrorStatusesMapToTypedExceptions(int $status, string $expected): void
    {
        $this->client->willRespond($status, '{"error":{"message":"Something failed","type":"SOME_TYPE","requestId":"req-1"}}');

        try {
            $this->transport->post('/payment-link/update', ['paymentLinkId' => 'x', 'amount' => new JsonNumber('1.00')]);
            self::fail('Expected an exception.');
        } catch (PeyException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($status, $e->httpStatus());
            self::assertSame('SOME_TYPE', $e->errorType());
            self::assertSame('req-1', $e->requestId());
            self::assertSame(
                \sprintf('Pey API returned HTTP %d (SOME_TYPE) for POST /payment-link/update: Something failed [request id: req-1]', $status),
                $e->getMessage(),
            );
        }
    }

    public function testA400KeepsPeysMessageAsTheRequestError(): void
    {
        $this->client->willRespond(400, '{"error":{"message":"clabe is not valid","type":"INVALID_CLABE","requestId":"r"}}');

        try {
            $this->transport->post('/bank-account', ['clabe' => '000000000000000000']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->wasRejectedByPey());
            self::assertSame(['request' => ['clabe is not valid']], $e->errors());
        }
    }

    public function testConflictsExposeTheirCode(): void
    {
        $this->client->willRespond(409, '{"error":{"message":"Payment link is not pending","type":"PAYMENT_LINK_NOT_PENDING","requestId":"r"}}');

        try {
            $this->transport->post('/payment-link/update', ['paymentLinkId' => 'x', 'amount' => new JsonNumber('1.00')]);
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $e) {
            self::assertTrue($e->isPaymentLinkNotPending());
            self::assertFalse($e->isConcurrentUpdate());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideRetryAfterStatuses(): iterable
    {
        yield '429' => [429];
        yield '503' => [503];
    }

    #[DataProvider('provideRetryAfterStatuses')]
    public function testRetryAfterIsKept(int $status): void
    {
        $this->client->willRespond($status, '', ['Retry-After' => ' 120 ']);

        try {
            $this->transport->get('/transaction/api-key');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame('120', $e->retryAfter());
            self::assertSame(ApiError::UNKNOWN_TYPE, $e->errorType());
        }
    }

    public function testAnUndocumentedErrorBodyStillProducesATypedException(): void
    {
        $this->client->willRespond(401, '{"message":"Unauthorized"}');

        try {
            $this->transport->get('/transaction/api-key');
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertSame('Unauthorized', $e->apiError()->message);
            self::assertNull($e->retryAfter());
            self::assertSame('Pey API returned HTTP 401 (UNKNOWN) for GET /transaction/api-key: Unauthorized', $e->getMessage());
        }
    }

    public function testAPostNetworkFailureMayHaveBeenProcessed(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::network('Operation timed out', new Request('POST', 'https://api.pey.mx/codi/qr')));

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertTrue($e->requestMayHaveBeenProcessed());
            self::assertSame('POST', $e->method());
            self::assertSame('/codi/qr', $e->pathTemplate());
            self::assertNull($e->httpStatus());
            self::assertStringContainsString('Operation timed out', $e->getMessage());
            self::assertStringContainsString('may have processed this request', $e->getMessage());
            self::assertNull($e->getPrevious(), 'The PSR-18 exception holds the request and must not be chained.');
        }
    }

    public function testAGenericClientFailureOnAPostMayHaveBeenProcessed(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::generic(''));

        try {
            $this->transport->post('/codi/pn', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertTrue($e->requestMayHaveBeenProcessed());
            self::assertSame('RuntimeException@anonymous', $e->causeClass());
            self::assertStringContainsString('RuntimeException@anonymous', $e->getMessage(), 'An empty cause message falls back to the class.');
            self::assertStringNotContainsString("\0", $e->getMessage());
            self::assertStringNotContainsString(__DIR__, $e->getMessage(), 'No file paths from anonymous class names.');
        }
    }

    public function testARequestTheClientRejectedBeforeSendingWasNotProcessed(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::request('Invalid request', new Request('POST', 'https://api.pey.mx/codi/qr')));

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertFalse($e->requestMayHaveBeenProcessed());
            self::assertStringNotContainsString('may have processed', $e->getMessage());
        }
    }

    public function testAGetNetworkFailureIsNotFlaggedAsProcessed(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::network('Connection refused', new Request('GET', 'https://api.pey.mx/transaction/api-key')));

        try {
            $this->transport->get('/transaction/api-key');
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertFalse($e->requestMayHaveBeenProcessed());
        }
    }

    public function testABrokenResponseStreamIsANetworkFailure(): void
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willThrowException(new \RuntimeException('Stream reset'));
        $this->client->willReturn((new Response(200))->withBody($stream));

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertSame(\RuntimeException::class, $e->causeClass());
            self::assertTrue($e->requestMayHaveBeenProcessed());
        }
    }

    public function testLongCauseMessagesAreTruncatedToOneLine(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::network(str_repeat('x', 500) . "\nsecond line", new Request('GET', '/')));

        try {
            $this->transport->get('/transaction/api-key');
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertStringContainsString(str_repeat('x', 300) . '...', $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLeakyCauseMessages(): iterable
    {
        yield 'raw key' => ['cURL error for key ' . self::API_KEY];
        yield 'authorization header' => ['Request failed with header Authorization: Bearer ' . self::API_KEY];
        yield 'bearer with another token' => ['proxy said: bearer some-other-token'];
    }

    #[DataProvider('provideLeakyCauseMessages')]
    public function testCauseMessagesAreScrubbedOfCredentials(string $causeMessage): void
    {
        $authenticated = new Request('POST', 'https://api.pey.mx/codi/qr', ['Authorization' => 'Bearer ' . self::API_KEY]);
        $this->client->willReturn(FakePsr18Exceptions::network($causeMessage, $authenticated));

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString('some-other-token', $e->getMessage());
            self::assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function testTheApiKeyNeverAppearsInNetworkExceptionsOrTheirTraces(): void
    {
        $authenticated = new Request('POST', 'https://api.pey.mx/codi/qr', ['Authorization' => 'Bearer ' . self::API_KEY]);
        $this->client->willReturn(FakePsr18Exceptions::network('Authorization: Bearer ' . self::API_KEY, $authenticated));

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString(self::API_KEY, $output, $where);
            }
        }
    }

    public function testTheApiKeyNeverAppearsInApiExceptionsOrTheirTraces(): void
    {
        $this->client->willRespond(500, '{"error":{"message":"Error creating CoDi QR","type":"INTERNAL_SERVER_ERROR","requestId":"r"}}');

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
            self::fail('Expected a ServerException.');
        } catch (ServerException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString(self::API_KEY, $output, $where);
            }
        }
    }

    public function testRequestBodiesAreSensitiveInTraces(): void
    {
        $this->client->willRespond(500, '');

        try {
            $this->transport->post('/bank-account', ['clabe' => '012180001234567899', 'name' => 'Persona Física']);
            self::fail('Expected a ServerException.');
        } catch (ServerException $e) {
            $trace = Dumps::sdkTrace($e);
            self::assertStringContainsString('Dev1\Pey\Http\Transport', $trace, 'The SDK frames must be inspected.');
            self::assertStringNotContainsString('012180001234567899', $trace);
            self::assertStringNotContainsString('Persona Física', $trace);
        }
    }

    public function testRequestsAndResponsesAreLoggedWithTheAuthorizationRedacted(): void
    {
        $this->client->willRespond(200, '{"ok":true}');

        $this->transport->post('/payment-link/create', ['amount' => new JsonNumber('100.00'), 'clientReferenceId' => 'order-12345']);

        $debug = $this->logger->recordsAt('debug');
        self::assertCount(2, $debug);

        self::assertSame([
            'method' => 'POST',
            'path' => '/payment-link/create',
            'uri' => 'https://api.pey.mx/payment-link/create',
            'headers' => [
                'Host' => ['api.pey.mx'],
                'Accept' => ['application/json'],
                'User-Agent' => [Version::USER_AGENT],
                'Content-Type' => ['application/json'],
                'Authorization' => ['Bearer [REDACTED]'],
            ],
        ], $debug[0]['context']);

        $response = $debug[1]['context'];
        self::assertArrayHasKey('duration_ms', $response);
        self::assertIsInt($response['duration_ms']);
        unset($response['duration_ms']);
        self::assertSame(['method' => 'POST', 'path' => '/payment-link/create', 'status' => 200], $response);
    }

    public function testErrorResponsesAreLoggedAsWarnings(): void
    {
        $this->client->willRespond(404, '{"error":{"message":"Not found","type":"NOT_FOUND","requestId":"req-9"}}');

        try {
            $this->transport->get('/transaction/{id}', ['id' => 'x']);
        } catch (NotFoundException) {
        }

        $warnings = $this->logger->recordsAt('warning');
        self::assertCount(1, $warnings);
        self::assertSame([
            'method' => 'GET',
            'path' => '/transaction/{id}',
            'status' => 404,
            'type' => 'NOT_FOUND',
            'request_id' => 'req-9',
            'exception' => NotFoundException::class,
        ], $warnings[0]['context']);
    }

    public function testNetworkFailuresAreLoggedAsErrors(): void
    {
        $this->client->willReturn(FakePsr18Exceptions::network('timeout', new Request('POST', '/')));

        try {
            $this->transport->post('/codi/pn', ['amount' => new JsonNumber('1.00')]);
        } catch (NetworkException) {
        }

        $errors = $this->logger->recordsAt('error');
        self::assertCount(1, $errors);
        self::assertSame([
            'method' => 'POST',
            'path' => '/codi/pn',
            'cause' => 'RuntimeException@anonymous',
            'request_may_have_been_processed' => true,
        ], $errors[0]['context']);
    }

    public function testLogsNeverContainTheApiKeyOrRequestBodies(): void
    {
        $this->client
            ->willRespond(200, '{"id":"x"}')
            ->willRespond(500, '{"error":{"message":"boom","type":"INTERNAL_SERVER_ERROR","requestId":"r"}}')
            ->willReturn(FakePsr18Exceptions::network('Bearer ' . self::API_KEY, new Request('POST', '/')));

        $body = ['clabe' => '012180001234567899', 'name' => 'Persona Física', 'alias' => 'BBVA', 'entity' => 'PERSON'];
        $this->transport->post('/bank-account', $body);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $this->transport->post('/bank-account', $body);
            } catch (PeyException) {
            }
        }

        $everything = print_r($this->logger->records, true);
        self::assertStringNotContainsString(self::API_KEY, $everything);
        self::assertStringNotContainsString('012180001234567899', $everything);
        self::assertStringNotContainsString('Persona Física', $everything);
    }

    public function testItSendsEachRequestExactlyOnce(): void
    {
        $this->client->willRespond(503, '')->willRespond(200, '{}');

        try {
            $this->transport->post('/codi/qr', ['amount' => new JsonNumber('1.00')]);
        } catch (ServiceUnavailableException) {
        }

        self::assertCount(1, $this->client->requests, 'Transport must never retry; a retried POST can duplicate a charge.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDumpMechanisms(): iterable
    {
        return Dumps::mechanisms();
    }

    #[DataProvider('provideDumpMechanisms')]
    public function testTheApiKeyNeverAppearsInDumpsOfTheTransport(string $mechanism): void
    {
        self::assertStringNotContainsString(self::API_KEY, Dumps::via($mechanism, $this->transport));
    }

    public function testCreateUsesTheGivenClientAndDiscoversFactories(): void
    {
        $this->client->willRespond(200, '{"ok":true}');

        $transport = Transport::create(new Config(self::API_KEY), $this->client, $this->logger);

        self::assertSame(['ok' => true], $transport->get('/transaction/api-key'));
        self::assertCount(1, $this->client->requests);
    }

    public function testCreateBuildsAClientWhenNoneIsGiven(): void
    {
        $transport = Transport::create(new Config(self::API_KEY));

        $client = (new \ReflectionProperty(Transport::class, 'httpClient'))->getValue($transport);
        self::assertInstanceOf(\GuzzleHttp\Client::class, $client);
    }
}
