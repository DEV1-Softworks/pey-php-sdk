<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\HttpClientFactory;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\PeyClient;
use Dev1\Pey\Resources\Codi;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\RecordingLogger;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeyClient::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Transport::class)]
#[UsesClass(HttpClientFactory::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Codi::class)]
#[UsesClass(CreateQrRequest::class)]
#[UsesClass(QrCode::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Violations::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(SecretExposureException::class)]
final class PeyClientTest extends TestCase
{
    private const API_KEY = 'pey_test_not_a_real_value';

    public function testCreateWiresTheGivenHttpClient(): void
    {
        $http = (new MockHttpClient())->willRespond(201, '{"id":"AB12CD34EF","qrString":"x"}');
        $logger = new RecordingLogger();

        $pey = PeyClient::create(new Config(self::API_KEY), $http, $logger);
        $qr = $pey->codi()->createQr(new CreateQrRequest('1'));

        self::assertSame('AB12CD34EF', $qr->id);
        self::assertSame('Bearer ' . self::API_KEY, $http->lastRequest()->getHeaderLine('Authorization'));
        self::assertNotSame([], $logger->records);
    }

    public function testCreateBuildsAnHttpClientWhenNoneIsGiven(): void
    {
        self::assertInstanceOf(Codi::class, PeyClient::create(new Config(self::API_KEY))->codi());
    }

    public function testResourcesAreReused(): void
    {
        $pey = PeyClient::create(new Config(self::API_KEY), new MockHttpClient());

        self::assertSame($pey->codi(), $pey->codi());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDumpMechanisms(): iterable
    {
        return Dumps::mechanisms();
    }

    #[DataProvider('provideDumpMechanisms')]
    public function testTheApiKeyNeverAppearsInDumpsOfTheClient(string $mechanism): void
    {
        $pey = PeyClient::create(new Config(self::API_KEY), new MockHttpClient());

        self::assertStringNotContainsString(self::API_KEY, Dumps::via($mechanism, $pey));
    }
}
