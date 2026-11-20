<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that never touches the network: it records requests and
 * answers with queued responses or throwables, in order.
 */
final class MockHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @var list<ResponseInterface|\Throwable>
     */
    private array $queue = [];

    public function willReturn(ResponseInterface|\Throwable ...$outcomes): self
    {
        foreach ($outcomes as $outcome) {
            $this->queue[] = $outcome;
        }

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function willRespond(int $status, string $body = '', array $headers = []): self
    {
        return $this->willReturn(new Response($status, $headers, $body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('MockHttpClient has no queued response.');
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('MockHttpClient has not received any request.');
        }

        return $last;
    }
}
