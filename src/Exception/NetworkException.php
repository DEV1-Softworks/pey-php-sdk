<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * The HTTP exchange with Pey failed before a complete response was received:
 * DNS, connection, TLS, timeout or a broken response stream.
 *
 * The underlying client exception is not chained as `previous` on purpose:
 * PSR-18 exceptions keep the request, and the request carries the
 * Authorization header. Its class and a sanitized message are kept instead.
 */
final class NetworkException extends PeyException
{
    private function __construct(
        string $message,
        private readonly string $method,
        private readonly string $pathTemplate,
        private readonly bool $requestMayHaveBeenProcessed,
        private readonly string $causeClass,
        private readonly bool $transient,
    ) {
        parent::__construct($message);
    }

    /**
     * @param string $causeMessage already sanitized: no secrets, no line breaks
     * @param bool   $transient    false when the HTTP client rejected the request as invalid, so trying again cannot help
     */
    public static function create(
        string $method,
        string $pathTemplate,
        bool $requestMayHaveBeenProcessed,
        string $causeClass,
        string $causeMessage,
        bool $transient = true,
    ): self {
        $message = \sprintf('Network error during %s %s: %s', $method, $pathTemplate, $causeMessage !== '' ? $causeMessage : $causeClass);

        if ($requestMayHaveBeenProcessed) {
            $message .= ' Pey may have processed this request. Check the resource\'s state before trying again; never resend it blindly.';
        }

        return new self($message, $method, $pathTemplate, $requestMayHaveBeenProcessed, $causeClass, $transient);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function pathTemplate(): string
    {
        return $this->pathTemplate;
    }

    /**
     * True when the request may have reached Pey, so its effect (a charge, a
     * payment link, a bank account) is unknown. Always true for a POST that
     * failed after it could have been sent.
     */
    public function requestMayHaveBeenProcessed(): bool
    {
        return $this->requestMayHaveBeenProcessed;
    }

    /**
     * True for connection, timeout and stream failures; false when the HTTP
     * client rejected the request itself as invalid. Only transient failures of
     * GET requests are retried.
     */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    /**
     * The class of the underlying PSR-18 or stream exception.
     */
    public function causeClass(): string
    {
        return $this->causeClass;
    }
}
