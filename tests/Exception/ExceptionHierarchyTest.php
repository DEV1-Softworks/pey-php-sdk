<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Exception;

use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\AuthenticationException;
use Dev1\Pey\Exception\ConfigurationException;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeyException::class)]
#[CoversClass(ApiException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(ConflictException::class)]
#[CoversClass(NetworkException::class)]
#[CoversClass(UnexpectedResponseException::class)]
#[CoversClass(ConfigurationException::class)]
#[UsesClass(ApiError::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideExceptionClasses(): iterable
    {
        foreach ([
            ApiException::class,
            AuthenticationException::class,
            ConfigurationException::class,
            ConflictException::class,
            ForbiddenException::class,
            NetworkException::class,
            NotFoundException::class,
            RateLimitedException::class,
            SecretExposureException::class,
            ServerException::class,
            ServiceUnavailableException::class,
            UnexpectedResponseException::class,
            ValidationException::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideExceptionClasses')]
    public function testEveryExceptionExtendsPeyException(string $class): void
    {
        self::assertTrue(is_subclass_of($class, PeyException::class));
    }

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function provideHttpExceptionParents(): iterable
    {
        yield '401' => [AuthenticationException::class, ApiException::class];
        yield '403' => [ForbiddenException::class, ApiException::class];
        yield '404' => [NotFoundException::class, ApiException::class];
        yield '409' => [ConflictException::class, ApiException::class];
        yield '429' => [RateLimitedException::class, ApiException::class];
        yield '5xx' => [ServerException::class, ApiException::class];
        yield '503 is a server error' => [ServiceUnavailableException::class, ServerException::class];
    }

    /**
     * @param class-string $class
     * @param class-string $parent
     */
    #[DataProvider('provideHttpExceptionParents')]
    public function testHttpExceptionsCanBeCaughtByGroup(string $class, string $parent): void
    {
        self::assertTrue(is_subclass_of($class, $parent));
    }

    public function testLocalValidationErrorsAreFieldLevel(): void
    {
        $e = ValidationException::withErrors([
            'amount' => ['must be greater than 0.'],
            'numRef' => ['must be 1 to 7 digits.', 'must be a string.'],
        ]);

        self::assertSame(['amount' => ['must be greater than 0.'], 'numRef' => ['must be 1 to 7 digits.', 'must be a string.']], $e->errors());
        self::assertSame('Invalid Pey request. amount: must be greater than 0. | numRef: must be 1 to 7 digits.; must be a string.', $e->getMessage());
        self::assertFalse($e->wasRejectedByPey());
        self::assertNull($e->httpStatus());
        self::assertNull($e->apiError());
        self::assertNull($e->errorType());
        self::assertNull($e->requestId());
        self::assertSame(0, $e->getCode());
    }

    public function testForFieldIsAShortcut(): void
    {
        self::assertSame(['clabe' => ['has an invalid check digit.']], ValidationException::forField('clabe', 'has an invalid check digit.')->errors());
    }

    public function testAValidationExceptionNeedsErrors(): void
    {
        $this->expectException(\LogicException::class);

        ValidationException::withErrors([]);
    }

    public function testAnApiValidationErrorWithoutMessageFallsBackToTheType(): void
    {
        $e = ValidationException::fromApiResponse('POST', '/codi/qr', new ApiError('INVALID_PARAMETER', '', null));

        self::assertSame(['request' => ['INVALID_PARAMETER']], $e->errors());
        self::assertSame(400, $e->getCode());
        self::assertSame('Pey API returned HTTP 400 (INVALID_PARAMETER) for POST /codi/qr', $e->getMessage());
    }

    public function testConflictHelpersRecognizeTheDocumentedCodes(): void
    {
        $concurrent = ConflictException::fromResponse('POST', '/payment-link/update', 409, new ApiError('CONCURRENT_UPDATE', 'm', 'r'));

        self::assertTrue($concurrent->isConcurrentUpdate());
        self::assertFalse($concurrent->isPaymentLinkNotPending());
        self::assertSame('CONCURRENT_UPDATE', $concurrent->errorType());
        self::assertSame(409, $concurrent->getCode());
    }

    public function testApiExceptionsExposePeysErrorAndRetryAfter(): void
    {
        $error = new ApiError('SERVICE_UNAVAILABLE', 'Maintenance', 'req-7');
        $e = ServiceUnavailableException::fromResponse('POST', '/codi/qr', 503, $error, '30');

        self::assertSame($error, $e->apiError());
        self::assertSame('30', $e->retryAfter());
        self::assertSame('req-7', $e->requestId());
        self::assertNull(NotFoundException::fromResponse('GET', '/x', 404, $error)->retryAfter());
    }

    public function testMalformedBodiesAreDescribed(): void
    {
        $e = UnexpectedResponseException::malformedBody('GET', '/transaction/{id}', 200);

        self::assertSame('Pey API returned HTTP 200 for GET /transaction/{id} with a body that is not a JSON object.', $e->getMessage());
        self::assertSame(200, $e->httpStatus());
        self::assertNull($e->apiError());
    }

    public function testUnexpectedStatusesKeepPeysError(): void
    {
        $e = UnexpectedResponseException::unexpectedStatus('GET', '/x', 418, new ApiError('TEAPOT', 'short and stout', null));

        self::assertSame(418, $e->httpStatus());
        self::assertSame('TEAPOT', $e->errorType());
        self::assertSame('Pey API returned HTTP 418 (TEAPOT) for GET /x: short and stout', $e->getMessage());
    }

    public function testNetworkExceptionsDescribeTheirOutcome(): void
    {
        $sent = NetworkException::create('POST', '/codi/qr', true, 'GuzzleHttp\Exception\ConnectException', 'timed out');
        $notSent = NetworkException::create('GET', '/transaction/{id}', false, 'X', 'refused');

        self::assertSame(
            "Network error during POST /codi/qr: timed out Pey may have processed this request. Check the resource's state before trying again; never resend it blindly.",
            $sent->getMessage(),
        );
        self::assertSame('Network error during GET /transaction/{id}: refused', $notSent->getMessage());
        self::assertSame('GuzzleHttp\Exception\ConnectException', $sent->causeClass());
        self::assertSame('POST', $sent->method());
        self::assertSame('/codi/qr', $sent->pathTemplate());
        self::assertTrue($sent->requestMayHaveBeenProcessed());
        self::assertFalse($notSent->requestMayHaveBeenProcessed());
        self::assertNull($sent->httpStatus());
    }

    public function testConfigurationExceptionsCarryTheirReason(): void
    {
        self::assertSame('Bad base URL.', ConfigurationException::because('Bad base URL.')->getMessage());
    }
}
