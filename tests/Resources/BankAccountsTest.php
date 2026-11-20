<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Resources;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Response\BankAccount;
use Dev1\Pey\Dto\Response\BankAccountRegistration;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Resources\BankAccounts;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\RecordingLogger;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BankAccounts::class)]
#[UsesClass(BankAccountGuard::class)]
#[UsesClass(BankAccountNotAllowedException::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Transport::class)]
#[UsesClass(Json::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(Payload::class)]
#[UsesClass(RegisterBankAccountRequest::class)]
#[UsesClass(BankAccountRegistration::class)]
#[UsesClass(BankAccount::class)]
#[UsesClass(CodiStatus::class)]
#[UsesClass(Entity::class)]
#[UsesClass(Clabe::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Violations::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ValidationException::class)]
final class BankAccountsTest extends TestCase
{
    private const ALLOWED = '032180000118359719';

    private const NOT_ALLOWED = '002010077777777771';

    private const ID = '01JBAVWE4NS5TGXHWGMX3KH23Q';

    private MockHttpClient $http;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();
    }

    private function bankAccounts(string ...$allowedClabes): BankAccounts
    {
        $factory = new Psr17Factory();
        $config = new Config('pey_test_not_a_real_value', allowedClabes: array_values($allowedClabes));

        return new BankAccounts(new Transport($config, $this->http, $factory, $factory), BankAccountGuard::fromConfig($config), $this->logger);
    }

    private static function request(string $clabe): RegisterBankAccountRequest
    {
        return new RegisterBankAccountRequest('BBVA', 'Pagos con Pey S.A. de C.V.', $clabe, Entity::Business);
    }

    public function testAnAllowlistedClabeIsRegistered(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);
        $this->http->willRespond(200, '{"id":"01JBAVWE4NS5TGXHWGMX3KH23Q","codiStatus":"PENDING"}');

        $registration = $bankAccounts->register(self::request(self::ALLOWED));

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pey.mx/bank-account', (string) $request->getUri());
        self::assertSame('{"alias":"BBVA","name":"Pagos con Pey S.A. de C.V.","clabe":"032180000118359719","entity":"BUSINESS"}', (string) $request->getBody());
        self::assertSame(self::ID, $registration->id);
        self::assertSame(CodiStatus::Pending, $registration->codiStatus);
    }

    public function testWithoutAnAllowlistNothingIsEverSent(): void
    {
        $bankAccounts = $this->bankAccounts();

        try {
            $bankAccounts->register(self::request(self::ALLOWED));
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException) {
        }

        self::assertSame([], $this->http->requests, 'A blocked registration must never reach the HTTP client.');
    }

    public function testAClabeOutsideTheAllowlistIsNeverSent(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);

        try {
            $bankAccounts->register(self::request(self::NOT_ALLOWED));
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException $e) {
            self::assertSame('**************7771', $e->maskedClabe());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testBlockedRegistrationsKeepTheFullClabeOutOfTraces(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);

        try {
            $bankAccounts->register(self::request(self::NOT_ALLOWED));
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString(self::NOT_ALLOWED, $output, $where);
            }
        }
    }

    public function testBlockedRegistrationsAreLoggedWithAMaskedClabe(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);

        try {
            $bankAccounts->register(self::request(self::NOT_ALLOWED));
        } catch (BankAccountNotAllowedException) {
        }

        $warnings = $this->logger->recordsAt('warning');
        self::assertCount(1, $warnings);
        self::assertSame(['clabe' => '**************7771'], $warnings[0]['context']);
        self::assertStringNotContainsString(self::NOT_ALLOWED, print_r($this->logger->records, true));
    }

    public function testARegistrationIsNeverRetried(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);
        $this->http->willRespond(503, '')->willRespond(200, '{"id":"x","codiStatus":"PENDING"}');

        try {
            $bankAccounts->register(self::request(self::ALLOWED));
            self::fail('Expected a ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testPeyRejectingTheClabeIsAValidationError(): void
    {
        $bankAccounts = $this->bankAccounts(self::ALLOWED);
        $this->http->willRespond(400, '{"error":{"message":"clabe is not valid","type":"INVALID_CLABE","requestId":"r"}}');

        try {
            $bankAccounts->register(self::request(self::ALLOWED));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('INVALID_CLABE', $e->errorType());
        }
    }

    public function testGetRetrievesTheAccount(): void
    {
        $bankAccounts = $this->bankAccounts();
        $this->http->willRespond(200, '{"id":"01JBAVWE4NS5TGXHWGMX3KH23Q","alias":"BBVA","name":"Pagos con Pey S.A. de C.V.","clabe":"012180001234567899",'
            . '"entity":"BUSINESS","taxId":"XAXX010101000","codiStatus":"ACTIVE","dateCreated":1760000000}');

        $account = $bankAccounts->get(self::ID);

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.pey.mx/bank-account/01JBAVWE4NS5TGXHWGMX3KH23Q', (string) $request->getUri());
        self::assertSame(CodiStatus::Active, $account->codiStatus);
    }

    public function testGetWorksWithoutAnAllowlist(): void
    {
        $bankAccounts = $this->bankAccounts();
        $this->http->willRespond(404, '{"error":{"message":"Bank account not found","type":"BANK_ACCOUNT_NOT_FOUND","requestId":"r"}}');

        try {
            $bankAccounts->get(self::ID);
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            self::assertSame('BANK_ACCOUNT_NOT_FOUND', $e->errorType());
        }
    }

    public function testGetRejectsIdsThatAreNotUlidsWithoutSendingAnything(): void
    {
        $bankAccounts = $this->bankAccounts();

        try {
            $bankAccounts->get('../transaction/api-key');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['id'], array_keys($e->errors()));
        }

        self::assertSame([], $this->http->requests);
    }
}
