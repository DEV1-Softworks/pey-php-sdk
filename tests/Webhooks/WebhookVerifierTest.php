<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Webhooks;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use Dev1\Pey\Tests\Support\MutableClock;
use Dev1\Pey\Tests\Support\StandardWebhooksVectors as Vectors;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\EventFactory;
use Dev1\Pey\Webhooks\VerificationFailure;
use Dev1\Pey\Webhooks\VerifiedWebhook;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookVerifier::class)]
#[CoversClass(VerifiedWebhook::class)]
#[CoversClass(WebhookVerificationException::class)]
#[CoversClass(VerificationFailure::class)]
#[UsesClass(EventFactory::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Json::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Secret::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(SecretExposureException::class)]
#[UsesClass(UnexpectedResponseException::class)]
final class WebhookVerifierTest extends TestCase
{
    private const ID = 'msg_2zW8h3Y7mQ1kP4xB9cD6eF0gH5';

    private MutableClock $clock;

    private string $body;

    protected function setUp(): void
    {
        $this->clock = new MutableClock(1_790_724_818);
        $contents = file_get_contents(\dirname(__DIR__) . '/Fixtures/webhooks/codi.qr.payment_received.json');
        self::assertIsString($contents);
        $this->body = $contents;
    }

    private function verifier(mixed $secret = Vectors::SECRET, int $tolerance = WebhookVerifier::DEFAULT_TOLERANCE_SECONDS): WebhookVerifier
    {
        /** @var string|Secret|list<string|Secret> $secret */
        return new WebhookVerifier($secret, $tolerance, $this->clock);
    }

    /**
     * @return array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}
     */
    private function signed(?string $body = null, ?int $timestamp = null, string $secret = Vectors::SECRET, string $id = self::ID): array
    {
        return Vectors::headers($secret, $id, $timestamp ?? $this->clock->now()->getTimestamp(), $body ?? $this->body);
    }

    /**
     * @param \Closure(): mixed $verify
     */
    private static function failureOf(\Closure $verify): VerificationFailure
    {
        try {
            $verify();
        } catch (WebhookVerificationException $e) {
            return $e->failure();
        }

        self::fail('Expected a WebhookVerificationException.');
    }

    // --------------------------------------------------------- valid webhooks

    public function testTheStandardWebhooksTestVectorVerifies(): void
    {
        $this->clock = new MutableClock(Vectors::TIMESTAMP);
        $headers = ['webhook-id' => Vectors::MESSAGE_ID, 'webhook-timestamp' => (string) Vectors::TIMESTAMP, 'webhook-signature' => Vectors::SIGNATURE];

        // The vector's payload is not a Pey event: reaching the payload parser
        // (rather than failing verification) proves the signature matched.
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('WebhookEvent field "type" must be a string');

        $this->verifier()->verify(Vectors::PAYLOAD, $headers);
    }

    public function testAValidWebhookReturnsItsIdTimestampAndTypedEvent(): void
    {
        $webhook = $this->verifier()->verify($this->body, $this->signed());

        self::assertSame(self::ID, $webhook->id);
        self::assertSame($this->clock->now()->getTimestamp(), $webhook->timestamp->getTimestamp());
        self::assertInstanceOf(CodiQrPaymentReceived::class, $webhook->event);
        self::assertSame('01M3QR8H0RH0XBCKYYD242YXNV', $webhook->event->transactionId);
    }

    public function testHeaderNamesAreCaseInsensitiveAndMayBeLists(): void
    {
        $signed = $this->signed();
        $headers = [
            'Webhook-Id' => [$signed['webhook-id']],
            'WEBHOOK-TIMESTAMP' => $signed['webhook-timestamp'],
            'webhook-signature' => [' ' . $signed['webhook-signature'] . ' '],
            'Content-Type' => ['application/json'],
        ];

        self::assertSame(self::ID, $this->verifier()->verify($this->body, $headers)->id);
    }

    public function testAnyOfSeveralSignaturesMayMatch(): void
    {
        $signed = $this->signed();
        $signed['webhook-signature'] = 'v1,bm90LWEtcmVhbC1zaWduYXR1cmU= v1a,ignored ' . $signed['webhook-signature'];

        self::assertSame(self::ID, $this->verifier()->verify($this->body, $signed)->id);
    }

    public function testSeveralSecretsCanBeAcceptedDuringRotation(): void
    {
        $verifier = $this->verifier([Vectors::OTHER_SECRET, new Secret(Vectors::SECRET)]);

        self::assertSame(self::ID, $verifier->verify($this->body, $this->signed(secret: Vectors::SECRET))->id);
        self::assertSame(self::ID, $verifier->verify($this->body, $this->signed(secret: Vectors::OTHER_SECRET))->id);
    }

    public function testTheSecretPrefixIsOptional(): void
    {
        $verifier = $this->verifier(substr(Vectors::SECRET, \strlen('whsec_')));

        self::assertSame(self::ID, $verifier->verify($this->body, $this->signed())->id);
    }

