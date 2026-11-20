<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Http\ApiError;

/**
 * Pey answered with an HTTP error status (other than 400, which is reported as
 * ValidationException).
 */
abstract class ApiException extends PeyException
{
    final protected function __construct(
        string $method,
        string $pathTemplate,
        int $httpStatus,
        private readonly ApiError $error,
        private readonly ?string $retryAfter,
    ) {
        parent::__construct(self::describe($method, $pathTemplate, $httpStatus, $error), $httpStatus, $error);
    }

    /**
     * @param string $pathTemplate the path with placeholders, for example `/transaction/{id}`
     * @param string|null $retryAfter the raw `Retry-After` header, if Pey sent one
     */
    public static function fromResponse(
        string $method,
        string $pathTemplate,
        int $httpStatus,
        ApiError $error,
        ?string $retryAfter = null,
    ): static {
        return new static($method, $pathTemplate, $httpStatus, $error, $retryAfter);
    }

    /**
     * @internal builds the exception message shared by all HTTP error exceptions
     */
    public static function describe(string $method, string $pathTemplate, int $httpStatus, ApiError $error): string
    {
        $message = \sprintf('Pey API returned HTTP %d (%s) for %s %s', $httpStatus, $error->type, $method, $pathTemplate);

        if ($error->message !== '') {
            $message .= ': ' . $error->message;
        }

        if ($error->requestId !== null) {
            $message .= \sprintf(' [request id: %s]', $error->requestId);
        }

        return $message;
    }

    public function apiError(): ApiError
    {
        return $this->error;
    }

    public function errorType(): string
    {
        return $this->error->type;
    }

    /**
     * The raw `Retry-After` header Pey sent with the response, if any.
     */
    public function retryAfter(): ?string
    {
        return $this->retryAfter;
    }
}
