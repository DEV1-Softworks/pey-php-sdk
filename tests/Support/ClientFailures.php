<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Composer\InstalledVersions;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Exceptions a PSR-18 client throws when an exchange fails. None of them
 * proves that the request never reached the server: Guzzle, for example, uses
 * RequestExceptionInterface for failures that happen after the request was
 * sent, or after the response started.
 *
 * The PSR-level fakes and the exceptions both Guzzle majors share are always
 * included, so the list is never empty. Guzzle 8 only exceptions are added
 * when Guzzle 8 is installed.
 */
final class ClientFailures
{
    /**
     * The cases that only exist when Guzzle 8 is installed.
     */
    public const GUZZLE_8_ONLY = [
        'Guzzle 8 ResponseTimeoutException',
        'Guzzle 8 ResponseTransferException',
        'Guzzle 8 NetworkTimeoutException',
    ];

    /**
     * @return non-empty-array<string, \Throwable>
     */
    public static function forRequest(RequestInterface $request): array
    {
        $failures = [
            'PSR-18 RequestExceptionInterface' => FakePsr18Exceptions::request('Invalid request', $request),
            'PSR-18 NetworkExceptionInterface' => FakePsr18Exceptions::network('Operation timed out', $request),
            // Guzzle 7: cURL errors outside its connection list, such as 56.
            // Guzzle 8: cURL errors it does not classify.
            'Guzzle RequestException' => new RequestException('cURL error 56: Recv failure: Connection reset by peer', $request),
            'Guzzle ConnectException' => new ConnectException('cURL error 7: Failed to connect to api.pey.mx', $request),
        ];

        $guzzle8 = [
            // cURL error 28 after the response headers arrived.
            'Guzzle 8 ResponseTimeoutException' => ['GuzzleHttp\Exception\ResponseTimeoutException', ['cURL error 28: Operation timed out', $request, new Response(201)]],
            // Any transfer failure once the response has started.
            'Guzzle 8 ResponseTransferException' => ['GuzzleHttp\Exception\ResponseTransferException', ['cURL error 56: Recv failure: Connection reset by peer', $request, new Response(201)]],
            'Guzzle 8 NetworkTimeoutException' => ['GuzzleHttp\Exception\NetworkTimeoutException', ['cURL error 28: Operation timed out', $request]],
        ];

        foreach ($guzzle8 as $name => [$class, $arguments]) {
            $failure = self::instantiateIfInstalled($class, $arguments);
            if ($failure !== null) {
                $failures[$name] = $failure;
            }
        }

        return $failures;
    }

    /**
     * True when Composer installed Guzzle 8, read at runtime so that it does
     * not depend on the exception class names above.
     */
    public static function guzzle8IsInstalled(): bool
    {
        return str_starts_with(InstalledVersions::getVersion('guzzlehttp/guzzle') ?? '', '8.');
    }

    /**
     * Builds a Guzzle 8 only exception, or returns null on Guzzle 7. The class
     * name is a plain string so the code type-checks on both majors.
     *
     * @param list<mixed> $arguments
     */
    private static function instantiateIfInstalled(string $class, array $arguments): ?\Throwable
    {
        if (!class_exists($class)) {
            return null;
        }

        $failure = new $class(...$arguments);
        if (!$failure instanceof \Throwable) {
            throw new \LogicException(\sprintf('%s is not an exception.', $class));
        }

        return $failure;
    }
}