    public function testAPsr7RequestIsVerifiedFromTheStartOfItsBody(): void
    {
        $request = new ServerRequest('POST', 'https://shop.example.com/webhooks/pey', $this->signed(), $this->body);
        $request->getBody()->getContents(); // A framework already read the stream.

        self::assertSame(self::ID, $this->verifier()->verifyRequest($request)->id);
    }

    // ------------------------------------------------------- signature failures

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideForgeries(): iterable
    {
        yield 'body changed by one character' => ['amount'];
        yield 'body re-encoded' => ['re-encoded'];
        yield 'trailing newline added' => ['newline'];
        yield 'another message id' => ['id'];
        yield 'another timestamp' => ['timestamp'];
        yield 'signature of another version' => ['version'];
        yield 'bare signature without version' => ['bare'];
        yield 'truncated signature' => ['truncated'];
        yield 'empty signature' => ['empty'];
    }

    #[DataProvider('provideForgeries')]
    public function testForgedWebhooksAreRejected(string $forgery): void
    {
        $headers = $this->signed();
        $body = $this->body;
        $signature = $headers['webhook-signature'];

        match ($forgery) {
            'amount' => $body = str_replace('"amount": 150', '"amount": 151', $body),
            're-encoded' => $body = json_encode(json_decode($body, false, 512, \JSON_THROW_ON_ERROR), \JSON_THROW_ON_ERROR),
            'newline' => $body .= "\n",
            'id' => $headers['webhook-id'] = 'msg_other',
            'timestamp' => $headers['webhook-timestamp'] = (string) ((int) $headers['webhook-timestamp'] - 1),
            'version' => $headers['webhook-signature'] = 'v2' . substr($signature, 2),
            'bare' => $headers['webhook-signature'] = substr($signature, 3),
            'truncated' => $headers['webhook-signature'] = substr($signature, 0, -2),
            'empty' => $headers['webhook-signature'] = '',
            default => self::fail('Unknown forgery ' . $forgery),
        };

        self::assertNotSame($this->body . json_encode($this->signed()), $body . json_encode($headers), 'The forgery must change something.');
        self::assertSame(VerificationFailure::InvalidSignature, self::failureOf(fn() => $this->verifier()->verify($body, $headers)));
    }

    public function testAWebhookSignedWithAnotherSecretIsRejected(): void
    {
        $headers = $this->signed(secret: Vectors::OTHER_SECRET);

        self::assertSame(VerificationFailure::InvalidSignature, self::failureOf(fn() => $this->verifier()->verify($this->body, $headers)));
    }

    public function testAForgedBodyIsNeverParsed(): void
    {
        // Not JSON at all: if it were parsed first, this would be an UnexpectedResponseException.
        $headers = $this->signed();

        self::assertSame(VerificationFailure::InvalidSignature, self::failureOf(fn() => $this->verifier()->verify('{"type": not json', $headers)));
    }

    public function testAnAuthenticBodyThatIsNotAnEventIsAPayloadError(): void
    {
        $this->expectException(UnexpectedResponseException::class);

        $this->verifier()->verify('[1,2]', $this->signed('[1,2]'));
    }

    // -------------------------------------------------------------- timestamps

    /**
     * @return iterable<string, array{int, ?VerificationFailure}>
     */
    public static function provideTimestampOffsets(): iterable
    {
        yield 'now' => [0, null];
        yield 'exactly the tolerance in the past' => [-300, null];
        yield 'one second beyond the tolerance in the past' => [-301, VerificationFailure::TimestampTooOld];
        yield 'a day old (replay)' => [-86_400, VerificationFailure::TimestampTooOld];
        yield 'exactly the tolerance in the future' => [300, null];
        yield 'one second beyond the tolerance in the future' => [301, VerificationFailure::TimestampTooNew];
    }

    #[DataProvider('provideTimestampOffsets')]
    public function testTheTimestampMustBeWithinTheTolerance(int $offset, ?VerificationFailure $expected): void
    {
        $headers = $this->signed(timestamp: $this->clock->now()->getTimestamp() + $offset);

        if ($expected === null) {
            self::assertSame(self::ID, $this->verifier()->verify($this->body, $headers)->id);
        } else {
            self::assertSame($expected, self::failureOf(fn() => $this->verifier()->verify($this->body, $headers)));
        }
    }

    public function testTheToleranceIsConfigurable(): void
    {
        $headers = $this->signed(timestamp: $this->clock->now()->getTimestamp() - 61);

        self::assertSame(self::ID, $this->verifier()->verify($this->body, $headers)->id);
        self::assertSame(VerificationFailure::TimestampTooOld, self::failureOf(fn() => $this->verifier(tolerance: 60)->verify($this->body, $headers)));
    }

    public function testTheTimestampIsCheckedBeforeTheSignature(): void
    {
        // A perfectly signed but old webhook is a replay.
        $headers = $this->signed(timestamp: $this->clock->now()->getTimestamp() - 3_600);

        self::assertSame(VerificationFailure::TimestampTooOld, self::failureOf(fn() => $this->verifier()->verify($this->body, $headers)));
    }

