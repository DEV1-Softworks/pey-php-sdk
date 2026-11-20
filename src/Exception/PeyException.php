<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Http\ApiError;

/**
 * Base class for every exception this SDK throws to consumers.
 *
 * When the exception comes from an HTTP response, httpStatus() and apiError()
 * describe it. Messages never contain the API key, request headers or request
 * bodies.
 */
abstract class PeyException extends \RuntimeException
{
    protected function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?ApiError $apiError = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    /**
     * The HTTP status Pey answered with, or null when no response was received
     * (local validation, network failure, configuration error).
     */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The error Pey reported in the response body, when there was one.
     */
    public function apiError(): ?ApiError
    {
        return $this->apiError;
    }

    /**
     * Pey's `error.type`, for example `INVALID_AMOUNT`.
     */
    public function errorType(): ?string
    {
        return $this->apiError?->type;
    }

    /**
     * Pey's `error.requestId`. Include it when contacting Pey support.
     */
    public function requestId(): ?string
    {
        return $this->apiError?->requestId;
    }
}
