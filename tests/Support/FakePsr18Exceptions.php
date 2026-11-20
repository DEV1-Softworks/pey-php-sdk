<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Builds PSR-18 exceptions that, like real ones, hold the request (and so the
 * Authorization header).
 */
final class FakePsr18Exceptions
{
    public static function network(string $message, RequestInterface $request): NetworkExceptionInterface&\Throwable
    {
        return new class ($message, $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    public static function request(string $message, RequestInterface $request): RequestExceptionInterface&\Throwable
    {
        return new class ($message, $request) extends \RuntimeException implements RequestExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }

    public static function generic(string $message): ClientExceptionInterface&\Throwable
    {
        return new class ($message) extends \RuntimeException implements ClientExceptionInterface {};
    }
}
