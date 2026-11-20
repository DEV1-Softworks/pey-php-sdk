<?php

declare(strict_types=1);

namespace Dev1\Pey;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Resources\Codi;
use Dev1\Pey\Resources\CodiInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Client for the Pey API. This is an unofficial SDK, not affiliated with Pey
 * or Banco de México.
 *
 * Pey has no sandbox: every call moves real money.
 *
 * ```php
 * $pey = PeyClient::create(new Config(apiKey: $_ENV['PEY_API_KEY']));
 * $qr = $pey->codi()->createQr(new CreateQrRequest(amount: '150.00', concept: 'Order #12345'));
 * ```
 */
final class PeyClient implements PeyClientInterface
{
    private readonly Codi $codi;

    /**
     * @internal use PeyClient::create()
     */
    public function __construct(Transport $transport)
    {
        $this->codi = new Codi($transport);
    }

    /**
     * Builds a client. Anything not supplied is discovered: without an HTTP
     * client, one is built with the configured timeouts (see Config).
     *
     * @param ClientInterface|null $httpClient used as-is. Its timeouts and TLS settings are your responsibility,
     *                                         and it must not follow redirects or send credentials of its own.
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
        return new self(Transport::create($config, $httpClient, $logger, $requestFactory, $streamFactory));
    }

    public function codi(): CodiInterface
    {
        return $this->codi;
    }
}