    // ----------------------------------------------------------------- headers

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRequiredHeaders(): iterable
    {
        yield 'id' => ['webhook-id'];
        yield 'timestamp' => ['webhook-timestamp'];
        yield 'signature' => ['webhook-signature'];
    }

    #[DataProvider('provideRequiredHeaders')]
    public function testEveryHeaderIsRequired(string $header): void
    {
        $headers = $this->signed();
        unset($headers[$header]);

        $e = null;
        try {
            $this->verifier()->verify($this->body, $headers);
        } catch (WebhookVerificationException $e) {
        }

        self::assertNotNull($e);
        self::assertSame(VerificationFailure::MissingHeader, $e->failure());
        self::assertSame(\sprintf('Webhook verification failed: the %s header is missing.', $header), $e->getMessage());
    }

    #[DataProvider('provideRequiredHeaders')]
    public function testARepeatedHeaderIsRejected(string $header): void
    {
        $headers = $this->signed();
        self::assertArrayHasKey($header, $headers);
        $repeated = [$header => [$headers[$header], $headers[$header]]] + $headers;

        self::assertSame(VerificationFailure::InvalidHeader, self::failureOf(fn() => $this->verifier()->verify($this->body, $repeated)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMalformedHeaders(): iterable
    {
        yield 'timestamp with letters' => [['webhook-timestamp' => '17907248l8']];
        yield 'decimal timestamp' => [['webhook-timestamp' => '1790724818.5']];
        yield 'negative timestamp' => [['webhook-timestamp' => '-1790724818']];
        yield 'zero timestamp' => [['webhook-timestamp' => '0']];
        yield 'timestamp with a leading zero' => [['webhook-timestamp' => '01790724818']];
        yield 'timestamp in milliseconds' => [['webhook-timestamp' => '1790724818000']];
        yield 'empty id' => [['webhook-id' => '']];
        yield 'id with a space' => [['webhook-id' => 'msg 1']];
        yield 'id with a line break' => [['webhook-id' => "msg\n1"]];
        yield 'overlong id' => [['webhook-id' => str_repeat('m', 256)]];
        yield 'non-string value' => [['webhook-signature' => [123]]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('provideMalformedHeaders')]
    public function testMalformedHeadersAreRejected(array $override): void
    {
        $headers = $override + $this->signed();

        self::assertSame(VerificationFailure::InvalidHeader, self::failureOf(fn() => $this->verifier()->verify($this->body, $headers)));
    }

    public function testMessagesNeverEchoHeaderValues(): void
    {
        $headers = ['webhook-id' => "evil\r\nX-Injected: 1"] + $this->signed();

        try {
            $this->verifier()->verify($this->body, $headers);
            self::fail('Expected a WebhookVerificationException.');
        } catch (WebhookVerificationException $e) {
            self::assertStringNotContainsString('Injected', $e->getMessage());
        }
    }

    // ----------------------------------------------------------- configuration

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidSecrets(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['whsec_'];
        yield 'not base64' => ['whsec_not base64!'];
        yield 'base64 with bad padding' => ['whsec_abc==='];
        yield 'empty list' => [[]];
        yield 'not a string' => [[123]];
    }

    #[DataProvider('provideInvalidSecrets')]
    public function testInvalidSecretsAreRejected(mixed $secret): void
    {
        $this->expectException(ConfigurationException::class);

        $this->verifier($secret);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidTolerances(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'over a day' => [86_401];
    }

    #[DataProvider('provideInvalidTolerances')]
    public function testInvalidTolerancesAreRejected(int $tolerance): void
    {
        $this->expectException(ConfigurationException::class);

        $this->verifier(tolerance: $tolerance);
    }

    public function testItUsesTheSystemClockByDefault(): void
    {
        $verifier = new WebhookVerifier(Vectors::SECRET);

        self::assertSame(VerificationFailure::TimestampTooOld, self::failureOf(fn() => $verifier->verify($this->body, $this->signed(timestamp: 1_000_000_000))));
    }

    // ----------------------------------------------------------------- secrets

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDumpMechanisms(): iterable
    {
        return Dumps::mechanisms();
    }

    #[DataProvider('provideDumpMechanisms')]
    public function testTheSecretNeverAppearsInDumpsOfTheVerifier(string $mechanism): void
    {
        $output = Dumps::via($mechanism, $this->verifier());

        self::assertStringNotContainsString('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $output);
    }

    public function testTheSecretParameterIsMarkedSensitive(): void
    {
        $parameter = new \ReflectionParameter([WebhookVerifier::class, '__construct'], 'secret');

        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testTheSecretNeverAppearsInConfigurationErrors(): void
    {
        $leaky = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw!';

        try {
            $this->verifier($leaky);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $output, $where);
            }
        }
    }

    public function testTheSecretNeverAppearsInVerificationErrors(): void
    {
        try {
            $this->verifier()->verify($this->body, $this->signed(secret: Vectors::OTHER_SECRET));
            self::fail('Expected a WebhookVerificationException.');
        } catch (WebhookVerificationException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $output, $where);
                self::assertStringNotContainsString('01M3QR8H0RH0XBCKYYD242YXNV', $output, $where . ' must not contain the body');
            }
        }
    }
}
